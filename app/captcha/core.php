<?php
/**
 * 云界论坛 - 自建人机验证系统（独立模块）
 *
 * 仿 Cloudflare Turnstile / reCAPTCHA，无第三方依赖、无需注册外部服务。
 * 本文件为服务端核心：Canvas 拼图滑块生成（基于 GD）+ 行为打分 + 校验。
 *
 * 验证流程：
 *  1. captcha_new()         页面/接口初始化挑战，返回 token
 *  2. 用户点击复选框后，前端收集行为特征（鼠标轨迹、操作、停留时间等）
 *     POST captcha_check() 由服务端行为打分：
 *       - 得分达标 → 直接标记通过（无感验证）
 *       - 得分不足 → 下发拼图滑块挑战（GD 生成的 base64 图片）
 *  3. 滑块拖拽松手后调用 captcha_slider_verify() 校验（通过则标记 passed）
 *  4. 表单提交时调用 captcha_passed() 校验已通过且一次性消费挑战
 *
 * 参考：https://github.com/Kocher-JGC/jigsaw-verify
 * 设计要点：
 *   - 使用 GD 生成真实图片背景，替代 SVG 字符串
 *   - 拼图小块从背景中裁切，并带有右侧凸起（仿拼图形状）
 *   - 支持换图刷新
 *
 * 后台设置键：captcha_enabled（兼容旧键 slider_captcha_enabled）
 * 前端资源见本目录：api.php（接口）、captcha.js、captcha.css、serve.php（静态资源出口）
 */

if (!defined('APP_ROOT')) {
    exit;
}

/* ==================== 图片拼图尺寸 ==================== */
const SLIDER_CAPTCHA_WIDTH        = 300;
const SLIDER_CAPTCHA_HEIGHT       = 150;
const SLIDER_CAPTCHA_PIECE        = 50;
const SLIDER_CAPTCHA_TAB          = 8;
const SLIDER_CAPTCHA_TOLERANCE    = 8;

/* ==================== 校验参数 ==================== */
const CAPTCHA_TTL          = 300;
const CAPTCHA_MAX_ATTEMPTS = 5;
const CAPTCHA_PASS_SCORE   = 3;

/* ==================== 点文字验证参数 ==================== */
const CAPTCHA_CLICK_BANK_SIDE = 3;
const CAPTCHA_CLICK_BANK_SIZE = 9;
const CAPTCHA_CLICK_ANSWER_WORDS_MIN = 2;
const CAPTCHA_CLICK_ANSWER_WORDS_MAX = 4;

/* ==================== 随机背景图 ==================== */
const CAPTCHA_BG_API = 'https://api.szczk.top/background/';
const CAPTCHA_BG_TIMEOUT = 5;

/* ==================== 推理拼图交换验证参数 ==================== */
const SWAP_CAPTCHA_WIDTH      = 300;
const SWAP_CAPTCHA_HEIGHT     = 150;
const SWAP_CAPTCHA_COLS       = 2;
const SWAP_CAPTCHA_ROWS       = 2;
const SWAP_CAPTCHA_PAD        = 2;
const SWAP_CAPTCHA_GAP        = 3;

/* ==================== 抗绕过安全增强 ==================== */
const CAPTCHA_POW_DEFAULT_BITS = 3;   // 默认可选 3（约 4096 次哈希，人类瞬时，机器人昂贵）
const CAPTCHA_HONEYPOT_LEN     = 10;
const CAPTCHA_RL_MAX_FAILS     = 12;  // 单 IP 窗口内最大失败次数
const CAPTCHA_RL_WINDOW        = 300; // 限流窗口（秒）
const CAPTCHA_RL_BLOCK         = 900; // 锁死时长（秒）
const CAPTCHA_ESCALATE_AFTER   = 2;   // 失败几次后升级难度

/**
 * 客户端 IP 前三段（用于 token 绑定，兼顾 NAT/代理环境）
 */
function captcha_ip_prefix(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    }
    $parts6 = explode(':', $ip);
    return implode(':', array_slice($parts6, 0, 4));
}

/**
 * 惰性生成每个安装唯一的 HMAC 签名密钥（写入 data/ 且不进版本库）
 */
function captcha_hmac_key(): string {
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = APP_ROOT . 'data/captcha_secret.php';
    if (is_file($file)) {
        include $file;
        if (!empty($GLOBALS['_captcha_hmac_key'])) {
            $key = (string)$GLOBALS['_captcha_hmac_key'];
            return $key;
        }
    }
    $key = bin2hex(random_bytes(32));
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $content = "<?php\n// 自动生成的验证码签名密钥，请勿删除或提交到版本库\n\$GLOBALS['_captcha_hmac_key'] = '" . $key . "';\n";
    @file_put_contents($file, $content);
    @chmod($file, 0600);
    return $key;
}

/**
 * 计算 token 签名（绑定 session_id + IP 前缀，防止跨会话重放/伪造）
 */
function captcha_token_sig(string $token): string {
    return hash_hmac('sha256', $token . '|' . session_id() . '|' . captcha_ip_prefix(), captcha_hmac_key());
}

/* ==================== 设置开关（后台可配置） ==================== */
function captcha_pow_enabled(): bool {
    return function_exists('get_site_setting') && get_site_setting('captcha_pow_enabled', '1') === '1';
}
function captcha_pow_bits(): int {
    $v = function_exists('get_site_setting')
        ? (int)get_site_setting('captcha_pow_bits', (string)CAPTCHA_POW_DEFAULT_BITS)
        : CAPTCHA_POW_DEFAULT_BITS;
    return max(1, min(6, $v));
}
function captcha_honeypot_enabled(): bool {
    return function_exists('get_site_setting') && get_site_setting('captcha_honeypot_enabled', '1') === '1';
}
function captcha_escalation_enabled(): bool {
    return function_exists('get_site_setting') && get_site_setting('captcha_escalation_enabled', '1') === '1';
}
function captcha_rotation_enabled(): bool {
    return function_exists('get_site_setting') && get_site_setting('captcha_rotation_enabled', '1') === '1';
}

/**
 * 生成 PoW 挑战参数（prefix + 目标前缀零位数）
 */
function captcha_pow_challenge(): ?array {
    if (!captcha_pow_enabled()) {
        return null;
    }
    $bits = captcha_pow_bits();
    return [
        'prefix' => bin2hex(random_bytes(8)),
        'bits'   => $bits,
        'target' => str_repeat('0', $bits),
    ];
}

/**
 * 校验 PoW：sha256(prefix . nonce) 的前 bits 位必须为 target
 *
 * 注意：PoW 是防御性深度检测，不应作为硬性门禁。
 * 前端 JS 在低端设备/大 bits 下可能超时返回空值，
 * 此处失败仅记录不阻断，避免误伤正常用户。
 */
function captcha_verify_pow(?array $pow, ?string $nonce): bool {
    if ($pow === null) {
        return true; // 未启用 PoW
    }
    if ($nonce === null || $nonce === '' || !preg_match('/^[0-9a-fA-F]+$/', (string)$nonce)) {
        return false; // PoW 缺失或非法 → 标记但不阻断（调用方决定是否使用）
    }
    $hash = hash('sha256', $pow['prefix'] . $nonce);
    return substr($hash, 0, (int)$pow['bits']) === $pow['target'];
}

/**
 * 蜜罐字段名（随机；注入隐藏输入框，机器人自动填写即判失败）
 */
function captcha_honeypot_name(): string {
    static $name = null;
    if ($name === null) {
        $name = 'hp_' . bin2hex(random_bytes(CAPTCHA_HONEYPOT_LEN));
    }
    return $name;
}

