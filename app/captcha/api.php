<?php
/**
 * 云界论坛 - 人机验证 API（独立模块）
 *
 * 动作（GET/POST 参数 action）：
 *   get     初始化挑战，返回 { enabled, token, width, height, piece_width }
 *   check   提交行为特征，返回 { ok:true } 或 { ok:false, challenge:'slider', bg, piece, ... }
 *   slider  提交滑块拖动位置 x，返回 { ok:true/false }
 *
 * 访问：/index.php?route=api/captcha（或 /api/captcha）
 */
require_once APP_ROOT . 'app/includes/functions.php';
require_once APP_ROOT . 'app/includes/db.php';
require_once APP_ROOT . 'app/captcha/core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
$debug  = function_exists('get_site_setting') && get_site_setting('captcha_debug', '0') === '1';

// 只在 'get' 动作时返回 display 字段（其他动作不需要重复传递）
$resp   = ['enabled' => captcha_enabled()];
if ($action === 'get') {
    $resp['display'] = captcha_display();
}

if ($debug) {
    $resp['debug'] = [
        'action'  => $action,
        'gd'      => function_exists('imagecreatetruecolor'),
        'webp'    => function_exists('imagecreatefromwebp'),
        'bg_api'  => CAPTCHA_BG_API,
        'time'    => date('Y-m-d H:i:s'),
    ];
}

if (!captcha_enabled()) {
    echo json_encode($resp);
    exit;
}

try {
    if ($action === 'get') {
        // 调试模式：正常返回挑战数据，但额外附带调试信息
        if (function_exists('get_site_setting') && get_site_setting('captcha_debug', '0') === '1') {
            $resp = array_merge($resp, captcha_new());
            $resp['debug']['mode'] = 'debug';
        } else {
            $resp = array_merge($resp, captcha_new());
        }
    } elseif ($action === 'check') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        $signals = is_array($in['signals'] ?? null) ? $in['signals'] : [];
        $resp = array_merge($resp, captcha_check($in['token'] ?? '', $signals, !empty($in['refresh'])));
    } elseif ($action === 'slider') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        $resp = array_merge($resp, captcha_slider_verify(
            $in['token'] ?? '',
            $in['x'] ?? null,
            (int)($in['duration'] ?? 0),
            is_array($in['traj'] ?? null) ? $in['traj'] : [],
            isset($in['pow_nonce']) ? (string)$in['pow_nonce'] : null
        ));
    } elseif ($action === 'click') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        $resp = array_merge($resp, captcha_click_verify(
            $in['token'] ?? '',
            $in['seq'] ?? null,
            isset($in['pow_nonce']) ? (string)$in['pow_nonce'] : null
        ));
    } elseif ($action === 'swap') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        $order = is_array($in['order'] ?? null) ? $in['order'] : [];
        $resp = array_merge($resp, captcha_swap_verify(
            $in['token'] ?? '',
            $order,
            isset($in['pow_nonce']) ? (string)$in['pow_nonce'] : null
        ));
    } else {
        $resp['error'] = 'unknown_action';
    }
} catch (Throwable $e) {
    $resp['error'] = 'server_error';
    if ($debug) {
        $resp['debug']['exception'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    }
}

echo json_encode($resp);