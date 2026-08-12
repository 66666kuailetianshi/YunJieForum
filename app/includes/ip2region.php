<?php
/**
 * 云界论坛 - IP 归属地查询库（ip2region 官方 xdb 二进制直读）
 *
 * 数据形态：直接使用 ip2region 官方编译产物 ip2region_v4.xdb / ip2region_v6.xdb
 * （app/data 目录），运行期不解析、不入库，按官方 xdb 3.0 二进制格式直读查询，
 * 全程离线、无需联网、不依赖 SQLite 驱动。
 *
 * xdb 3.0 文件布局（官方 PHP Searcher 逻辑逆向确认）：
 *   头部 256 字节：version u16(3) / indexPolicy u16 / createdAt u32 /
 *                  startIndexPtr u32 / endIndexPtr u32 / ipVersion u16(4|6) / runtimePtrBytes u16
 *   向量索引 512KiB：256×256×8B，按 IP 前两字节定位 [sPtr, ePtr] 段索引区间
 *   数据区：无分隔符共享文本池（UTF-8）
 *   段索引区：v4 每行 14B（start LE u32 + end LE u32 + dataLen u16 + dataPtr u32），
 *             v6 每行 38B（start 16B + end 16B + dataLen u16 + dataPtr u32，16 字节按大端序存储）
 *
 * 查询算法：向量索引粗定位 → 段索引区二分（v4 段 IP 为小端存储、比较时倒序）→
 * 读取 dataPtr 处 dataLen 字节文本。文本为「国家|省份|城市|ISP|国别码」五列，
 * 境外国家为英文（如 United States），中国数据为中文（如 中国|江苏省|南京市）。
 *
 * 中英双语显示：内置 247 个国家/地区名英文→中文映射表，境外归属地显示为
 * 「中文国家（英文原名）· 省份 · 城市」，中国归属地保持「省·市」中文。
 *
 * 内存占用：仅将 512KiB 向量索引读入内存，数据区按需 fseek/fread，
 * 单次查询约 20 次文件读取（二分 ~19 步 + 数据 1 次），适合共享主机。
 *
 * 源数据来自开源项目 ip2region（Apache-2.0 OR MIT 双许可），
 * 许可与署名信息见项目根目录 LICENSE.md。
 */

if (!defined('IP_REGION_XDB_V4')) {
    define('IP_REGION_XDB_V4', APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ip2region_v4.xdb');
}
if (!defined('IP_REGION_XDB_V6')) {
    define('IP_REGION_XDB_V6', APP_ROOT . 'app' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ip2region_v6.xdb');
}

// xdb 常量（与官方 Searcher 一致）
define('IP_REGION_XDB_HEADER_LEN', 256);
define('IP_REGION_XDB_VEC_ROWS', 256);
define('IP_REGION_XDB_VEC_COLS', 256);
define('IP_REGION_XDB_VEC_SIZE', 8);

/**
 * IP 归属地查询（快捷函数）
 *
 * @param string $ip IPv4 / IPv6 地址
 * @return string|null 原始归属地字符串（国家|省份|城市|ISP|国别码）/'LAN'（内网保留地址）/null（未命中）
 */
function ip_region_query(string $ip): ?string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return 'LAN';
    }

    // 进程内结果缓存（仅缓存成功命中）
    static $cache = [];
    static $cacheCount = 0;
    if (isset($cache[$ip])) {
        return $cache[$ip];
    }

    $region = null;
    try {
        $region = ip_region_xdb_search((strpos($ip, ':') !== false) ? 'v6' : 'v4', $ip);
    } catch (Throwable $e) {
        // 查询异常（如文件被替换瞬间）静默返回 null，不影响业务
        $region = null;
    }

    if ($region !== null && $region !== '') {
        if ($cacheCount >= 512) {
            array_shift($cache);
            $cacheCount--;
        }
        $cache[$ip] = $region;
        $cacheCount++;
        return $region;
    }
    return null;
}