/**
 * 检查蜜罐：字段被填写（机器人行为）返回 false
 */
function captcha_honeypot_ok(array $post): bool {
    if (!captcha_honeypot_enabled()) {
        return true;
    }
    $name = $_SESSION['captcha_hp_name'] ?? '';
    if ($name === '') {
        return true;
    }
    return empty($post[$name] ?? '');
}

/**
 * IP 限流：是否已被锁死
 */
function captcha_rl_blocked(): bool {
    $file = APP_ROOT . 'data/cache/captcha_rl_' . md5(captcha_ip_prefix()) . '.json';
    if (!is_file($file)) {
        return false;
    }
    $data = @json_decode(@file_get_contents($file), true);
    if (!is_array($data)) {
        return false;
    }
    return (!empty($data['blocked_until']) && $data['blocked_until'] > time());
}

/**
 * 记录一次失败（IP 维度），超阈值则锁死一段时间
 */
function captcha_rl_hit(): void {
    $file = APP_ROOT . 'data/cache/captcha_rl_' . md5(captcha_ip_prefix()) . '.json';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $now = time();
    $data = is_file($file) ? (@json_decode(@file_get_contents($file), true) ?? []) : [];
    if (($data['window_start'] ?? 0) < $now - CAPTCHA_RL_WINDOW) {
        $data = ['window_start' => $now, 'fails' => 0, 'blocked_until' => 0];
    }
    $data['fails'] = (int)($data['fails'] ?? 0) + 1;
    if ($data['fails'] >= CAPTCHA_RL_MAX_FAILS) {
        $data['blocked_until'] = $now + CAPTCHA_RL_BLOCK;
    }
    @file_put_contents($file, json_encode($data));
}

/**
 * 抗 AI 视觉识别：在图像上叠加高频噪声与细微波纹干扰
 * 对人类几乎无感，但能显著干扰计算机视觉的轮廓/边缘提取
 */
function captcha_anti_ai_noise($img, int $w, int $h, int $intensity = 18): void {
    if (!function_exists('imagesetpixel')) {
        return;
    }
    // 高频噪点
    for ($i = 0, $n = (int)($w * $h * $intensity / 1000); $i < $n; $i++) {
        $x = random_int(0, $w - 1);
        $y = random_int(0, $h - 1);
        $c = random_int(0, 1) ? 255 : 0;
        $a = random_int(20, 70);
        $col = imagecolorallocatealpha($img, $c, $c, $c, 127 - (int)($a / 2));
        imagesetpixel($img, $x, $y, $col);
    }
    // 细微斜向干扰线（低对比度）
    for ($i = 0, $n = random_int(2, 4); $i < $n; $i++) {
        $cx = random_int(0, $w);
        $cy = random_int(0, $h);
        $col = imagecolorallocatealpha($img, 200, 200, 200, 110);
        imageline($img, $cx, $cy, $cx + random_int(-30, 30), $cy + random_int(-30, 30), $col);
    }
}

/**
 * 从随机背景图 API 获取一张图片并缩放/裁剪到目标尺寸
 *
 * - 请求 https://api.szczk.top/background/ 获取随机图片
 * - 按 cover 方式裁剪并缩放到 $width × $height
 * - 失败时返回 null（调用方退回程序化绘制）
 *
 * @return resource|null GD 图像资源
 */
function captcha_fetch_bg(int $width, int $height) {
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $data = @file_get_contents(CAPTCHA_BG_API, false, stream_context_create([
        'http' => [
            'timeout'    => CAPTCHA_BG_TIMEOUT,
            'max_redirects' => 5,
            'follow_location' => 1,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
        ],
    ]));
    if (!$data) {
        return null;
    }

    // 解码图片：优先用 imagecreatefromstring（GD 支持 webp 时可直接解码 webp 字节）
    $src = @imagecreatefromstring($data);

    // 兜底：若 GD 的 fromstring 不支持该格式，则尝试写入临时文件用 imagecreatefromwebp 解码
    if (!$src && function_exists('imagecreatefromwebp')) {
        $tmp = @tempnam(sys_get_temp_dir(), 'cap');
        if ($tmp && @file_put_contents($tmp, $data) !== false) {
            $src = @imagecreatefromwebp($tmp);
            @unlink($tmp);
        }
    }
    if (!$src) {
        return null;
    }

    $sw = imagesx($src);
    $sh = imagesy($src);
    $targetRatio = $width / $height;
    $srcRatio = $sw / $sh;

    // cover 裁剪：取源图能填满目标比例的最大区域
    if ($srcRatio > $targetRatio) {
        $cropW = (int)round($sh * $targetRatio);
        $cropH = $sh;
    } else {
        $cropW = $sw;
        $cropH = (int)round($sw / $targetRatio);
    }
    $sx = (int)(($sw - $cropW) / 2);
    $sy = (int)(($sh - $cropH) / 2);

    $dst = imagecreatetruecolor($width, $height);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $width, $height, $cropW, $cropH);
    imagedestroy($src);
    return $dst;
}

/**
 * 使用 GD 生成拼图验证码图片（仿 jigsaw-verify）
 *
 * - 随机程序化场景：天空渐变 + 太阳 + 云朵 + 远山 + 近景树木
 * - 将场景画到 GD 画布上，再裁出拼图块，并在原位置留出缺口
 * - 返回 base64 data URL 供前端 <img> 直接使用
 */
