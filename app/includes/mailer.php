<?php
/**
 * 云界论坛 - 轻量级 SMTP 邮件发送器
 *
 * 不依赖外部库，原生 fsockopen 实现，支持无加密 / SSL / TLS。
 */

/**
 * 读取全局 SMTP 配置并发送一封邮件
 *
 * @param string $to      收件人邮箱
 * @param string $toName  收件人名称
 * @param string $subject 主题
 * @param string $body    HTML 正文
 * @param string $type    邮件类型：verify|reset|appeal|ban|test|notification|other（用于统计分类）
 * @return array ['success'=>bool, 'error'=>?string]
 */
function send_mail(string $to, string $toName, string $subject, string $body, string $type = 'other'): array {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log_db($to, $toName, $subject, $type, 'failed', t('mail_invalid_recipient_log', '收件人邮箱无效'));
        return ['success' => false, 'error' => t('mail_invalid_recipient', '收件人邮箱无效。')];
    }

    $enabled = defined('SMTP_ENABLED') ? SMTP_ENABLED : false;
    if (!$enabled) {
        mail_log_db($to, $toName, $subject, $type, 'failed', t('mail_not_enabled_log', '邮件功能未启用'));
        return ['success' => false, 'error' => t('mail_not_enabled', '邮件功能未启用。')];
    }

    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : '';
    $from = defined('SMTP_FROM') ? SMTP_FROM : $user;
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : SITE_NAME;

    if (empty($host) || empty($from)) {
        mail_log_db($to, $toName, $subject, $type, 'failed', t('mail_smtp_config_incomplete_log', 'SMTP 配置不完整'));
        return ['success' => false, 'error' => t('mail_smtp_config_incomplete', 'SMTP 配置不完整。')];
    }

    try {
        $client = new SmtpClient($host, $port, $user, $pass, $encryption);
        $result = $client->send($from, $fromName, $to, $toName, $subject, $body);
        // 严格依据 SMTP 服务器返回的结果记录日志：
        // - 成功：SMTP DATA 命令返回 250，且 QUIT 正常
        // - 失败：连接失败、认证失败、RCPT 被拒、DATA 被拒等任何 SMTP 错误
        //   错误信息中包含 SMTP 服务器返回的原始响应码与文本
        $logError = $result['success'] ? '' : ($result['error'] ?? t('mail_unknown_error', '未知错误'));
        $messageId = $result['success'] ? ($result['message_id'] ?? '') : '';
        mail_log_db($to, $toName, $subject, $type, $result['success'] ? 'success' : 'failed', $logError, $messageId);
        return $result;
    } catch (Exception $e) {
        error_log('SMTP 发送失败：' . $e->getMessage());
        mail_log_db($to, $toName, $subject, $type, 'failed', $e->getMessage(), '');
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 将邮件发送记录写入数据库（用于邮件中心统计）
 *
 * @param string $messageId 邮件 Message-ID（用于退信匹配），仅成功发送时记录
 */
function mail_log_db(string $to, string $toName, string $subject, string $type, string $status, string $error = '', string $messageId = ''): void {
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO mail_logs (recipient, recipient_name, subject, type, status, error_message, message_id) VALUES (:to, :name, :subject, :type, :status, :error, :message_id)");
        $stmt->execute([
            ':to'         => mb_substr($to, 0, 255),
            ':name'       => mb_substr($toName, 0, 100),
            ':subject'    => mb_substr($subject, 0, 255),
            ':type'       => in_array($type, ['verify', 'reset', 'appeal', 'ban', 'test', 'notification', 'other'], true) ? $type : 'other',
            ':status'     => in_array($status, ['success', 'failed'], true) ? $status : 'failed',
            ':error'      => $error,
            ':message_id' => $messageId !== '' ? mb_substr($messageId, 0, 255) : null,
        ]);
    } catch (Exception $e) {
        // 日志记录失败不应影响主流程
        error_log('mail_log_db 失败：' . $e->getMessage());
    }
}

class SmtpClient {
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $username;
    /** @var string */
    private $password;
    /** @var string */
    private $encryption;
    private $socket = null;
    /** @var string 最近一次 SMTP 服务器返回的完整响应（含状态码与文本） */
    private $lastResponse = '';

    public function __construct(string $host, int $port, string $username, string $password, string $encryption = '') {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = strtolower($encryption);
    }

    public function send(string $from, string $fromName, string $to, string $toName, string $subject, string $body): array {
        $timeout = 10;
        $address = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $this->socket = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            return ['success' => false, 'error' => t('mail_cannot_connect', '无法连接 SMTP 服务器：{errstr} ({errno})', ['errstr' => $errstr, 'errno' => $errno])];
        }

        stream_set_timeout($this->socket, $timeout);