/**
 * 打开并缓存 xdb 检索器（按 mtime+size 变化自动重建）
 * 仅将 512KiB 向量索引读入内存，数据区按需读文件。
 *
 * @return array|null {handle, vIndex, segSize, ipLen, size, header}
 */
function ip_region_xdb_open(string $ver): ?array
{
    static $cache = [];
    $path = ($ver === 'v6') ? IP_REGION_XDB_V6 : IP_REGION_XDB_V4;
    if (!is_file($path)) {
        return null;
    }
    $sig = @filemtime($path) . ':' . @filesize($path);
    if (isset($cache[$ver]) && $cache[$ver]['sig'] === $sig) {
        return $cache[$ver]['data'];
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    $headerBuff = fread($handle, IP_REGION_XDB_HEADER_LEN);
    if (strlen($headerBuff) !== IP_REGION_XDB_HEADER_LEN) {
        fclose($handle);
        return null;
    }
    $hdrVersion = ip_region_le_u16($headerBuff, 0);
    $ipVersion  = ip_region_le_u16($headerBuff, 16);
    $expected   = ($ver === 'v6') ? 6 : 4;
    if ($hdrVersion !== 3 || $ipVersion !== $expected) {
        fclose($handle);
        return null;
    }
    $vIndex = fread($handle, IP_REGION_XDB_VEC_ROWS * IP_REGION_XDB_VEC_COLS * IP_REGION_XDB_VEC_SIZE);
    if (strlen($vIndex) !== IP_REGION_XDB_VEC_ROWS * IP_REGION_XDB_VEC_COLS * IP_REGION_XDB_VEC_SIZE) {
        fclose($handle);
        return null;
    }

    $data = [
        'handle'  => $handle,
        'vIndex'  => $vIndex,
        'segSize' => ($ver === 'v6') ? 38 : 14, // v6: 16+16+2+4；v4: 4+4+2+4
        'ipLen'   => ($ver === 'v6') ? 16 : 4,
        'size'    => (int)@filesize($path),
        'header'  => [
            'version'     => $hdrVersion,
            'createdAt'   => ip_region_le_u32($headerBuff, 4),
            'startPtr'    => ip_region_le_u32($headerBuff, 8),
            'endPtr'      => ip_region_le_u32($headerBuff, 12),
            'ipVersion'   => $ipVersion,
        ],
    ];
    $cache[$ver] = ['sig' => $sig, 'data' => $data];
    return $data;
}

/**
 * 在指定 xdb 中检索 IP 归属地（官方 Searcher 二分逻辑移植）
 *
 * @return string|null 命中返回归属地文本（五列），未命中返回 null
 */
function ip_region_xdb_search(string $ver, string $ip): ?string
{
    $s = ip_region_xdb_open($ver);
    if ($s === null) {
        return null;
    }

    $ipBytes = @inet_pton($ip);
    if ($ipBytes === false || strlen($ipBytes) !== $s['ipLen']) {
        return null;
    }

    // 1) 向量索引粗定位：IP 前两字节 → 段索引区间 [sPtr, ePtr]
    $il0 = ord($ipBytes[0]) & 0xFF;
    $il1 = ord($ipBytes[1]) & 0xFF;
    $idx = $il0 * IP_REGION_XDB_VEC_COLS * IP_REGION_XDB_VEC_SIZE + $il1 * IP_REGION_XDB_VEC_SIZE;
    $sPtr = ip_region_le_u32($s['vIndex'], $idx);
    $ePtr = ip_region_le_u32($s['vIndex'], $idx + 4);
    if ($sPtr === 0 || $ePtr === 0) {
        return null;
    }

    // 2) 段索引区二分
    $bytes   = strlen($ipBytes);
    $dBytes  = $bytes * 2;       // 段行内 dataLen 偏移
    $idxSize = $s['segSize'];
    $dataLen = 0;
    $dataPtr = 0;
    $l = 0;
    $h = (int)(($ePtr - $sPtr) / $idxSize);
    $handle = $s['handle'];

    while ($l <= $h) {
        $m = ($l + $h) >> 1;
        $p = $sPtr + $m * $idxSize;
        if (fseek($handle, $p) == -1) {
            return null;
        }
        $buff = fread($handle, $idxSize);
        if (strlen($buff) !== $idxSize) {
            return null;
        }
        if ($s['ipLen'] === 16) {
            // IPv6：16 字节按大端序存储，直接二进制比较
            if (strcmp($ipBytes, substr($buff, 0, $bytes)) < 0) {
                $h = $m - 1;
                continue;
            }
            if (strcmp($ipBytes, substr($buff, $bytes, $bytes)) > 0) {
                $l = $m + 1;
                continue;
            }
        } else {
            // IPv4：段内 IP 为小端存储，比较时倒序逐字节
            if (ip_region_cmp_v4($ipBytes, $buff, 0) < 0) {
                $h = $m - 1;
                continue;
            }
            if (ip_region_cmp_v4($ipBytes, $buff, $bytes) > 0) {
                $l = $m + 1;
                continue;
            }
        }
        $dataLen = ip_region_le_u16($buff, $dBytes);
        $dataPtr = ip_region_le_u32($buff, $dBytes + 2);
        break;
    }

    if ($dataLen === 0) {
        return null;
    }
    if (fseek($handle, $dataPtr) == -1) {
        return null;
    }
    $region = fread($handle, $dataLen);
    return (strlen($region) === $dataLen) ? $region : null;
}

/**
 * IPv4 段索引比较：ipBytes 为大端序（inet_pton），段内 4 字节为小端存储，读时倒序
 */
function ip_region_cmp_v4(string $ipBytes, string $buff, int $offset): int
{
    $j = $offset + 3;
    for ($i = 0; $i < 4; $i++, $j--) {
        $a = ord($ipBytes[$i]);
        $b = ord($buff[$j]);
        if ($a > $b) {
            return 1;
        }
        if ($a < $b) {
            return -1;
        }
    }
    return 0;
}

/**
 * 读取 xdb 小端 u16
 */
function ip_region_le_u16(string $b, int $idx): int
{
    return (ord($b[$idx]) | (ord($b[$idx + 1]) << 8));
}

/**
 * 读取 xdb 小端 u32（32 位平台溢出补偿）
 */
function ip_region_le_u32(string $b, int $idx): int
{
    $val = (ord($b[$idx])) | (ord($b[$idx + 1]) << 8)
        | (ord($b[$idx + 2]) << 16) | (ord($b[$idx + 3]) << 24);
    if ($val < 0 && PHP_INT_SIZE == 4) {
        $val = (int)sprintf("%u", $val);
    }
    return $val;
}

/**
 * 将归属地字符串解析为结构化字段（'0' 视为空值）
 *
 * 官方 xdb 文本为 5 列格式（国家|省份|城市|ISP|国别码）：
 *   例：中国|江苏省|南京市|0|CN、United States|California|0|Google LLC|US
 *
 * @return array{country:string, region:string, province:string, city:string, isp:string}
 */
function ip_region_parts(?string $raw): array
{
    $empty = ['country' => '', 'region' => '', 'province' => '', 'city' => '', 'isp' => ''];
    if ($raw === null || $raw === '' || $raw === 'LAN') {
        return $empty;
    }
    $parts = explode('|', $raw);
    $clean = function (?string $v): string {
        $v = (string)$v;
        return ($v === '' || $v === '0') ? '' : $v;
    };
    return [
        'country'  => $clean($parts[0] ?? ''),
        'region'   => $clean($parts[4] ?? ''), // 国别码（ISO 3166 alpha-2，如 US）
        'province' => $clean($parts[1] ?? ''),
        'city'     => $clean($parts[2] ?? ''),
        'isp'      => $clean($parts[3] ?? ''),
    ];
}

/**
 * 国家/地区名英译中映射表（与官方 xdb 数据一致，含个别已为中文本条目）
 */
function ip_region_countries(): array
{
    static $map = null;
    if ($map === null) {
        $map = [
            'Afghanistan' => '阿富汗', 'Albania' => '阿尔巴尼亚', 'Algeria' => '阿尔及利亚',
            'American Samoa' => '美属萨摩亚', 'Andorra' => '安道尔', 'Angola' => '安哥拉',
            'Anguilla' => '安圭拉', 'Antarctica' => '南极洲', 'Antigua and Barbuda' => '安提瓜和巴布达',
            'Argentina' => '阿根廷', 'Armenia' => '亚美尼亚', 'Aruba' => '阿鲁巴',
            'Australia' => '澳大利亚', 'Austria' => '奥地利', 'Azerbaijan' => '阿塞拜疆',
            'Bahamas' => '巴哈马', 'Bahrain' => '巴林', 'Bangladesh' => '孟加拉国',
            'Barbados' => '巴巴多斯', 'Belarus' => '白俄罗斯', 'Belgium' => '比利时',
            'Belize' => '伯利兹', 'Benin' => '贝宁', 'Bermuda' => '百慕大',
            'Bhutan' => '不丹', 'Bolivia' => '玻利维亚', 'Bosnia and Herzegovina' => '波斯尼亚和黑塞哥维那',
            'Botswana' => '博茨瓦纳', 'Brazil' => '巴西', 'British Indian Ocean Territory' => '英属印度洋领地',
            'British Virgin Islands' => '英属维尔京群岛', 'Brunei Darussalam' => '文莱', 'Bulgaria' => '保加利亚',
            'Burkina Faso' => '布基纳法索', 'Burundi' => '布隆迪', 'Cabo Verde' => '佛得角',
            'Cambodia' => '柬埔寨', 'Cameroon' => '喀麦隆', 'Canada' => '加拿大',
            'Caribbean Netherlands' => '荷属加勒比', 'Cayman Islands' => '开曼群岛', 'Central African Republic' => '中非共和国',
            'Chad' => '乍得', 'Chile' => '智利', 'Christmas Island' => '圣诞岛',
            'Cocos Islands' => '科科斯群岛', 'Colombia' => '哥伦比亚', 'Comoros' => '科摩罗',
            'Congo' => '刚果', 'Cook Islands' => '库克群岛', 'Costa Rica' => '哥斯达黎加',
            'Croatia' => '克罗地亚', 'Cuba' => '古巴', 'Curaçao' => '库拉索',
            'Cyprus' => '塞浦路斯', 'Czechia' => '捷克', "Côte d'Ivoire" => '科特迪瓦',
            'DR Congo' => '刚果（金）', 'Denmark' => '丹麦', 'Djibouti' => '吉布提',
            'Dominica' => '多米尼克', 'Dominican Republic' => '多米尼加', 'Ecuador' => '厄瓜多尔',
            'Egypt' => '埃及', 'El Salvador' => '萨尔瓦多', 'Equatorial Guinea' => '赤道几内亚',
            'Eritrea' => '厄立特里亚', 'Estonia' => '爱沙尼亚', 'Eswatini' => '斯威士兰',
            'Ethiopia' => '埃塞俄比亚', 'Falkland Islands' => '福克兰群岛', 'Faroe Islands' => '法罗群岛',
            'Fiji' => '斐济', 'Finland' => '芬兰', 'France' => '法国',
            'French Guiana' => '法属圭亚那', 'French Polynesia' => '法属波利尼西亚', 'French Southern Territories' => '法属南部领地',
            'Gabon' => '加蓬', 'Gambia' => '冈比亚', 'Georgia' => '格鲁吉亚',
            'Germany' => '德国', 'Ghana' => '加纳', 'Gibraltar' => '直布罗陀',
            'Greece' => '希腊', 'Greenland' => '格陵兰', 'Grenada' => '格林纳达',
            'Guadeloupe' => '瓜德罗普', 'Guam' => '关岛', 'Guatemala' => '危地马拉',
            'Guernsey' => '根西岛', 'Guinea' => '几内亚', 'Guinea-Bissau' => '几内亚比绍',
            'Guyana' => '圭亚那', 'Haiti' => '海地', 'Holy See' => '梵蒂冈',
            'Honduras' => '洪都拉斯', 'Hungary' => '匈牙利', 'Iceland' => '冰岛',
            'India' => '印度', 'Indonesia' => '印度尼西亚', 'Iran' => '伊朗',
            'Iraq' => '伊拉克', 'Ireland' => '爱尔兰', 'Isle of Man' => '马恩岛',
            'Israel' => '以色列', 'Italy' => '意大利', 'Jamaica' => '牙买加',
            'Japan' => '日本', 'Jersey' => '泽西岛', 'Jordan' => '约旦',
            'Kazakhstan' => '哈萨克斯坦', 'Kenya' => '肯尼亚', 'Kiribati' => '基里巴斯',
            'Kosovo' => '科索沃', 'Kuwait' => '科威特', 'Kyrgyzstan' => '吉尔吉斯斯坦',
            'Laos' => '老挝', 'Latvia' => '拉脱维亚', 'Lebanon' => '黎巴嫩',
            'Lesotho' => '莱索托', 'Liberia' => '利比里亚', 'Libya' => '利比亚',
            'Liechtenstein' => '列支敦士登', 'Lithuania' => '立陶宛', 'Luxembourg' => '卢森堡',
            'Madagascar' => '马达加斯加', 'Malawi' => '马拉维', 'Malaysia' => '马来西亚',
            'Maldives' => '马尔代夫', 'Mali' => '马里', 'Malta' => '马耳他',
            'Marshall Islands' => '马绍尔群岛', 'Martinique' => '马提尼克', 'Mauritania' => '毛里塔尼亚',
            'Mauritius' => '毛里求斯', 'Mayotte' => '马约特', 'Mexico' => '墨西哥',
            'Micronesia' => '密克罗尼西亚', 'Moldova' => '摩尔多瓦', 'Monaco' => '摩纳哥',
            'Mongolia' => '蒙古', 'Montenegro' => '黑山', 'Montserrat' => '蒙特塞拉特',
            'Morocco' => '摩洛哥', 'Mozambique' => '莫桑比克', 'Myanmar' => '缅甸',
            'Namibia' => '纳米比亚', 'Nauru' => '瑙鲁', 'Nepal' => '尼泊尔',
            'Netherlands' => '荷兰', 'New Caledonia' => '新喀里多尼亚', 'New Zealand' => '新西兰',
            'Nicaragua' => '尼加拉瓜', 'Niger' => '尼日尔', 'Nigeria' => '尼日利亚',
            'Niue' => '纽埃', 'Norfolk Island' => '诺福克岛', 'North Korea' => '朝鲜',
            'North Macedonia' => '北马其顿', 'Northern Mariana Islands' => '北马里亚纳群岛', 'Norway' => '挪威',
            'Oman' => '阿曼', 'Pakistan' => '巴基斯坦', 'Palau' => '帕劳',
            'Palestine' => '巴勒斯坦', 'Panama' => '巴拿马', 'Papua New Guinea' => '巴布亚新几内亚',
            'Paraguay' => '巴拉圭', 'Peru' => '秘鲁', 'Philippines' => '菲律宾',
            'Pitcairn' => '皮特凯恩群岛', 'Poland' => '波兰', 'Portugal' => '葡萄牙',
            'Puerto Rico' => '波多黎各', 'Qatar' => '卡塔尔', 'Reserved' => '保留地址',
            'Romania' => '罗马尼亚', 'Russia' => '俄罗斯', 'Rwanda' => '卢旺达',
            'Réunion' => '留尼汪', 'Saint Barthélemy' => '圣巴泰勒米', 'Saint Helena' => '圣赫勒拿',
            'Saint Kitts and Nevis' => '圣基茨和尼维斯', 'Saint Lucia' => '圣卢西亚', 'Saint Martin' => '圣马丁',
            'Saint Pierre and Miquelon' => '圣皮埃尔和密克隆', 'Saint Vincent and the Grenadines' => '圣文森特和格林纳丁斯',
            'Samoa' => '萨摩亚', 'San Marino' => '圣马力诺', 'Sao Tome and Principe' => '圣多美和普林西比',
            'Saudi Arabia' => '沙特阿拉伯', 'Senegal' => '塞内加尔', 'Serbia' => '塞尔维亚',
            'Seychelles' => '塞舌尔', 'Sierra Leone' => '塞拉利昂', 'Singapore' => '新加坡',
            'Sint Maarten' => '荷属圣马丁', 'Slovakia' => '斯洛伐克', 'Slovenia' => '斯洛文尼亚',
            'Solomon Islands' => '所罗门群岛', 'Somalia' => '索马里', 'South Africa' => '南非',
            'South Georgia and the South Sandwich Islands' => '南乔治亚和南桑威奇群岛', 'South Korea' => '韩国',
            'South Sudan' => '南苏丹', 'Spain' => '西班牙', 'Sri Lanka' => '斯里兰卡',
            'Sudan' => '苏丹', 'Suriname' => '苏里南', 'Svalbard and Jan Mayen' => '斯瓦尔巴和扬马延',
            'Sweden' => '瑞典', 'Switzerland' => '瑞士', 'Syria' => '叙利亚',
            'Tajikistan' => '塔吉克斯坦', 'Tanzania' => '坦桑尼亚', 'Thailand' => '泰国',
            'Timor-Leste' => '东帝汶', 'Togo' => '多哥', 'Tokelau' => '托克劳',
            'Tonga' => '汤加', 'Trinidad and Tobago' => '特立尼达和多巴哥', 'Tunisia' => '突尼斯',
            'Turkmenistan' => '土库曼斯坦', 'Turks and Caicos Islands' => '特克斯和凯科斯群岛', 'Tuvalu' => '图瓦卢',
            'Türkiye' => '土耳其', 'US Minor Outlying Islands' => '美国本土外小岛屿', 'Uganda' => '乌干达',
            'Ukraine' => '乌克兰', 'United Arab Emirates' => '阿联酋', 'United Kingdom' => '英国',
            'United States' => '美国', 'United States Virgin Islands' => '美属维尔京群岛', 'Uruguay' => '乌拉圭',
            'Uzbekistan' => '乌兹别克斯坦', 'Vanuatu' => '瓦努阿图', 'Venezuela' => '委内瑞拉',
            'Viet Nam' => '越南', 'Wallis and Futuna' => '瓦利斯和富图纳', 'Western Sahara' => '西撒哈拉',
            'Yemen' => '也门', 'Zambia' => '赞比亚', 'Zimbabwe' => '津巴布韦',
            'Åland' => '奥兰群岛',
            // xdb 数据中已存在的个别中文条目（保持原样）
            '中国' => '中国', '美国' => '美国',
        ];
    }
    return $map;
}

/**
 * 国家/地区名英译中：命中返回中文，未命中返回 ''（调用方回退英文原名）
 */
function ip_region_country_cn(string $name): string
{
    if ($name === '') {
        return '';
    }
    $map = ip_region_countries();
    return $map[$name] ?? '';
}

/**
 * 归属地友好显示（用于访客列表等场景）
 * 中国："中国|江苏省|南京市" → "江苏·南京"
 * 境外："United States|California|0|Google LLC|US" → "美国（United States）·加利福尼亚"
 */
function ip_region_display(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    if ($raw === 'LAN') {
        return t('ipdb_lan', '内网IP');
    }
    $p = ip_region_parts($raw);
    if ($p['country'] === '') {
        return '';
    }

    // 中国：省 + 市（去除"省/市/自治区"等后缀，直辖区去重）
    if ($p['country'] === '中国') {
        $prov = preg_replace('/(省|市|自治区|自治州|特别行政区|地区|盟)$/', '', $p['province']);
        $city = preg_replace('/(市|自治州|地区|盟|县|区)$/', '', $p['city']);
        if ($prov !== '' && $prov === $city) {
            return $prov;
        }
        return implode('·', array_values(array_filter([$prov, $city])));
    }

    // 境外：中文国家（附英文原名）+ 省份/城市（原语言）
    $cn = ip_region_country_cn($p['country']);
    if ($cn !== '' && $cn !== $p['country']) {
        $head = $cn . '（' . $p['country'] . '）';
    } else {
        $head = ($cn !== '') ? $cn : $p['country'];
    }
    $rest = array_values(array_filter([$p['province'], $p['city']]));
    if ($rest === []) {
        return $head;
    }
    return $head . '·' . implode('·', $rest);
}

/**
 * 地域聚合键（用于地域分布统计）：国内取省级，国外取中文国家名
 * 示例："中国|江苏省|南京市" → "江苏省"；"United States|California|0" → "美国"
 */
function ip_region_province(?string $raw): string
{
    if ($raw === null || $raw === '' || $raw === 'LAN') {
        return '';
    }
    $p = ip_region_parts($raw);
    if ($p['country'] === '') {
        return '';
    }
    if ($p['country'] !== '中国') {
        $cn = ip_region_country_cn($p['country']);
        return $cn !== '' ? $cn : $p['country'];
    }
    return $p['province'] !== '' ? $p['province'] : $p['city'];
}

/**
 * 读取任意 xdb 文件头部信息（校验 version=3）
 *
 * @return array|null 非法/损坏文件返回 null
 */
function ip_region_xdb_read_header(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return null;
    }
    $b = fread($fp, IP_REGION_XDB_HEADER_LEN);
    fclose($fp);
    if (strlen($b) !== IP_REGION_XDB_HEADER_LEN) {
        return null;
    }
    $version = ip_region_le_u16($b, 0);
    if ($version !== 3) {
        return null;
    }
    return [
        'version'     => $version,
        'indexPolicy' => ip_region_le_u16($b, 2),
        'createdAt'   => ip_region_le_u32($b, 4),
        'startPtr'    => ip_region_le_u32($b, 8),
        'endPtr'      => ip_region_le_u32($b, 12),
        'ipVersion'   => ip_region_le_u16($b, 16),
        'runtimePtr'  => ip_region_le_u16($b, 18),
    ];
}