function slider_captcha_gd(int $gapX, int $gapY): array {
    $w = SLIDER_CAPTCHA_WIDTH;
    $h = SLIDER_CAPTCHA_HEIGHT;
    $pw = SLIDER_CAPTCHA_PIECE;
    $tabR = SLIDER_CAPTCHA_TAB;

    // 优先使用随机背景图 API，失败则退回程序化绘制
    $img = captcha_fetch_bg($w, $h);
    $usingApiBg = ($img !== null);
    if (!$img) {
        $img = imagecreatetruecolor($w, $h);

        // 天空渐变
        for ($y = 0; $y < $h; $y++) {
            $ratio = $y / max(1, $h - 1);
            $r = (int)(120 + $ratio * 80);
            $g = (int)(180 + $ratio * 55);
            $b = (int)(220 + $ratio * 35);
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w - 1, $y, $color);
        }

        // 太阳
        $sunX = random_int(40, $w - 40);
        $sunY = random_int(18, 60);
        $sunR = random_int(10, 16);
        imagefilledellipse($img, $sunX, $sunY, $sunR * 2, $sunR * 2, imagecolorallocate($img, 255, 250, 200));

        // 云朵
        for ($i = 0, $n = random_int(2, 3); $i < $n; $i++) {
            $cx = random_int(20, $w - 50);
            $cy = random_int(10, 50);
            $s = random_int(10, 18);
            imagefilledellipse($img, $cx, $cy, $s * 2, max(5, (int)($s * 0.7)), $white = imagecolorallocate($img, 255, 255, 255));
            imagefilledellipse($img, (int)($cx + $s * 0.8), (int)($cy + 2), (int)($s * 1.4), max(4, (int)($s * 0.6)), $white);
            imagefilledellipse($img, (int)($cx - $s * 0.8), (int)($cy + 2), (int)($s * 1.2), max(4, (int)($s * 0.5)), $white);
        }

        // 远山
        $farColor = imagecolorallocate($img, 130, 160, 130);
        $pts = '0,' . ($h - 20);
        $x = 0;
        while ($x < $w) {
            $x = min($w, $x + random_int(40, 90));
            $pts .= ' ' . $x . ',' . random_int(30, 55);
        }
        $pts .= ' ' . $w . ',' . ($h - 20);
        imagefilledpolygon($img, explode(',', str_replace(' ', ',', $pts)), 3, $farColor);

        // 近山
        $nearColor = imagecolorallocate($img, 80, 120, 70);
        $pts = '0,' . ($h - 8);
        $x = 0;
        while ($x < $w) {
            $x = min($w, $x + random_int(40, 80));
            $pts .= ' ' . $x . ',' . random_int(55, 85);
        }
        $pts .= ' ' . $w . ',' . ($h - 8);
        imagefilledpolygon($img, explode(',', str_replace(' ', ',', $pts)), 3, $nearColor);

        // 树木
        for ($i = 0, $n = random_int(2, 4); $i < $n; $i++) {
            $tx = random_int(6, $w - 10);
            $ty = $h - 8;
            $th = random_int(12, 24);
            imagefilledpolygon($img, [$tx, $ty + 2, $tx - 4, $ty - $th, $tx + 4, $ty - $th], 3, imagecolorallocate($img, 60, 100, 40));
        }

        // 地面
        imagefilledrectangle($img, 0, $h - 8, $w - 1, $h - 1, imagecolorallocate($img, 80, 120, 60));

        // 额外装饰（简单房子轮廓，增加辨识度）
        $accent = imagecolorallocate($img, 180, 120, 80);
        imagefilledrectangle($img, $w - 55, $h - 30, $w - 15, $h - 8, $accent);
        imagefilledrectangle($img, $w - 48, $h - 40, $w - 22, $h - 30, imagecolorallocate($img, 140, 100, 60));
    }

    // 抗 AI 视觉识别：背景叠加高频噪声（破坏边缘/轮廓提取）
    captcha_anti_ai_noise($img, $w, $h);

    // 确保 gapX/gapY 不超出边界
    $gapX = max(0, min($w - $pw, $gapX));
    $gapY = max(0, min($h - $pw, $gapY));

    // 绘制拼图块（从背景中裁切）
    $pieceImg = imagecreatetruecolor($pw + $tabR, $pw);
    imagesavealpha($pieceImg, true);
    $transparent = imagecolorallocatealpha($pieceImg, 0, 0, 0, 127);
    imagefill($pieceImg, 0, 0, $transparent);
    imagecopy($pieceImg, $img, 0, 0, $gapX, $gapY, $pw, $pw);

    // 拼图块外边框 + 阴影
    $border = imagecolorallocate($pieceImg, 255, 255, 255);
    $shadow = imagecolorallocate($pieceImg, 30, 41, 59);
    imagerectangle($pieceImg, 0, 0, $pw - 1, $pw - 1, $border);
    imagerectangle($pieceImg, 1, 1, $pw - 2, $pw - 2, $shadow);

    // 不规则拼图边缘（抗计算机视觉：破坏规则矩形轮廓，使 CV 难以干净抠出拼块）
    $cut = imagecolorallocatealpha($pieceImg, 0, 0, 0, 127);
    $edgeCount = random_int(3, 6);
    for ($i = 0; $i < $edgeCount; $i++) {
        $side = random_int(0, 3);
        $pos = random_int(4, $pw - 4);
        $r = random_int(3, 6);
        if ($side === 0) { $cx = 0; $cy = $pos; }
        elseif ($side === 1) { $cx = $pw - 1; $cy = $pos; }
        elseif ($side === 2) { $cx = $pos; $cy = 0; }
        else { $cx = $pos; $cy = $pw - 1; }
        imagefilledellipse($pieceImg, $cx, $cy, $r * 2, $r * 2, $cut);
    }
    // 拼块自身也叠加轻量噪声
    captcha_anti_ai_noise($pieceImg, $pw + $tabR, $pw, 10);

    // 拼图块右侧凸起（半圆）
    $cx = $pw - 1;
    $cy = (int)($pw / 2);
    imagefilledellipse($pieceImg, $cx + $tabR, $cy, $tabR * 2, $tabR * 2, $border);
    imagefilledellipse($pieceImg, $cx + $tabR + 1, $cy, $tabR * 2 - 2, $tabR * 2 - 2, $shadow);

    // 在背景缺口处绘制深色镂空 + 白色描边
    $gapColor = imagecolorallocatealpha($img, 0, 0, 0, 60);
    imagefilledrectangle($img, $gapX, $gapY, $gapX + $pw - 1, $gapY + $pw - 1, $gapColor);
    imagerectangle($img, $gapX, $gapY, $gapX + $pw - 1, $gapY + $pw - 1, $border);
    imagerectangle($img, $gapX + 1, $gapY + 1, $gapX + $pw - 2, $gapY + $pw - 2, $shadow);

    // 输出 base64
    ob_start();
    imagepng($img);
    $bgB64 = base64_encode(ob_get_clean());

    ob_start();
    imagepng($pieceImg);
    $pieceB64 = base64_encode(ob_get_clean());

    imagedestroy($img);
    imagedestroy($pieceImg);

    return [
        'bg_b64'      => 'data:image/png;base64,' . $bgB64,
        'piece_b64'   => 'data:image/png;base64,' . $pieceB64,
        'width'       => $w,
        'height'      => $h,
        'piece_width' => $pw + $tabR,
    ];
}

/**
 * 人机验证是否已在后台启用（兼容旧开关键 slider_captcha_enabled）
 */
function captcha_enabled(): bool {
    if (!function_exists('get_site_setting')) {
        return false;
    }
    $v = get_site_setting('captcha_enabled', '');
    if ($v === '') {
        $v = get_site_setting('slider_captcha_enabled', '0');
    }
    return $v === '1';
}

/**
 * 初始化新挑战，返回前端 token 与拼图尺寸信息
 */
function captcha_new(): array {
    $token = bin2hex(random_bytes(16));
    $pow = captcha_pow_challenge();
    $hpName = captcha_honeypot_enabled() ? captcha_honeypot_name() : '';
    if ($hpName !== '') {
        $_SESSION['captcha_hp_name'] = $hpName;
    }
    $_SESSION['captcha'] = [
        'token'      => $token,
        'sig'        => captcha_token_sig($token),
        'expires'    => time() + CAPTCHA_TTL,
        'attempts'   => 0,
        'passed'     => false,
        'mode'       => 'behavior',
        'gap'        => null,
        'difficulty' => captcha_difficulty(),
        'pow'        => $pow,
        'escalation' => 0,
    ];
    $out = [
        'token'       => $token,
        'width'       => SLIDER_CAPTCHA_WIDTH,
        'height'      => SLIDER_CAPTCHA_HEIGHT,
        'piece_width' => SLIDER_CAPTCHA_PIECE + SLIDER_CAPTCHA_TAB,
    ];
    if ($pow !== null) {
        $out['pow'] = $pow;
    }
    if ($hpName !== '') {
        $out['hp_name'] = $hpName;
    }
    if (captcha_rl_blocked()) {
        $out['blocked'] = true;
        $out['blocked_msg'] = t('captcha_blocked', '验证尝试过于频繁，请稍后再试');
    }
    return $out;
}

/**
 * 行为验证：根据前端提交的行为特征打分
 * 达标 → 直接通过；不达标 → 根据配置下发对应挑战
 */