        try {
            $this->expect(220, '连接问候');
            $this->command('EHLO ' . gethostname(), 250, 'EHLO');

            // STARTTLS
            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220, 'STARTTLS');
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                $ok = @stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
                if (!$ok) {
                    throw new Exception(t('mail_starttls_failed', 'STARTTLS 加密协商失败。'));
                }
                $this->command('EHLO ' . gethostname(), 250, 'EHLO(TLS)');
            }

            // AUTH LOGIN
            if ($this->username !== '' && $this->password !== '') {
                $this->command('AUTH LOGIN', 334, 'AUTH LOGIN');
                $this->command(base64_encode($this->username), 334, 'AUTH 用户名');
                $this->command(base64_encode($this->password), 235, 'AUTH 密码');
            }

            $this->command('MAIL FROM:<' . $from . '>', 250, 'MAIL FROM');
            $this->command('RCPT TO:<' . $to . '>', 250, 'RCPT TO');
            $this->command('DATA', 354, 'DATA');

            $messageId = md5(uniqid('yj', true)) . '@' . $this->host;
            $boundary = '----=_Part_' . md5(uniqid());
            $date = date('r');

            $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $fromNameEncoded = $fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?=' : $from;
            $toNameEncoded = $toName !== '' ? '=?UTF-8?B?' . base64_encode($toName) . '?=' : $to;

            $data = "Date: $date\r\n";
            $data .= "From: \"$fromNameEncoded\" <$from>\r\n";
            $data .= "To: \"$toNameEncoded\" <$to>\r\n";
            $data .= "Subject: $subjectEncoded\r\n";
            $data .= "Message-ID: <$messageId>\r\n";
            $data .= "MIME-Version: 1.0\r\n";
            $data .= "Content-Type: text/html; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: base64\r\n";
            $data .= "\r\n";
            $data .= chunk_split(base64_encode($body), 76, "\r\n");
            $data .= "\r\n.\r\n";

            // DATA 结束后，SMTP 服务器返回 250 表示邮件已被接收
            $this->command($data, 250, 'DATA 内容');

            // QUIT 命令失败不影响邮件投递结果（邮件已被服务器接收）
            try {
                $this->command('QUIT', 221, 'QUIT');
            } catch (Exception $quitEx) {
                // 忽略 QUIT 错误
            }

            fclose($this->socket);
            $this->socket = null;
            // 返回 message_id（不含尖括号），用于退信匹配
            return ['success' => true, 'error' => null, 'message_id' => $messageId];
        } catch (Exception $e) {
            if ($this->socket) {
                @fwrite($this->socket, "QUIT\r\n");
                @fclose($this->socket);
                $this->socket = null;
            }
            return ['success' => false, 'error' => $e->getMessage(), 'message_id' => ''];
        }
    }

    /**
     * 发送 SMTP 命令并校验服务器响应
     *
     * @param string $cmd      SMTP 命令
     * @param int    $expected 期望的响应码
     * @param string $stage    当前阶段名称（用于错误信息定位）
     */
    private function command(string $cmd, int $expected, string $stage = ''): void {
        if ($this->socket === null) {
            throw new Exception(t('mail_smtp_closed', 'SMTP 连接已关闭。'));
        }
        $written = @fwrite($this->socket, $cmd . "\r\n");
        if ($written === false) {
            throw new \RuntimeException(t('mail_write_failed', 'SMTP 写入失败：连接可能已断开'));
        }
        $this->expect($expected, $stage);
    }

    /**
     * 读取并校验 SMTP 服务器响应
     *
     * @param int    $code  期望的响应码
     * @param string $stage 当前阶段名称（用于错误信息定位）
     */
    private function expect(int $code, string $stage = ''): void {
        if ($this->socket === null) {
            throw new Exception(t('mail_smtp_closed', 'SMTP 连接已关闭。'));
        }
        $response = '';
        $start = time();
        while (!feof($this->socket)) {
            $line = @fgets($this->socket, 515);
            if ($line === false) {
                if (time() - $start > 10) {
                    throw new Exception(t('mail_response_timeout', 'SMTP 服务器响应超时。') . ($stage !== '' ? "[$stage]" : ''));
                }
                continue;
            }
            $response .= $line;
            // SMTP 多行响应以 "code-" 继续，以 "code " 结束
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        $this->lastResponse = trim($response);

        if (!preg_match('/^' . $code . '[ -]/', $response)) {
            // 提取 SMTP 服务器返回的状态码
            $serverCode = '';
            if (preg_match('/^(\d{3})/', $response, $m)) {
                $serverCode = $m[1];
            }
            // 构造详细的错误信息，包含阶段、状态码、服务器原文
            $errorMsg = 'SMTP';
            if ($stage !== '') {
                $errorMsg .= '[' . $stage . ']';
            }
            if ($serverCode !== '') {
                $errorMsg .= ' ' . $serverCode;
            }
            $errorMsg .= t('mail_error_colon', '：') . $this->lastResponse;
            throw new Exception($errorMsg);
        }
    }
}