/**
 * 指定版本 xdb 的元信息（用于后台状态展示）
 *
 * @return array|null {path, size, createdAt, segments, ipVersion}
 */
function ip_region_xdb_info(string $ver): ?array
{
    $path = ($ver === 'v6') ? IP_REGION_XDB_V6 : IP_REGION_XDB_V4;
    $hdr = ip_region_xdb_read_header($path);
    if ($hdr === null) {
        return null;
    }
    $expected = ($ver === 'v6') ? 6 : 4;
    if ($hdr['ipVersion'] !== $expected) {
        return null;
    }
    $segSize = ($ver === 'v6') ? 38 : 14;
    return [
        'path'      => $path,
        'size'      => (int)@filesize($path),
        'createdAt' => $hdr['createdAt'],
        'segments'  => (int)(($hdr['endPtr'] - $hdr['startPtr']) / $segSize),
        'ipVersion' => $hdr['ipVersion'],
    ];
}

/**
 * 安装 xdb 文件（后台上传用）：校验头部类型 → 临时文件 → 原子替换
 *
 * @param string $srcPath 已上传的临时 xdb 文件
 * @param string $ver     'v4' / 'v6'
 * @return array{success:bool, type?:string, imported?:int, size?:int, error?:string}
 */
function ip_region_xdb_install(string $srcPath, string $ver): array
{
    if (!in_array($ver, ['v4', 'v6'], true)) {
        return ['success' => false, 'error' => 'bad_version'];
    }
    if (!is_file($srcPath)) {
        return ['success' => false, 'error' => 'not_found'];
    }
    $hdr = ip_region_xdb_read_header($srcPath);
    if ($hdr === null) {
        return ['success' => false, 'error' => 'bad_format'];
    }
    $expected = ($ver === 'v6') ? 6 : 4;
    if ($hdr['ipVersion'] !== $expected) {
        return ['success' => false, 'error' => 'type_mismatch'];
    }

    $target = ($ver === 'v6') ? IP_REGION_XDB_V6 : IP_REGION_XDB_V4;
    $segSize = ($ver === 'v6') ? 38 : 14;
    $segments = (int)(($hdr['endPtr'] - $hdr['startPtr']) / $segSize);

    // 确保目标目录存在（app/data 不随代码分发，首次安装时需创建）
    $dir = dirname($target);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // 写 .new 临时文件后原子替换，避免与只读查询竞争
    $tmp = $target . '.new';
    @unlink($tmp);
    if (!@rename($srcPath, $tmp)) {
        if (!@copy($srcPath, $tmp)) {
            @unlink($srcPath);
            return ['success' => false, 'error' => 'replace_failed'];
        }
        @unlink($srcPath);
    }
    if (!@rename($tmp, $target)) {
        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return ['success' => false, 'error' => 'replace_failed'];
        }
    }

    return [
        'success'  => true,
        'type'     => $ver,
        'imported' => $segments,
        'size'     => (int)@filesize($target),
    ];
}