function captcha_check(string $token, array $signals, bool $refresh = false): array {
    $cap = $_SESSION['captcha'] ?? null;
    if (!is_array($cap) || ($cap['token'] ?? '') !== $token) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    if (($cap['expires'] ?? 0) < time()) {
        unset($_SESSION['captcha']);
        return ['ok' => false, 'error' => 'expired'];
    }
    if (($cap['attempts'] ?? 0) >= captcha_max_attempts()) {
        unset($_SESSION['captcha']);
        return ['ok' => false, 'error' => 'attempts'];
    }
    // IP 限流：被锁死时直接拒绝
    if (captcha_rl_blocked()) {
        return ['ok' => false, 'error' => 'blocked', 'message' => t('captcha_blocked', '验证尝试过于频繁，请稍后再试')];
    }

    // 失败升级：多次失败后切换到更难挑战并收紧容差
    $escalate = captcha_escalation_enabled() && ($cap['attempts'] ?? 0) >= CAPTCHA_ESCALATE_AFTER;
    if ($escalate) {
        $cap['escalation'] = 1;
        $_SESSION['captcha'] = $cap;
    }

    // 换一张：跳过行为打分，直接重新生成挑战
    $skipBehavior = $refresh || captcha_display() === 'popup';
    if (!$skipBehavior && captcha_behavior_score($signals) >= captcha_pass_score()) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true, 'method' => 'auto'];
    }

    $style = captcha_style();
    if ($style === 'auto') {
        // 启用轮换时随机选一种；升级时强制滑块（位置型对机器人最难）
        $styles = captcha_rotation_enabled() ? ['slider', 'click', 'swap'] : ['slider'];
        if ($escalate) {
            $styles = ['slider'];
        }
        $style = $styles[random_int(0, count($styles) - 1)];
    } elseif ($escalate && $style !== 'slider') {
        // 升级时即便配置了 click/swap 也回落到 slider
        $style = 'slider';
    }
    if ($style === 'click') {
        return captcha_click_challenge($cap);
    }
    if ($style === 'swap') {
        return captcha_swap_challenge($cap);
    }
    return captcha_slider_challenge($cap);
}

/**
 * 下发拼图滑块挑战（兜底项）
 */
function captcha_slider_challenge(array $cap): array {
    $gapX = random_int(
        SLIDER_CAPTCHA_PIECE + 12,
        SLIDER_CAPTCHA_WIDTH - SLIDER_CAPTCHA_PIECE - 12
    );
    $gapY = random_int(28, SLIDER_CAPTCHA_HEIGHT - SLIDER_CAPTCHA_PIECE - 16);
    $gd = slider_captcha_gd($gapX, $gapY);

    $cap['mode'] = 'slider';
    $cap['gap']  = $gapX;
    $cap['gapY'] = $gapY;
    $tol = captcha_slider_tolerance();
    // 升级模式：容差减半，进一步压缩机器人暴力空间
    if (!empty($cap['escalation'])) {
        $tol = max(3, (int)($tol / 2));
    }
    $cap['tol']  = $tol;
    $_SESSION['captcha'] = $cap;

    return [
        'ok'          => false,
        'challenge'   => 'slider',
        'width'       => SLIDER_CAPTCHA_WIDTH,
        'height'      => SLIDER_CAPTCHA_HEIGHT,
        'piece_width' => SLIDER_CAPTCHA_PIECE + SLIDER_CAPTCHA_TAB,
        'gap_y'       => $gapY,
        'bg_b64'      => $gd['bg_b64'],
        'piece_b64'   => $gd['piece_b64'],
    ];
}

/**
 * 下发推理拼图交换验证码挑战（拖动交换 2 个图块复原图片）
 *
 * - 将背景图切割为 cols × rows 的网格
 * - 随机交换其中 2 个图块的位置
 * - 用户通过拖拽交换图块使其恢复正确顺序
 */
function captcha_swap_challenge(array $cap): array {
    $cols = SWAP_CAPTCHA_COLS;
    $rows = SWAP_CAPTCHA_ROWS;
    $total = $cols * $rows;
    if ($total < 4) {
        $cols = 2;
        $rows = 2;
        $total = 4;
    }

    $srcW = SWAP_CAPTCHA_WIDTH;
    $srcH = SWAP_CAPTCHA_HEIGHT;
    $pad  = SWAP_CAPTCHA_PAD;
    $gap  = SWAP_CAPTCHA_GAP;

    $stageW = $srcW - $pad * 2;
    $stageH = $srcH - $pad * 2;
    $pieceW = (int)(($stageW - ($cols - 1) * $gap) / $cols);
    $pieceH = (int)(($stageH - ($rows - 1) * $gap) / $rows);

    // 获取背景图
    $src = captcha_fetch_bg($srcW, $srcH);
    if (!$src && function_exists('imagecreatetruecolor')) {
        $src = imagecreatetruecolor($srcW, $srcH);
        for ($y = 0; $y < $srcH; $y++) {
            $ratio = $y / max(1, $srcH - 1);
            $r = (int)(90 + $ratio * 100);
            $g = (int)(140 + $ratio * 80);
            $b = (int)(180 + $ratio * 60);
            $color = imagecolorallocate($src, $r, $g, $b);
            imageline($src, 0, $y, $srcW - 1, $y, $color);
        }
        for ($i = 0, $n = random_int(3, 6); $i < $n; $i++) {
            $cx = random_int(20, $srcW - 20);
            $cy = random_int(20, $srcH - 20);
            $r  = random_int(8, 20);
            $c  = imagecolorallocatealpha($src, random_int(100, 255), random_int(100, 255), random_int(100, 255), random_int(30, 60));
            imagefilledellipse($src, $cx, $cy, $r * 2, $r * 2, $c);
        }
    }
    if (!$src) {
        $cap['mode']   = 'swap';
        $cap['passed'] = false;
        $_SESSION['captcha'] = $cap;
        return ['ok' => false, 'error' => 'gd_unavailable'];
    }

    // ========== 正确顺序始终是 [0,1,2,3] ==========
    $correctOrder = range(0, $total - 1);  // [0,1,2,3]
    
    // 初始顺序：被打乱的顺序
    $order = range(0, $total - 1);
    
    // 随机交换 2 个位置制造"破损"
    $a = random_int(0, $total - 1);
    $b = random_int(0, $total - 1);
    while ($b === $a) {
        $b = random_int(0, $total - 1);
    }
    // 交换 $a 和 $b 位置的图块
    $tmp = $order[$a];
    $order[$a] = $order[$b];
    $order[$b] = $tmp;

    // 记录正确顺序和交换信息
    $cap['mode']       = 'swap';
    $cap['swap_a']     = $a;
    $cap['swap_b']     = $b;
    $cap['answer']     = $correctOrder; // ✅ 正确答案是 [0,1,2,3]
    $cap['cols']       = $cols;
    $cap['rows']       = $rows;
    $cap['piece_w']    = $pieceW;
    $cap['piece_h']    = $pieceH;
    $cap['stage_w']    = $stageW;
    $cap['stage_h']    = $stageH;
    $cap['gap']        = $gap;
    $cap['attempts']   = 0;
    $_SESSION['captcha'] = $cap;

    // 生成拼图块图片（返回每个图块的位置）
    $pieces = [];
    for ($idx = 0; $idx < $total; $idx++) {
        $correctIdx = $order[$idx]; // 当前排列中第 idx 个位置放的应是 correctIdx 块
        $origRow = (int)floor($correctIdx / $cols);
        $origCol = $correctIdx % $cols;

        $sx = $pad + $origCol * ($pieceW + $gap);
        $sy = $pad + $origRow * ($pieceH + $gap);

        $pieceImg = imagecreatetruecolor($pieceW, $pieceH);
        imagesavealpha($pieceImg, true);
        $transparent = imagecolorallocatealpha($pieceImg, 0, 0, 0, 127);
        imagefill($pieceImg, 0, 0, $transparent);
        imagecopy($pieceImg, $src, 0, 0, $sx, $sy, $pieceW, $pieceH);

        // 边框 + 轻微阴影
        $border = imagecolorallocatealpha($pieceImg, 255, 255, 255, 30);
        $shadow = imagecolorallocatealpha($pieceImg, 0, 0, 0, 40);
        imagerectangle($pieceImg, 0, 0, $pieceW - 1, $pieceH - 1, $border);
        imagerectangle($pieceImg, 1, 1, $pieceW - 2, $pieceH - 2, $shadow);

        ob_start();
        imagepng($pieceImg);
        $b64 = base64_encode(ob_get_clean());
        imagedestroy($pieceImg);

        $pieces[] = [
            'index'   => $idx,
            'correct' => $correctIdx,
            'b64'     => 'data:image/png;base64,' . $b64,
        ];
    }

    imagedestroy($src);

    return [
        'ok'      => false,
        'challenge' => 'swap',
        'width'   => $srcW,
        'height'  => $srcH,
        'cols'    => $cols,
        'rows'    => $rows,
        'piece_w' => $pieceW,
        'piece_h' => $pieceH,
        'stage_w' => $stageW,
        'stage_h' => $stageH,
        'gap'     => $gap,
        'pieces'  => $pieces,
        'order'   => $order,
    ];
}

