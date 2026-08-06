<?php
/**
 * 云界论坛 - 邮件退信处理器
 *
 * 通过 POP3/IMAP 协议连接退信邮箱，读取退信邮件，解析失败原因，
 * 通过 Message-ID 或收件人地址匹配数据库中的发送日志，更新状态为"已退信"。
 *
 * 支持的退信格式：
 *  - 标准 DSN (Delivery Status Notification) - RFC 3464
 *  - QQ 邮箱退信
 *  - 网易 163/126 退信
 *  - Gmail 退信
 *  - 阿里云邮 / 腾讯企业邮退信
 *  - 通用退信（基于关键字识别）
 *
 * 依赖：PHP imap 扩展（IMAP/POP3/NNTTP 协议统一封装）
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class BounceProcessor {
    /** @var PDO */
    private $db;
    /** @var array */
    private $config;
    private $imapStream = null;

    public function __construct() {
        $this->db = get_db();
        $this->loadConfig();
    }

    /**
     * 加载退信处理配置
     */
    private function loadConfig(): void {
        $stmt = $this->db->query("SELECT * FROM mail_bounce_config WHERE id = 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config) {
            $config = [
                'enabled' => 0,
                'protocol' => 'imap',
                'host' => '',
                'port' => 993,
                'encryption' => 'ssl',
                'username' => '',
                'password' => '',
                'mailbox' => 'INBOX',
                'auto_check' => 1,
            ];
        }
        $this->config = $config;
    }

    /**
     * 保存退信处理配置
     */
    public function saveConfig(array $data): void {
        $stmt = $this->db->prepare("UPDATE mail_bounce_config SET
            enabled = :enabled,
            protocol = :protocol,
            host = :host,
            port = :port,
            encryption = :encryption,
            username = :username,
            password = :password,
            mailbox = :mailbox,
            auto_check = :auto_check,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = 1");
        $stmt->execute([
            ':enabled'    => !empty($data['enabled']) ? 1 : 0,
            ':protocol'   => in_array($data['protocol'] ?? '', ['imap', 'pop3'], true) ? $data['protocol'] : 'imap',
            ':host'       => trim($data['host'] ?? ''),
            ':port'       => (int)($data['port'] ?? 993),
            ':encryption' => in_array($data['encryption'] ?? '', ['', 'ssl', 'tls'], true) ? $data['encryption'] : 'ssl',
            ':username'   => trim($data['username'] ?? ''),
            ':password'   => $data['password'] ?? '',
            ':mailbox'    => trim($data['mailbox'] ?? 'INBOX') ?: 'INBOX',
            ':auto_check' => !empty($data['auto_check']) ? 1 : 0,
        ]);
        $this->loadConfig();
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * 测试退信邮箱连接
     *
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function testConnection(): array {
        if (!$this->isImapAvailable()) {
            return ['success' => false, 'message' => t('bounce_no_imap_ext', 'PHP 未启用 imap 扩展，无法连接退信邮箱。')];
        }
        if (empty($this->config['host']) || empty($this->config['username'])) {
            return ['success' => false, 'message' => t('bounce_empty_host_user', '退信邮箱主机或用户名为空。')];
        }

        $mailbox = $this->buildMailboxString();
        $stream = @imap_open($mailbox, $this->config['username'], $this->config['password'], OP_HALFOPEN, 1);

        if (!$stream) {
            $error = imap_last_error();
            return ['success' => false, 'message' => t('bounce_connect_failed', '连接失败：') . ($error ?: t('bounce_unknown_error', '未知错误'))];
        }

        $mailboxCount = @imap_num_msg($stream);
        @imap_close($stream);
        return ['success' => true, 'message' => t('bounce_connect_ok', '连接成功，邮箱共有 {count} 封邮件。', ['count' => $mailboxCount])];
    }

    /**
     * 检查并处理退信邮件
     *
     * @param int $maxMessages 单次处理最大邮件数（默认 50）
     * @return array ['success'=>bool, 'found'=>int, 'processed'=>int, 'error'=>?string, 'details'=>array]
     */
    public function processBounces(int $maxMessages = 50): array {
        if (empty($this->config['enabled'])) {
            return ['success' => false, 'found' => 0, 'processed' => 0, 'error' => t('bounce_not_enabled', '退信处理未启用'), 'details' => []];
        }
        if (!$this->isImapAvailable()) {
            return ['success' => false, 'found' => 0, 'processed' => 0, 'error' => t('bounce_no_imap_short', 'PHP 未启用 imap 扩展'), 'details' => []];
        }

        $result = [
            'success'   => false,
            'found'     => 0,
            'processed' => 0,
            'error'     => null,
            'details'   => [],
        ];

        $mailbox = $this->buildMailboxString();
        $this->imapStream = @imap_open($mailbox, $this->config['username'], $this->config['password'], 0, 1);

        if (!$this->imapStream) {
            $error = imap_last_error();
            $result['error'] = t('bounce_open_failed', '连接退信邮箱失败：') . ($error ?: t('bounce_unknown_error', '未知错误'));
            $this->logBounceCheck(0, 0, $result['error']);
            return $result;
        }

        try {
            $total = @imap_num_msg($this->imapStream);
            $result['found'] = $total;

            // 从最新的邮件开始处理
            $start = max(1, $total - $maxMessages + 1);
            $processed = 0;
            $details = [];

            for ($i = $total; $i >= $start; $i--) {
                $bounceInfo = $this->parseBounceEmail($i);
                if ($bounceInfo !== null) {
                    $matched = $this->matchAndUpdateLog($bounceInfo);
                    if ($matched) {
                        $processed++;
                        $details[] = [
                            'recipient' => $bounceInfo['recipient'],
                            'reason'    => mb_substr($bounceInfo['reason'], 0, 100),
                            'type'      => $bounceInfo['type'],
                            'matched'   => true,
                        ];
                    }
                }
            }

            $result['success'] = true;
            $result['processed'] = $processed;
            $result['details'] = $details;

            // 更新最后检查时间
            $this->db->prepare("UPDATE mail_bounce_config SET last_check = CURRENT_TIMESTAMP, last_check_count = :count WHERE id = 1")
                ->execute([':count' => $processed]);

            $this->logBounceCheck($total, $processed, '', $details);

        } catch (Exception $e) {
            $result['error'] = t('bounce_process_exception', '处理退信时异常：') . $e->getMessage();
            $this->logBounceCheck($result['found'], $result['processed'], $result['error']);
        } finally {
            if ($this->imapStream) {
                @imap_close($this->imapStream);
                $this->imapStream = null;
            }
        }

        return $result;
    }

    /**
     * 解析单封退信邮件
     *
     * @param int $msgNo 邮件序号
     * @return array|null ['recipient'=>string, 'reason'=>string, 'type'=>'hard'|'soft', 'message_id'=>string]
     */
    private function parseBounceEmail(int $msgNo): ?array {
        $headers = @imap_headerinfo($this->imapStream, $msgNo);
        if (!$headers) {
            return null;
        }

        $subject = isset($headers->subject) ? $this->decodeMimeHeader($headers->subject) : '';
        $fromAddress = '';
        if (isset($headers->from[0])) {
            $fromAddress = ($headers->from[0]->mailbox ?? '') . '@' . ($headers->from[0]->host ?? '');
        }

        // 退信邮件识别：通过主题、发件人、邮件内容
        $isBounce = $this->isBounceEmail($subject, $fromAddress);
        if (!$isBounce) {
            // 进一步检查邮件内容
            $body = $this->getEmailBody($msgNo);
            if (!$this->isBounceByContent($body)) {
                return null;
            }
        }

        // 获取完整邮件内容
        $body = $this->getEmailBody($msgNo);
        $fullHeaders = @imap_fetchheader($this->imapStream, $msgNo, FT_PREFETCHTEXT);
        $rawEmail = $fullHeaders . $body;

        // 解析退信信息
        return $this->extractBounceInfo($rawEmail, $subject, $body);
    }

    /**
     * 通过主题和发件人判断是否为退信邮件
     */
    private function isBounceEmail(string $subject, string $fromAddress): bool {
        $subjectLower = mb_strtolower($subject);
        $fromLower = mb_strtolower($fromAddress);

        // 常见退信发件人模式
        $bounceSenders = [
            'mail delivery', 'postmaster', 'mailer-daemon', 'mailmaster',
            'bounce', 'no-reply', 'noreply', 'auto-reply', 'mailmaster@',
            'mmdr', 'mail delivery subsystem', t('common_b600be','邮件投递'), t('common_be4326','系统退信'),
        ];
        foreach ($bounceSenders as $sender) {
            if (strpos($fromLower, $sender) !== false) {
                return true;
            }
        }

        // 常见退信主题
        $bounceSubjects = [
            'delivery status notification', 'mail delivery failed',
            'returned mail', 'undeliverable', 'failure notice',
            'bounce', t('common_32dfea','邮件投递失败'), t('common_f8455e','退信'), t('common_e767d3','发送失败'), t('common_23c072','无法投递'),
            'permanent error', 'delivery failure', 'message you sent was undeliverable',
        ];
        foreach ($bounceSubjects as $subj) {
            if (strpos($subjectLower, $subj) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 通过邮件内容判断是否为退信
     */
    private function isBounceByContent(string $body): bool {
        $bodyLower = mb_strtolower($body);
        $keywords = [
            'delivery status notification',
            'mail delivery failed',
            'permanent fatal error',
            'the following recipients',
            'could not be delivered',
            t('common_907cf2','邮件无法投递'),
            t('common_513b04','投递失败'),
            t('common_f01c7f','退信原因'),
            t('common_b4ad97','无法送达'),
            'mailbox unavailable',
            'user not found',
            'no such user',
            'mailbox not found',
            'recipient address rejected',
            '5.1.1', '5.2.1', '5.2.2', '5.4.4', '5.4.6', '5.7.1',
        ];
        foreach ($keywords as $kw) {
            if (strpos($bodyLower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 从退信邮件中提取退信信息
     */
    private function extractBounceInfo(string $rawEmail, string $subject, string $body): ?array {
        $recipient = '';
        $reason = '';
        $type = 'hard';
        $messageId = '';

        // 1. 提取原始邮件的 Message-ID（用于精确匹配）
        if (preg_match_all('/Message-ID:\s*<([^>]+)>/i', $rawEmail, $m)) {
            // 通常最后一个 Message-ID 是原始邮件的
            foreach ($m[1] as $mid) {
                if (strpos($mid, 'yj') !== false) {
                    $messageId = $mid;
                    break;
                }
            }
            if (empty($messageId) && !empty($m[1])) {
                $messageId = end($m[1]);
            }
        }

        // 2. 提取失败的收件人地址
        // DSN 格式：Original-Recipient 或 Final-Recipient
        if (preg_match('/Original-Recipient:\s*[a-z]+;\s*([^\r\n]+)/i', $rawEmail, $m)) {
            $recipient = trim($m[1]);
        } elseif (preg_match('/Final-Recipient:\s*[a-z]+;\s*([^\r\n]+)/i', $rawEmail, $m)) {
            $recipient = trim($m[1]);
        } else {
            // 从正文中查找邮箱地址（Failed-recipients 或 X-Failed-Recipients）
            if (preg_match('/X-Failed-Recipients:\s*([^\r\n]+)/i', $rawEmail, $m)) {
                $recipient = trim($m[1]);
            } elseif (preg_match('/Failed-Recipients?:\s*([^\r\n]+)/i', $rawEmail, $m)) {
                $recipient = trim($m[1]);
            } else {
                // 从正文中提取所有邮箱，过滤掉发件人地址
                if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $body, $emails)) {
                    foreach ($emails[0] as $email) {
                        $emailLower = strtolower($email);
                        // 排除明显的系统地址
                        if (strpos($emailLower, 'postmaster@') === false
                            && strpos($emailLower, 'mailer-daemon@') === false
                            && strpos($emailLower, $this->config['username']) === false) {
                            $recipient = $email;
                            break;
                        }
                    }
                }
            }
        }
        $recipient = trim($recipient, " \t\n\r\0\x0B,;\"'<>");

        // 3. 提取退信原因
        // DSN 格式：Diagnostic-Code
        if (preg_match('/Diagnostic-Code:\s*[a-z]+;\s*([^\r\n]+)/i', $rawEmail, $m)) {
            $reason = trim($m[1]);
        }
        // 状态码
        if (preg_match('/Status:\s*(\d+\.\d+\.\d+)/i', $rawEmail, $m)) {
            $statusCode = $m[1];
            $type = $this->classifyBounceType($statusCode);
            if (empty($reason)) {
                $reason = t('bounce_status_code', '状态码 {code}', ['code' => $statusCode]);
            } else {
                $reason .= t('bounce_status_code_in_paren', '（状态码 {code}）', ['code' => $statusCode]);
            }
        }

        // 如果 DSN 格式没有提取到原因，从正文中提取
        if (empty($reason)) {
            $reason = $this->extractReasonFromBody($body);
        }

        // 如果都没提取到，使用主题作为原因
        if (empty($reason)) {
            $reason = $subject ?: t('bounce_unknown_reason', '未知退信原因');
        }

        if (empty($recipient) && empty($messageId)) {
            return null;
        }

        return [
            'recipient'  => $recipient,
            'reason'     => $reason,
            'type'       => $type,
            'message_id' => $messageId,
        ];
    }

    /**
     * 根据 SMTP 状态码判断退信类型
     *
     * 5.x.x 为永久失败（硬退信）
     * 4.x.x 为临时失败（软退信）
     */
    private function classifyBounceType(string $statusCode): string {
        if (preg_match('/^(\d)\./', $statusCode, $m)) {
            return $m[1] === '5' ? 'hard' : 'soft';
        }
        return 'hard';
    }

    /**
     * 从邮件正文中提取退信原因
     */
    private function extractReasonFromBody(string $body): string {
        $bodyText = strip_tags($body);

        // 常见退信原因模式
        $patterns = [
            '/mailbox (?:is )?(?:full|over quota)/i'                 => t('bounce_reason_mailbox_full', '邮箱已满'),
            '/user not found|no such user|user unknown/i'             => t('bounce_reason_user_not_found', '用户不存在'),
            '/mailbox not found|mailbox unavailable/i'                => t('bounce_reason_mailbox_not_found', '邮箱不存在'),
            '/recipient address rejected/i'                           => t('bounce_reason_recipient_rejected', '收件人地址被拒'),
            '/account is disabled|account disabled/i'                 => t('bounce_reason_account_disabled', '账号已禁用'),
            '/connection (?:refused|timed out)/i'                     => t('bounce_reason_connection', '连接被拒或超时'),
            '/host or domain name not found/i'                        => t('bounce_reason_domain_not_found', '域名未找到'),
            '/no mx record|no mail exchange/i'                        => t('bounce_reason_no_mx', '无 MX 记录'),
            '/spam|junk|blacklist/i'                                   => t('bounce_reason_spam', '被识别为垃圾邮件'),
            '/message too large|exceeds size/i'                       => t('bounce_reason_message_too_large', '邮件过大'),
            '/(?:could not|无法)(?:投递|送达|发送)/i'                  => t('bounce_reason_undeliverable', '无法投递'),
            '/投递失败[：:]\s*([^\r\n]{5,120})/i'                       => '$1',
            '/退信原因[：:]\s*([^\r\n]{5,120})/i'                       => '$1',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $bodyText, $m)) {
                return $replacement === '$1' ? $m[1] : $replacement;
            }
        }

        // 提取 550/551/552 等 SMTP 错误码所在的行
        if (preg_match('/[^\r\n]*(?:550|551|552|553|554|450|451|452)[^\r\n]*/i', $bodyText, $m)) {
            $line = trim($m[0]);
            if (mb_strlen($line) > 200) {
                $line = mb_substr($line, 0, 200);
            }
            return $line;
        }

        return '';
    }

    /**
     * 匹配数据库记录并更新退信状态
     */
    private function matchAndUpdateLog(array $bounceInfo): bool {
        $recipient = $bounceInfo['recipient'];
        $messageId = $bounceInfo['message_id'];

        // 优先通过 Message-ID 匹配（最精确）
        if (!empty($messageId)) {
            $stmt = $this->db->prepare("SELECT id, status FROM mail_logs WHERE message_id = :mid AND bounce_status = 'pending' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([':mid' => $messageId]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($log) {
                return $this->updateBounceStatus((int)$log['id'], $bounceInfo);
            }
        }

        // 回退：通过收件人地址 + 时间范围匹配（48小时内最近一条成功记录）
        if (!empty($recipient)) {
            $stmt = $this->db->prepare("SELECT id, status FROM mail_logs
                WHERE recipient = :recipient
                AND bounce_status = 'pending'
                AND status = 'success'
                AND created_at >= " . get_db_driver()->daysAgo(7) . "
                ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([':recipient' => $recipient]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($log) {
                return $this->updateBounceStatus((int)$log['id'], $bounceInfo);
            }
        }

        return false;
    }

    /**
     * 更新邮件日志的退信状态
     */
    private function updateBounceStatus(int $logId, array $bounceInfo): bool {
        $stmt = $this->db->prepare("UPDATE mail_logs SET
            bounce_status = 'bounced',
            bounce_type = :type,
            bounce_reason = :reason,
            bounce_time = CURRENT_TIMESTAMP,
            status = 'failed',
            error_message = :error
            WHERE id = :id AND bounce_status = 'pending'");
        $errorText = t('bounce_error_text', '退信({type})：{reason}', ['type' => $bounceInfo['type'], 'reason' => $bounceInfo['reason']]);
        return $stmt->execute([
            ':type'   => $bounceInfo['type'],
            ':reason' => mb_substr($bounceInfo['reason'], 0, 1000),
            ':error'  => mb_substr($errorText, 0, 1000),
            ':id'     => $logId,
        ]);
    }

    /**
     * 获取邮件正文
     */
    private function getEmailBody(int $msgNo): string {
        $structure = @imap_fetchstructure($this->imapStream, $msgNo);
        if (!$structure) {
            return @imap_body($this->imapStream, $msgNo) ?: '';
        }

        $body = '';
        if (isset($structure->parts) && is_array($structure->parts) && count($structure->parts) > 0) {
            // 多部分邮件
            foreach ($structure->parts as $partNum => $part) {
                $partBody = $this->getPartBody($msgNo, $partNum + 1, $part);
                if ($partBody !== '') {
                    $body .= $partBody . "\n";
                }
            }
        } else {
            // 单部分邮件
            $body = $this->getPartBody($msgNo, 1, $structure);
        }

        return $body;
    }

    /**
     * 获取邮件分块内容
     */
    private function getPartBody(int $msgNo, int $partNum, object $part): string {
        $body = @imap_fetchbody($this->imapStream, $msgNo, (string)$partNum);
        if ($body === false || $body === '') {
            return '';
        }

        $encoding = $part->encoding ?? 0;
        switch ($encoding) {
            case ENC7BIT:
            case ENC8BIT:
            case ENCBINARY:
                break;
            case ENCBASE64:
                $body = base64_decode($body);
                break;
            case ENCQUOTEDPRINTABLE:
                $body = quoted_printable_decode($body);
                break;
        }

        // 处理字符集
        if (isset($part->ifparameters) && $part->ifparameters) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    $charset = strtolower($param->value);
                    if ($charset !== 'utf-8' && $charset !== 'utf8' && $charset !== 'us-ascii') {
                        $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
                        if ($converted !== false) {
                            $body = $converted;
                        }
                    }
                    break;
                }
            }
        }

        return $body;
    }

    /**
     * 解码 MIME 编码的邮件头
     */
    private function decodeMimeHeader(string $header): string {
        $decoded = @imap_mime_header_decode($header);
        if ($decoded === false) {
            return $header;
        }
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result;
    }

    /**
     * 构造 imap_open 使用的 mailbox 字符串
     */
    private function buildMailboxString(): string {
        $protocol = $this->config['protocol'] === 'pop3' ? '/pop3' : '/imap';
        $encryption = '';
        if ($this->config['encryption'] === 'ssl') {
            $encryption = '/ssl';
        } elseif ($this->config['encryption'] === 'tls') {
            $encryption = '/tls';
        }
        $mailbox = $this->config['mailbox'] ?: 'INBOX';
        return '{' . $this->config['host'] . ':' . $this->config['port'] . $protocol . $encryption . '}' . $mailbox;
    }

    /**
     * 检查 imap 扩展是否可用
     */
    private function isImapAvailable(): bool {
        return function_exists('imap_open');
    }

    /**
     * 记录退信检查日志
     */
    private function logBounceCheck(int $found, int $processed, string $error = '', array $details = []): void {
        try {
            $stmt = $this->db->prepare("INSERT INTO mail_bounce_logs (found_count, processed_count, error_message, details) VALUES (:found, :processed, :error, :details)");
            $stmt->execute([
                ':found'     => $found,
                ':processed' => $processed,
                ':error'     => $error,
                ':details'   => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : '',
            ]);
        } catch (Exception $e) {
            error_log(t('bounce_log_check_failed', 'logBounceCheck 失败：') . $e->getMessage());
        }
    }

    /**
     * 获取最近 N 条退信检查日志
     */
    public function getRecentBounceLogs(int $limit = 10): array {
        $stmt = $this->db->prepare("SELECT * FROM mail_bounce_logs ORDER BY check_time DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取退信统计
     */
    public function getBounceStats(): array {
        $stats = [
            'total_bounced'  => 0,
            'hard_bounced'   => 0,
            'soft_bounced'   => 0,
            'pending'        => 0,
            'last_check'     => null,
            'last_count'     => 0,
        ];
        try {
            $stats['total_bounced'] = (int)$this->db->query("SELECT COUNT(*) FROM mail_logs WHERE bounce_status = 'bounced'")->fetchColumn();
            $stats['hard_bounced']  = (int)$this->db->query("SELECT COUNT(*) FROM mail_logs WHERE bounce_status = 'bounced' AND bounce_type = 'hard'")->fetchColumn();
            $stats['soft_bounced']  = (int)$this->db->query("SELECT COUNT(*) FROM mail_logs WHERE bounce_status = 'bounced' AND bounce_type = 'soft'")->fetchColumn();
            $stats['pending']       = (int)$this->db->query("SELECT COUNT(*) FROM mail_logs WHERE bounce_status = 'pending' AND status = 'success'")->fetchColumn();
            $cfg = $this->db->query("SELECT last_check, last_check_count FROM mail_bounce_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                $stats['last_check'] = $cfg['last_check'];
                $stats['last_count'] = (int)$cfg['last_check_count'];
            }
        } catch (Exception $e) {
            // 忽略
        }
        return $stats;
    }
}