/**
 * 生成随机数字验证码
 */
function generate_email_code(int $length = 6): string {
    $min = (int)pow(10, $length - 1);
    $max = (int)pow(10, $length) - 1;
    return (string)random_int($min, $max);
}

/**
 * 渲染统一的邮件 HTML 外壳
 *
 * 所有通过 send_mail() 发出的邮件都应使用本函数包裹正文，
 * 以保证品牌视觉一致、在各大邮箱客户端中稳定显示。
 *
 * @param string $title       邮件标题（显示在邮件顶部 hero 区）
 * @param string $bodyHtml    邮件正文 HTML（不含外壳）
 * @param array  $options     可选：['subject'=>主题, 'action_url'=>按钮链接, 'action_text'=>按钮文字, 'footer'=>附加页脚]
 * @return string             完整的 HTML 邮件内容
 */
function render_email_template(string $title, string $bodyHtml, array $options = []): string {
    $siteName = defined('SITE_NAME') ? SITE_NAME : APP_NAME;
    // 优先使用 SITE_URL，为空时自动推导完整 URL（含协议+主机），避免邮件中链接不可用
    $siteUrl  = function_exists('site_absolute_url') ? site_absolute_url() : (defined('SITE_URL') && SITE_URL !== '' ? SITE_URL : '/');
    $year     = date('Y');

    $subject     = $options['subject'] ?? $title;
    $actionUrl   = $options['action_url'] ?? '';
    $actionText  = $options['action_text'] ?? '';
    $extraFooter = $options['footer'] ?? '';

    // 没有指定按钮链接时，底部"备用链接"使用站点首页地址
    $fallbackUrl = $actionUrl !== '' ? $actionUrl : $siteUrl;

    // 主按钮（可选）
    $actionBlock = '';
    if ($actionUrl !== '' && $actionText !== '') {
        $actionBlock = '
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0 8px;">
            <tr>
                <td align="center">
                    <a href="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener"
                       style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;background:linear-gradient(135deg,#6366f1 0%,#4f46e5 100%);box-shadow:0 4px 14px rgba(99,102,241,0.35);">
                        ' . htmlspecialchars($actionText, ENT_QUOTES, 'UTF-8') . '
                    </a>
                </td>
            </tr>
        </table>';
    }

    // 内联样式（邮箱客户端对 <style> 支持较差，全部使用 inline style）
    return '<!DOCTYPE html>
<html lang="zh-CN" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"PingFang SC","Microsoft YaHei",sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;min-width:100%;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
<!-- 顶部品牌条 -->
<tr>
<td style="background:linear-gradient(135deg,#6366f1 0%,#4f46e5 100%);padding:28px 32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.3px;">
' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '
</td>
<td align="right" style="color:rgba(255,255,255,0.78);font-size:12px;">
' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '
</td>
</tr>
</table>
</td>
</tr>
<!-- 标题 -->
<tr>
<td style="padding:32px 32px 4px;">
<h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#18181b;letter-spacing:-0.01em;line-height:1.4;">
' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '
</h1>
<div style="width:40px;height:3px;border-radius:2px;background:linear-gradient(90deg,#6366f1,#4f46e5);"></div>
</td>
</tr>
<!-- 正文 -->
<tr>
<td style="padding:20px 32px 8px;font-size:15px;line-height:1.7;color:#3f3f46;">
' . $bodyHtml . '
</td>
</tr>
<!-- 操作按钮 -->
<tr>
<td style="padding:0 32px;">
' . $actionBlock . '
</td>
</tr>
<!-- 提示 -->
<tr>
<td style="padding:16px 32px 24px;font-size:12px;line-height:1.6;color:#a1a1aa;">
' . t('mail_btn_not_working', '如按钮无法点击，请复制以下链接到浏览器打开：') . '<br>
<span style="color:#71717a;word-break:break-all;">' . htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8') . '</span>
</td>
</tr>
<!-- 页脚 -->
<tr>
<td style="padding:20px 32px 28px;background:#fafafa;border-top:1px solid #f4f4f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="font-size:12px;color:#a1a1aa;line-height:1.6;">
' . t('mail_auto_sent_notice', '本邮件由系统自动发送，请勿直接回复。') . '<br>
© ' . $year . ' ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . ' · ' . t('mail_all_rights_reserved', '保留所有权利') . '
' . ($extraFooter !== '' ? '<br>' . $extraFooter : '') . '
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';
}