/**
 * 交换拼图校验：用户提交的排列顺序与正确顺序一致则通过
 *
 * @param string $token 挑战 token
 * @param array  $order 用户提交的图块排列（每个元素为 correct index）
 */
function captcha_swap_verify(string $token, array $order, ?string $powNonce = null): array {
    $cap = $_SESSION['captcha'] ?? null;
    if (!is_array($cap) || ($cap['token'] ?? '') !== $token) {
        return ['ok' => false];
    }
    if (($cap['expires'] ?? 0) < time()) {
        unset($_SESSION['captcha']);
        return ['ok' => false];
    }
    if (($cap['mode'] ?? '') !== 'swap') {
        return ['ok' => false];
    }
    if (($cap['sig'] ?? '') !== captcha_token_sig($token)) {
        unset($_SESSION['captcha']);
        return ['ok' => false];
    }
    // PoW 校验（软失败：仅记录不阻断）
    if (!captcha_verify_pow($cap['pow'] ?? null, $powNonce)) {
        // PoW 失败不拒绝，继续走后续校验
    }

    $expected = $cap['answer'] ?? [];

    // 确保数组索引从 0 开始连续
    $got = array_values($order);
    if (json_encode($got) === json_encode($expected)) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true];
    }

    $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
    captcha_rl_hit();
    $_SESSION['captcha'] = $cap;
    return ['ok' => false];
}

/**
 * 验证方式：拼图(slider) / 点文字(click) / 推理交换(swap) / 智能混合(auto)，默认拼图
 */
function captcha_style(): string {
    if (!function_exists('get_site_setting')) {
        return 'slider';
    }
    $v = get_site_setting('captcha_style', 'slider');
    return in_array($v, ['slider', 'click', 'swap', 'auto'], true) ? $v : 'slider';
}

/**
 * 验证难度：简单(easy) / 普通(normal) / 困难(hard)，默认普通
 */
function captcha_difficulty(): string {
    if (!function_exists('get_site_setting')) {
        return 'normal';
    }
    $v = get_site_setting('captcha_difficulty', 'normal');
    return in_array($v, ['easy', 'normal', 'hard'], true) ? $v : 'normal';
}

/**
 * 验证显示方式：内嵌 (inline) / 弹窗 (popup)，默认内嵌
 */
function captcha_display(): string {
    if (!function_exists('get_site_setting')) {
        return 'inline';
    }
    $v = get_site_setting('captcha_display', 'inline');
    return in_array($v, ['inline', 'popup'], true) ? $v : 'inline';
}

/** 各难度对应的行为通过分数门槛 */
function captcha_pass_score(): int {
    return ['easy' => 2, 'normal' => 3, 'hard' => 5][captcha_difficulty()] ?? 3;
}

/** 各难度对应的最大尝试次数 */
function captcha_max_attempts(): int {
    return ['easy' => 5, 'normal' => 5, 'hard' => 3][captcha_difficulty()] ?? 5;
}

/** 各难度对应的滑块容差（像素，越小越难） */
function captcha_slider_tolerance(): int {
    $d = captcha_difficulty();
    $base = ['easy' => 15, 'normal' => 10, 'hard' => 7][$d] ?? 10;
    return $base;
}

/** 各难度对应的点选目标字数量 [min, max] */
function captcha_click_word_count(): array {
    $d = captcha_difficulty();
    if ($d === 'easy') {
        return [2, 2];
    }
    if ($d === 'hard') {
        return [4, 4];
    }
    return [CAPTCHA_CLICK_ANSWER_WORDS_MIN, CAPTCHA_CLICK_ANSWER_WORDS_MAX];
}

/**
 * 使用 GD 生成点文字验证码背景图
 * - 优先使用随机背景图 API，失败则程序化绘制风景图
 * - 返回 base64 data URL
 */
function click_captcha_bg(int $width, int $height): string {
    $img = captcha_fetch_bg($width, $height);
    if (!$img) {
        $img = imagecreatetruecolor($width, $height);

        // 天空渐变
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            $r = (int)(110 + $ratio * 90);
            $g = (int)(170 + $ratio * 60);
            $b = (int)(210 + $ratio * 40);
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $width - 1, $y, $color);
        }

        // 太阳
        $sunX = random_int(40, $width - 40);
        $sunY = random_int(18, 55);
        $sunR = random_int(10, 16);
        imagefilledellipse($img, $sunX, $sunY, $sunR * 2, $sunR * 2, imagecolorallocate($img, 255, 250, 200));

        // 云朵
        for ($i = 0, $n = random_int(2, 4); $i < $n; $i++) {
            $cx = random_int(20, $width - 50);
            $cy = random_int(10, 50);
            $s = random_int(10, 18);
            $white = imagecolorallocate($img, 255, 255, 255);
            imagefilledellipse($img, $cx, $cy, $s * 2, max(5, (int)($s * 0.7)), $white);
            imagefilledellipse($img, (int)($cx + $s * 0.8), (int)($cy + 2), (int)($s * 1.4), max(4, (int)($s * 0.6)), $white);
            imagefilledellipse($img, (int)($cx - $s * 0.8), (int)($cy + 2), (int)($s * 1.2), max(4, (int)($s * 0.5)), $white);
        }

        // 远山
        $farColor = imagecolorallocate($img, 120, 150, 120);
        $pts = [0, $height - 20];
        $x = 0;
        while ($x < $width) {
            $x = min($width, $x + random_int(40, 90));
            $pts[] = $x;
            $pts[] = random_int(40, 70);
        }
        $pts[] = $width;
        $pts[] = $height - 20;
        imagefilledpolygon($img, $pts, count($pts) / 2, $farColor);

        // 近山
        $nearColor = imagecolorallocate($img, 70, 110, 60);
        $pts = [0, $height - 8];
        $x = 0;
        while ($x < $width) {
            $x = min($width, $x + random_int(40, 80));
            $pts[] = $x;
            $pts[] = random_int(70, 100);
        }
        $pts[] = $width;
        $pts[] = $height - 8;
        imagefilledpolygon($img, $pts, count($pts) / 2, $nearColor);

        // 地面
        imagefilledrectangle($img, 0, $height - 8, $width - 1, $height - 1, imagecolorallocate($img, 75, 115, 55));
    }

    ob_start();
    imagepng($img);
    $b64 = base64_encode(ob_get_clean());
    imagedestroy($img);
    return 'data:image/png;base64,' . $b64;
}