/**
 * IP 库状态（供后台「IP 库管理」页面展示）
 */
function ip_region_db_status(): array
{
    $status = [
        'exists'     => false,
        'mode'       => 'xdb',
        'file'       => '',
        'size'       => 0,
        'v4_lines'   => 0,
        'v6_lines'   => 0,
        'dict_count' => 0,
        'v4_updated' => '0',
        'v6_updated' => '0',
        'v4_file'    => '',
        'v6_file'    => '',
        'sample_v4'  => '',
        'sample_v6'  => '',
        'sample_ok'  => false,
        'driver_ok'  => true,
    ];

    $v4 = ip_region_xdb_info('v4');
    $v6 = ip_region_xdb_info('v6');
    if ($v4 === null && $v6 === null) {
        $status['file'] = IP_REGION_XDB_V4;
        return $status;
    }

    $status['exists'] = true;
    $status['v4_file'] = $v4['path'] ?? '';
    $status['v6_file'] = $v6['path'] ?? '';
    $status['file'] = implode(' / ', array_values(array_filter([$status['v4_file'], $status['v6_file']])));
    $status['size'] = (int)($v4['size'] ?? 0) + (int)($v6['size'] ?? 0);
    $status['v4_lines'] = (int)($v4['segments'] ?? 0);
    $status['v6_lines'] = (int)($v6['segments'] ?? 0);
    $status['v4_updated'] = (string)($v4['createdAt'] ?? '0');
    $status['v6_updated'] = (string)($v6['createdAt'] ?? '0');

    // 抽样验证（v4/v6 各抽样若干公网 IP，命中即认为可用）
    $status['sample_v4'] = ip_region_sample(ip_region_query('223.5.5.5'), ip_region_query('8.8.8.8'));
    $status['sample_v6'] = ip_region_sample(ip_region_query('2400:3200::1'), ip_region_query('2606:4700:4700::1111'));
    $status['sample_ok'] = $status['sample_v4'] !== '' || $status['sample_v6'] !== '';

    return $status;
}

/**
 * 抽样结果归一化：命中返回命中文本，否则返回 ''
 */
function ip_region_sample(?string ...$results): string
{
    foreach ($results as $r) {
        if ($r !== null && $r !== '' && $r !== 'LAN') {
            return $r;
        }
    }
    return '';
}

/**
 * 删除 IP 归属地库文件（危险操作，后台调用）
 */
function ip_region_db_clear(): bool
{
    $ok = true;
    foreach ([IP_REGION_XDB_V4, IP_REGION_XDB_V6] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $tmp = $path . '.del';
        // 先改名再删除，避免与只读连接竞争
        if (@rename($path, $tmp)) {
            @unlink($tmp);
        } else {
            $ok = $ok && @unlink($path);
        }
    }
    return $ok;
}

/**
 * 字节数人性化显示
 */
function ip_region_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
