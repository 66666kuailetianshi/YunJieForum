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
    $_SESSION['captcha'] = [
        'token'    => $token,
        'expires'  => time() + CAPTCHA_TTL,
        'attempts' => 0,
        'passed'   => false,
        'mode'     => 'behavior',
        'gap'      => null,
    ];
    return [
        'token'       => $token,
        'width'       => SLIDER_CAPTCHA_WIDTH,
        'height'      => SLIDER_CAPTCHA_HEIGHT,
        'piece_width' => SLIDER_CAPTCHA_PIECE + SLIDER_CAPTCHA_TAB,
    ];
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

    // 换一张：跳过行为打分，直接重新生成挑战
    $skipBehavior = $refresh || captcha_display() === 'popup';
    if (!$skipBehavior && captcha_behavior_score($signals) >= captcha_pass_score()) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true, 'method' => 'auto'];
    }

    $style = captcha_style();
    if ($style === 'auto') {
        $styles = ['slider', 'click', 'swap'];
        $style = $styles[random_int(0, count($styles) - 1)];
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
    $cap['tol']  = captcha_slider_tolerance();
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

    // 初始顺序：[0, 1, 2, 3] 等
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
    $cap['answer']     = $order; // 正确的排列顺序
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
function captcha_swap_verify(string $token, array $order): array {
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

    $expected = $cap['answer'] ?? [];
    $got = array_values($order);
    if ($got === $expected) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true];
    }

    $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
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
 * 验证显示方式：内嵌 (inline) / 弹窗 (popup) / 触发 (trigger)，默认内嵌
 */
function captcha_display(): string {
    if (!function_exists('get_site_setting')) {
        return 'inline';
    }
    $v = get_site_setting('captcha_display', 'inline');
    return in_array($v, ['inline', 'popup', 'trigger'], true) ? $v : 'inline';
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
    return ['easy' => 12, 'normal' => 8, 'hard' => 5][captcha_difficulty()] ?? 8;
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
function captcha_click_verify(string $token, $seq): array {
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

    $expected = array_map('strval', $cap['answer'] ?? []);
    $got      = is_array($seq) ? array_values(array_map('strval', $seq)) : [];
    if ($got === $expected) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true];
    }

    $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
    $_SESSION['captcha'] = $cap;
    return ['ok' => false];
}

/**
 * 滑块拖拽松手后的即时校验：|x - gap| <= 容差 则标记已通过
 */
function captcha_slider_verify(string $token, $x): array {
    $cap = $_SESSION['captcha'] ?? null;
    if (!is_array($cap) || ($cap['token'] ?? '') !== $token) {
        return ['ok' => false];
    }
    if (($cap['expires'] ?? 0) < time()) {
        unset($_SESSION['captcha']);
        return ['ok' => false];
    }
    if (($cap['mode'] ?? '') !== 'slider') {
        return ['ok' => false];
    }

    $x = (int)$x;
    if (abs($x - (int)$cap['gap']) <= (int)($cap['tol'] ?? SLIDER_CAPTCHA_TOLERANCE)) {
        $cap['passed'] = true;
        $_SESSION['captcha'] = $cap;
        return ['ok' => true];
    }

    $cap['attempts'] = ($cap['attempts'] ?? 0) + 1;
    $_SESSION['captcha'] = $cap;
    return ['ok' => false];
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