/**
 * 生成单个汉字的 GD 透明小图（带扭曲/旋转效果）
 */
function click_char_image(string $char, int $size, string $colorHex): string {
    $pad = 8;
    $w = $size + $pad * 2;
    $h = $size + $pad * 2;
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);

    $rgb = hex_to_rgb($colorHex);
    $color = imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], random_int(0, 20));

    // 随机旋转角度
    $angle = random_int(-25, 25);
    $font = __DIR__ . '/fonts/chinese.ttf';
    if (!file_exists($font)) {
        $font = __DIR__ . '/fonts/SourceHanSansSC-Regular.otf';
    }
    if (!file_exists($font)) {
        imagestring($img, 5, $pad, $pad, $char, $color);
    } else {
        imagettftext($img, $size, $angle, (int)($pad * 0.8), (int)($h - $pad * 0.8), $color, $font, $char);
    }

    ob_start();
    imagepng($img);
    $b64 = base64_encode(ob_get_clean());
    imagedestroy($img);
    return 'data:image/png;base64,' . $b64;
}

function hex_to_rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [
        (int)hexdec(substr($hex, 0, 2)),
        (int)hexdec(substr($hex, 2, 2)),
        (int)hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * 下发点文字挑战（仿网易易盾「点选文字」）
 *
 * - 从词库随机取一个词（2-3 字），其每个字即"目标字"。
 * - 生成 3x3 候选字库：目标字 + 干扰字，打乱顺序。
 * - 用户需按词的顺序依次点击目标字；提交顺序与目标完全一致才算通过。
 * - 返回 bg_b64 背景图，以及每个字在图片上的位置 pieces。
 */
function captcha_click_challenge(array $cap): array {
    static $pool = ['风', '雨', '雷', '山', '水', '火', '木', '日', '月', '星', '安', '全', '云', '界', '论', '坛', '护', '码', '信', '号', '数', '图', '文', '字', '语', '言', '网', '络', '端', '游', '戏', '秒', '分', '时', '天', '地', '人', '和', '平', '乐', '喜', '福', '寿', '康', '宁', '春', '夏', '秋', '冬'];

    $words = captcha_click_word_count();
    do {
        $count = random_int($words[0], $words[1]);
        $tmp = $pool;
        shuffle($tmp);
        $targets = array_slice($tmp, 0, $count);
    } while (count($targets) < $words[0]);

    $distractors = array_values(array_filter($pool, function ($c) use ($targets) {
        return !in_array($c, $targets, true);
    }));
    shuffle($distractors);

    $bank = $targets;
    $need = CAPTCHA_CLICK_BANK_SIZE - count($bank);
    for ($i = 0; $i < $need && $i < count($distractors); $i++) {
        $bank[] = $distractors[$i];
    }
    shuffle($bank);

    $width = SLIDER_CAPTCHA_WIDTH;
    $height = SLIDER_CAPTCHA_HEIGHT;
    $bgB64 = click_captcha_bg($width, $height);

    // 生成每个字的位置与样式
    $colors = ['#e11d48', '#2563eb', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#be123c', '#4338ca'];
    $positions = [];
    $margin = 42;
    $used = [];
    foreach ($bank as $i => $ch) {
        $size = random_int(22, 30);
        $maxTry = 30;
        do {
            $x = random_int($margin, $width - $margin);
            $y = random_int($margin, $height - $margin);
            $maxTry--;
            $overlap = false;
            foreach ($used as $u) {
                if (abs($u['x'] - $x) < 38 && abs($u['y'] - $y) < 38) {
                    $overlap = true;
                    break;
                }
            }
        } while ($overlap && $maxTry > 0);
        $used[] = ['x' => $x, 'y' => $y];
        $positions[] = [
            'ch'    => $ch,
            'x'     => $x,
            'y'     => $y,
            'size'  => $size,
            'color' => $colors[random_int(0, count($colors) - 1)],
            'angle' => random_int(-25, 25),
        ];
    }

    $cap['mode']    = 'click';
    $cap['answer']  = $targets;
    $cap['bank']    = $bank;
    $cap['attempts'] = 0;
    $_SESSION['captcha'] = $cap;

    return [
        'ok'        => false,
        'challenge' => 'click',
        'prompt'    => implode('', $targets),
        'need'      => count($targets),
        'cols'      => CAPTCHA_CLICK_BANK_SIDE,
        'bank'      => $bank,
        'bg_b64'    => $bgB64,
        'positions' => $positions,
        'width'     => $width,
        'height'    => $height,
    ];
}

/**
 * 点文字校验：提交的字符序列必须与目标词顺序完全一致
 */
function captcha_click_verify(string $token, $seq, ?string $powNonce = null): array {
    $cap = $_SESSION['captcha'] ?? null;
    if (!is_array($cap) || ($cap['token'] ?? '') !== $token) {
        return ['ok' => false];
    }
    if (($cap['expires'] ?? 0) < time()) {
        unset($_SESSION['captcha']);
        return ['ok' => false];
    }
    if (($cap['mode'] ?? '') !== 'click') {
        return ['ok' => false];
    }
    if (($cap['sig'] ?? '') !== captcha_token_sig($token)) {
        unset($_SESSION['captcha']);
        return ['ok' => false];
    }
    // PoW 校验（软失败：仅记录不阻断）
    if (!captcha_verify_pow($cap['pow'] ?? null, $powNonce)) {
        // PoW 失败不拒绝，继续走后续校验
    }

    $expected = array_map('strval', $cap['answer'] ?? []);
    $got      = is_array($seq) ? array_values(array_map('strval', $seq)) : [];
    if ($got === $expected) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true];
    }

    $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
    captcha_rl_hit();
    $_SESSION['captcha'] = $cap;
    return ['ok' => false];
}

/**
 * 滑块拖拽松手后的即时校验：|x - gap| <= 容差 则标记已通过
 */
/**
 * 校验拼图滑块拖拽位置是否对齐缺口（含机器人行为风控）
 *
 * @param string $token   会话 token
 * @param int|null $x     拖拽终点 X 坐标
 * @param int $duration   拖拽耗时（毫秒），0 表示未提供（旧版兼容）
 * @param array $traj      轨迹点 [{t, x}, ...]，空数组表示未提供
 * @return array ['ok'=>bool, 'reason'?=>string]
 */
