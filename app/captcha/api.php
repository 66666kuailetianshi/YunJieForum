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
$resp   = ['enabled' => captcha_enabled()];

if (!captcha_enabled()) {
    echo json_encode($resp);
    exit;
}

try {
    if ($action === 'get') {
        // 调试模式：前端不渲染验证组件（captcha_passed 也已绕过，双重保障）
        if (function_exists('get_site_setting') && get_site_setting('captcha_debug', '0') === '1') {
            $resp['enabled'] = false;
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
        $resp = array_merge($resp, captcha_check($in['token'] ?? '', $signals));
    } elseif ($action === 'slider') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        $resp = array_merge($resp, captcha_slider_verify($in['token'] ?? '', $in['x'] ?? null));
    } elseif ($action === 'click') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        $resp = array_merge($resp, captcha_click_verify($in['token'] ?? '', $in['seq'] ?? null));
    } else {
        $resp['error'] = 'unknown_action';
    }
} catch (Throwable $e) {
    $resp['error'] = 'server_error';
}

echo json_encode($resp);