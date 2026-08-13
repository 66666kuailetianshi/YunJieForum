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
        // 环境诊断：随 get 响应返回，便于 F12 快速定位「点击就失败 / 验证码状态丢失」类问题
        $resp['diag'] = captcha_diag_env('get');
    } elseif ($action === 'check') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        // 在调用校验函数之前记录会话状态，用于判断 invalid 是否源于会话未保持
        $capPresentBefore = isset($_SESSION['captcha']);
        $capTokenBefore   = ($_SESSION['captcha']['token'] ?? null);
        $tokenReceived    = ($in['token'] ?? null);
        $signals = is_array($in['signals'] ?? null) ? $in['signals'] : [];
        $resp = array_merge($resp, captcha_check($in['token'] ?? '', $signals, !empty($in['refresh'])));
        $resp['diag'] = captcha_diag_env('check');
        $resp['diag']['captcha_present_before'] = $capPresentBefore;
        $resp['diag']['captcha_token_before']   = $capTokenBefore;
        $resp['diag']['token_received']         = $tokenReceived;
        // token 是否与会话中一致：为 false 时基本可判定为「会话未保持 / cookie 未携带」
        $resp['diag']['token_matched']          = $capPresentBefore && $capTokenBefore === $tokenReceived;
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
    } elseif ($action === 'letter') {
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw ?: '', true);
        if (!is_array($in)) {
            $in = [];
        }
        $resp = array_merge($resp, captcha_letter_verify(
            $in['token'] ?? '',
            $in['input'] ?? '',
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
    // 非调试模式也返回异常概要（类型 + 行号），让管理员可直接定位「点击就失败」的异常源；
    // 完整消息与服务器文件路径仅在调试模式（captcha_debug）下输出，避免泄露路径。
    $resp['message'] = '服务器内部错误：' . get_class($e) . '（第 ' . $e->getLine() . ' 行）';
    if ($debug) {
        $resp['debug']['exception'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    }
    if (function_exists('captcha_diag_env')) {
        $resp['diag'] = captcha_diag_env($action);
    }
}

echo json_encode($resp);