function captcha_slider_verify(string $token, $x, int $duration = 0, array $traj = [], ?string $powNonce = null): array {
    $cap = $_SESSION['captcha'] ?? null;
    if (!is_array($cap) || ($cap['token'] ?? '') !== $token) {
        return ['ok' => false, 'reason' => 'invalid_token'];
    }
    if (($cap['expires'] ?? 0) < time()) {
        unset($_SESSION['captcha']);
        return ['ok' => false, 'reason' => 'expired'];
    }
    if (($cap['mode'] ?? '') !== 'slider') {
        return ['ok' => false, 'reason' => 'mode_mismatch'];
    }
    // 安全校验：签名绑定（防止伪造/重放）
    if (($cap['sig'] ?? '') !== captcha_token_sig($token)) {
        unset($_SESSION['captcha']);
        return ['ok' => false, 'reason' => 'invalid_token'];
    }
    // PoW 校验（软失败：仅记录不阻断，避免低端设备/超时误伤正常用户）
    if (!captcha_verify_pow($cap['pow'] ?? null, $powNonce)) {
        // PoW 失败不拒绝，继续走后续校验（位置精度 + 风控）
    }

    // ========== 0. 机器人行为风控（仅在有风控数据时执行）==========
    if ($duration > 0 || !empty($traj)) {
        $risk = captcha_slider_risk_score($duration, $traj, (int)$cap['gap']);
        $diffMap = ['easy' => 0, 'normal' => 1, 'hard' => 2];
        $difficulty = $diffMap[($cap['difficulty'] ?? 'normal')] ?? 1;
        // 风险分数 >= 12 则直接拒绝（极高置信度机器人）
        if ($risk >= 12) {
            $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
            captcha_rl_hit();
            $_SESSION['captcha'] = $cap;
            return ['ok' => false, 'reason' => 'bot_detected', 'risk' => $risk];
        }
        // hard 模式下风险 >= 8 拒绝；normal 下 >= 10 才拒绝；easy 不因风控拒绝
        if ($difficulty === 2 && $risk >= 8) {
            $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
            captcha_rl_hit();
            $_SESSION['captcha'] = $cap;
            return ['ok' => false, 'reason' => 'suspicious', 'risk' => $risk];
        }
        if ($difficulty >= 1 && $risk >= 10) {
            $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
            captcha_rl_hit();
            $_SESSION['captcha'] = $cap;
            return ['ok' => false, 'reason' => 'suspicious', 'risk' => $risk];
        }
    }

    // ========== 1. 位置精度校验 ==========
    $x = (int)$x;
    $tol = (int)($cap['tol'] ?? captcha_slider_tolerance());

    $diff = abs($x - (int)$cap['gap']);
    $tolWithMargin = max($tol + 3, $tol);

    if ($diff <= $tolWithMargin) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true];
    }

    $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
    captcha_rl_hit();
    $_SESSION['captcha'] = $cap;
    return ['ok' => false, 'reason' => 'position_miss'];
}

/**
 * 滑块拖拽行为风险评分（机器人检测）
 *
 * 分析拖拽时间与轨迹，返回 0-15 的风险分数：
 *   0-2：正常人类行为
 *   3-5：轻微可疑（normal 难度下拒绝）
 *   6-9：高度可疑（hard 难度下拒绝）
 *  10+：确信机器人（所有难度均拒绝）
 *
 * @param int $duration 拖拽耗时（毫秒）
 * @param array $traj 轨迹点 [{t, x}, ...]
 * @param int $gapTarget 目标缺口 X 坐标
 * @return int 风险分数（0=正常，越高越像机器人）
 */
function captcha_slider_risk_score(int $duration, array $traj, int $gapTarget): int {
    $risk = 0;
    $n = count($traj);

    // ===== 1. 时间类检测 =====

    // 1a. 极速完成（< 100ms）—— 机器人特征，直接高分
    if ($duration > 0 && $duration < 100) {
        $risk += 6;
    }
    // 1b. 过快完成（100-200ms）—— 可疑
    elseif ($duration > 0 && $duration < 200) {
        $risk += 3;
    }
    // 1c. 超长拖拽（> 30s）—— 可能是放弃后误触
    if ($duration > 30000) {
        $risk += 3;
    }

    // ===== 2. 轨迹类检测（需至少 3 个轨迹点）=====
    if ($n >= 3) {

        // 2a. 轨迹点过少但距离远 —— 说明几乎没有移动过程，直接跳到目标
        $totalDist = abs($traj[$n - 1]['x'] - $traj[0]['x']);
        if ($n <= 4 && $totalDist > 50) {
            $risk += 4;
        }

        // 2b. 速度均匀性检测 —— 计算相邻点间速度的标准差
        $velocities = [];
        for ($i = 1; $i < $n; $i++) {
            $dt = (int)($traj[$i]['t']) - (int)($traj[$i - 1]['t']);
            $dx = abs((int)($traj[$i]['x']) - (int)($traj[$i - 1]['x']));
            if ($dt > 0) {
                $velocities[] = $dx / $dt; // px/ms
            }
        }
        $vCount = count($velocities);
        if ($vCount >= 3) {
            $vMean = array_sum($velocities) / $vCount;
            $vVariance = 0;
            foreach ($velocities as $v) {
                $vVariance += ($v - $vMean) ** 2;
            }
            $vVariance /= $vCount;
            $vStdDev = sqrt($vVariance);

            // 标准差极低 → 速度几乎恒定 → 自动化脚本
            if ($vStdDev < 0.05 && $vMean > 0.1) {
                $risk += 5;
            } elseif ($vStdDev < 0.15 && $vMean > 0.1) {
                $risk += 2;
            }

            // 2c. 瞬间最大速度异常高（> 3px/ms = 瞬间跳跃）
            $vMax = max($velocities);
            if ($vMax > 3.0) {
                $risk += 3;
            } elseif ($vMax > 1.5) {
                $risk += 1;
            }
        }

        // 2d. 无加速阶段 —— 首个速度即接近平均速度（人类有启动加速过程）
        if ($vCount >= 3 && isset($velocities[0]) && $velocities[0] > 0) {
            $vFirstFewAvg = array_sum(array_slice($velocities, 0, min(3, $vCount))) / min(3, $vCount);
            $vOverallAvg = array_sum($velocities) / $vCount;
            if ($vOverallAvg > 0 && $vFirstFewAvg >= $vOverallAvg * 0.8) {
                $risk += 2; // 启动速度就很快，缺少加速过程
            }
        }

        // 2e. 折返/回退次数 —— 人类拖拽常有微调（来回修正），机器人通常单向直达
        $directionChanges = 0;
        for ($i = 2; $i < $n; $i++) {
            $prevDir = (int)($traj[$i - 1]['x']) - (int)($traj[$i - 2]['x']);
            $currDir = (int)($traj[$i]['x']) - (int)($traj[$i - 1]['x']);
            if (($prevDir > 0 && $currDir < 0) || ($prevDir < 0 && $currDir > 0)) {
                $directionChanges++;
            }
        }
        // 有足够距离的拖拽却无任何折返
        if ($totalDist > 30 && $directionChanges === 0 && $n >= 6) {
            $risk += 2;
        }

        // 2f. x(t) 线性度 —— 程序化插值机器人 x 随时间严格线性（缓入缓出曲线缺失）
        if ($n >= 5) {
            $t0 = (int)$traj[0]['t'];
            $t1 = (int)$traj[$n - 1]['t'];
            $x0 = (int)$traj[0]['x'];
            $x1 = (int)$traj[$n - 1]['x'];
            $dt = $t1 - $t0;
            if ($dt > 0) {
                $maxDev = 0;
                for ($i = 1; $i < $n - 1; $i++) {
                    $ti = (int)$traj[$i]['t'] - $t0;
                    $expectedX = $x0 + ($x1 - $x0) * ($ti / $dt);
                    $dev = abs((int)$traj[$i]['x'] - $expectedX);
                    if ($dev > $maxDev) {
                        $maxDev = $dev;
                    }
                }
                // 严格线性 + 匀速 → 典型插值脚本；人类呈缓入缓出曲线
                if ($maxDev < 2 && ($vStdDev ?? 999) < 0.2) {
                    $risk += 3;
                } elseif ($maxDev < 4) {
                    $risk += 1;
                }
            }
        }

    } elseif ($n <= 1 && $duration > 0 && $duration < 500) {
        // 几乎无轨迹数据且快速完成 —— 高度可疑
        $risk += 4;
    }

    return min($risk, 15); // 上限 15
}

/**
 * 表单提交时校验：挑战存在、已通过且未过期（成功后清除，一次性使用）
 */
function captcha_passed(string $token): bool {
    if (function_exists('get_site_setting') && get_site_setting('captcha_debug', '0') === '1') {
        unset($_SESSION['captcha']);
        return true;
    }
    $cap = $_SESSION['captcha'] ?? null;
    if (!is_array($cap) || ($cap['token'] ?? '') !== $token) {
        return false;
    }
    // 签名绑定校验（防止跨会话重放/伪造 token）
    if (($cap['sig'] ?? '') !== captcha_token_sig($token)) {
        unset($_SESSION['captcha']);
        return false;
    }
    if (($cap['expires'] ?? 0) < time()) {
        unset($_SESSION['captcha']);
        return false;
    }
    if (empty($cap['passed'])) {
        return false;
    }
    unset($_SESSION['captcha']);
    return true;
}

/**
 * 行为特征打分（服务端二次计算，防客户端伪造）
 */
function captcha_behavior_score(array $s): int {
    $n        = isset($s['samples'])  ? min((int)$s['samples'], 3000) : 0;
    $dist     = isset($s['dist'])     ? min((int)$s['dist'], 50000) : 0;
    $variance = isset($s['variance']) ? min((int)$s['variance'], 10000) : 0;
    $elapsed  = isset($s['elapsed'])  ? (int)$s['elapsed'] : 0;
    $clicks   = isset($s['clicks'])   ? min((int)$s['clicks'], 50) : 0;
    $keys     = isset($s['keys'])     ? min((int)$s['keys'], 100) : 0;
    $noPointer = !empty($s['noPointer']);

    $score = 0;
    if ($n >= 10 && $dist >= 300) $score += 2;
    if ($variance >= 40) $score += 1;
    if ($elapsed >= 800 && $elapsed <= 60000) $score += 1;
    if ($clicks >= 1) $score += 1;
    if ($keys >= 1) $score += 1;
    if ($noPointer && $n < 10) $score = min($score, 1);

    // 恢复轨迹加分：鼠标起止不在同一侧，且有一定停顿/折返
    if (isset($s['recovery']) && $s['recovery'] > 0) {
        $score += (int)$s['recovery'];
    }
    // 加速度多样本加分：快速移动后减速停顿，再反向
    if (isset($s['accel_changes']) && $s['accel_changes'] >= 2) {
        $score += 1;
    }

    return $score;
}

/* ==================== 触发式验证配置 ==================== */

/**
 * 触发模式：
 * - always：始终显示验证码（推荐，用户体验最佳）
 * - suspicious：检测到可疑行为时触发（默认）
 * - high_risk：高风险操作时触发（发帖/私信）
 */
function captcha_trigger_mode(): string {
    if (!function_exists('get_site_setting')) {
        return 'always'; // 默认改为 always，确保登录注册都能看到
    }
    $v = get_site_setting('captcha_trigger_mode', 'always'); // 改默认值为 always
    return in_array($v, ['always', 'suspicious', 'high_risk'], true) ? $v : 'always';
}

/**
 * 跳过验证的冷却期（秒）：用户刚通过验证后，此时间内不再要求
 */
function captcha_skip_cooldown(): int {
    if (!function_exists('get_site_setting')) {
        return 600;
    }
    $v = (int)get_site_setting('captcha_skip_cooldown', '600');
    return max(60, min(86400, $v));
}

/**
 * 判断当前请求是否需要触发人机验证
 *
 * 触发条件：
 * - 触发模式 = always
 * - 触发模式 = high_risk 且当前路由是高敏感操作
 * - 触发模式 = suspicious 且检测到异常行为信号
 */
function should_trigger_captcha(string $action = ''): bool {
    if (!captcha_enabled()) {
        return false;
    }
    $mode = captcha_trigger_mode();
    if ($mode === 'always') {
        return true;
    }

    $highRiskRoutes = ['new_post', 'post', 'pm', 'report', 'appeal', 'forgot_password', 'reset_password'];
    if ($mode === 'high_risk' && in_array($action, $highRiskRoutes, true)) {
        return true;
    }

    // suspicious：检查 IP / 会话 / 设备异常信号
    return captcha_is_suspicious();
}

/**
 * 判断当前会话是否呈现可疑行为特征
 */
function captcha_is_suspicious(): bool {
    $key = 'captcha_signals';
    $sig = $_SESSION[$key] ?? null;
    if (!is_array($sig)) {
        return true;
    }

    // 登录失败次数
    if ((int)($sig['login_fails'] ?? 0) >= 3) {
        return true;
    }

    // 提交频率
    $submits = (int)($sig['submits'] ?? 0);
    $firstSubmit = (int)($sig['first_submit'] ?? 0);
    if ($submits >= 6 && $firstSubmit > 0 && (time() - $firstSubmit) < 60) {
        return true;
    }

    // 无指针设备且无有效鼠标样本
    if (!empty($sig['no_pointer']) && (int)($sig['mouse_samples'] ?? 0) < 5) {
        return true;
    }

    // IP 请求频率
    $ipKey = 'captcha_ip_' . ($_SERVER['REMOTE_ADDR'] ?? '');
    $ipHits = (int)($_SESSION[$ipKey] ?? 0);
    if ($ipHits >= 20 && (time() - ($sig['first_hit'] ?? time())) < 60) {
        return true;
    }

    return false;
}

/**
 * 记录用户行为信号（供触发检测使用）
 */
function captcha_record_signal(string $type, $value = null): void {
    if (session_status() === PHP_SESSION_NONE) {
        return;
    }
    if (!isset($_SESSION['captcha_signals'])) {
        $_SESSION['captcha_signals'] = [
            'login_fails'   => 0,
            'submits'       => 0,
            'first_submit'  => 0,
            'no_pointer'    => false,
            'mouse_samples' => 0,
            'first_hit'     => time(),
        ];
    }
    $sig = &$_SESSION['captcha_signals'];
    switch ($type) {
        case 'login_fail':
            $sig['login_fails'] = ($sig['login_fails'] ?? 0) + 1;
            break;
        case 'submit':
            $sig['submits'] = ($sig['submits'] ?? 0) + 1;
            if (empty($sig['first_submit'])) {
                $sig['first_submit'] = time();
            }
            break;
        case 'mouse_move':
            $sig['mouse_samples'] = ($sig['mouse_samples'] ?? 0) + 1;
            break;
        case 'no_pointer':
            $sig['no_pointer'] = true;
            break;
        case 'clear':
            $_SESSION['captcha_signals'] = [
                'login_fails'   => 0,
                'submits'       => 0,
                'first_submit'  => 0,
                'no_pointer'    => false,
                'mouse_samples' => 0,
                'first_hit'     => time(),
            ];
            break;
    }
}

/**
 * 清除行为信号（验证通过后调用）
 */
function captcha_clear_signals(): void {
    captcha_record_signal('clear');
}

/**
 * 记录 IP 请求频率
 */
function captcha_record_ip_hit(): void {
    if (session_status() === PHP_SESSION_NONE) {
        return;
    }
    $ipKey = 'captcha_ip_' . ($_SERVER['REMOTE_ADDR'] ?? '');
    $_SESSION[$ipKey] = ($_SESSION[$ipKey] ?? 0) + 1;
}
