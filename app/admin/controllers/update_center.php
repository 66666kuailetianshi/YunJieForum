<?php
/**
 * 云界论坛 - 管理后台「系统更新中心」
 *
 * 提供：
 *   1. 当前版本展示与「检查更新」入口（手动）。
 *   2. 「立即更新」入口（手动应用：下载 → 校验 → 备份 → 覆盖）。
 *   3. 自动更新设置（更新源地址、更新通道、是否自动应用、检查间隔）。
 *   4. 历史更新备份列表（位于更新设置下方，可下载 / 分享 / 删除，带分页）。
 */

require_once dirname(__DIR__) . '/layout/admin-init.php';

// 权限门禁：系统更新中心仅超级管理员可用
require_super_admin();

require_once APP_ROOT . 'app/includes/update_center.php';

// 处理历史更新备份下载（GET + 一次性派生令牌，直接流式输出）
if (($_GET['action'] ?? '') === 'download') {
    $filename = (string)($_GET['filename'] ?? '');
    $token    = (string)($_GET['token'] ?? '');
    if (!uc_is_update_backup_name($filename) || !admin_backup_download_token_valid($filename, $token)) {
        set_flash(t('admin_backup_flash_download_token_invalid', '下载令牌无效。'), 'error');
        redirect('/admin/update_center');
    }
    $filepath = uc_update_backup_dir() . $filename;
    if (!is_file($filepath)) {
        set_flash(t('update_history_file_missing', '备份文件不存在或已被删除。'), 'error');
        redirect('/admin/update_center');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($filepath);
    exit;
}

// 更新诊断页：独立页面输出「检测更新」的完整错误与环境信息（GET、无状态，实时重新检查一次）。
// 当「检查更新」失败时，前端提供入口跳转到本页；页面内容可整体复制，便于发给开发者排查。
if (($_GET['action'] ?? '') === 'check_diag') {
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $diagCfg = [
        'update_source_url' => uc_get_setting('update_source_url', ''),
        'update_channel'    => uc_get_setting('update_channel', 'stable'),
        'update_ssl_verify' => uc_get_setting('update_ssl_verify', '1') === '1' ? 'on' : 'off',
        'update_skip_hash'  => uc_get_setting('update_skip_hash', '0') === '1' ? 'on' : 'off',
        'update_last_check' => (int)uc_get_setting('update_last_check', '0'),
        'current_version'   => uc_get_current_version(),
    ];
    try {
        $diagCheck = uc_check_for_update();
    } catch (\Throwable $e) {
        // 检查过程本身抛出的 PHP 异常也完整呈现
        $diagCheck = [
            'success' => false,
            'error'   => 'exception: ' . $e->getMessage(),
            'details' => [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ],
        ];
    }
    $diagEnv = uc_env_snapshot();

    // 将嵌套数组扁平化为「缩进 + 键 + 值」行，页面表格与复制文本共用
    $diagRows = []; // ['d' => 缩进深度, 'k' => 键, 'v' => 值]
    $diagCollect = function ($data, $depth = 0) use (&$diagCollect, &$diagRows) {
        foreach ((array)$data as $k => $v) {
            if (is_array($v)) {
                $diagRows[] = ['d' => $depth, 'k' => (string)$k, 'v' => ''];
                $diagCollect($v, $depth + 1);
            } else {
                $diagRows[] = ['d' => $depth, 'k' => (string)$k, 'v' => is_bool($v) ? ($v ? 'true' : 'false') : (string)$v];
            }
        }
    };
    $diagCollect(['config' => $diagCfg, 'check' => $diagCheck, 'env' => $diagEnv]);

    $diagTextLines = [];
    foreach ($diagRows as $r) {
        $diagTextLines[] = str_repeat('  ', $r['d']) . $r['k'] . ': ' . $r['v'];
    }
    $diagText = implode("\n", $diagTextLines);
    $diagTextEsc = e($diagText); // 嵌入 <pre> 前必须转义（值可能含 < > & 等）

    $isOk = !empty($diagCheck['success']);
    $resultTitle = $isOk ? t('update_diag_check_ok', '更新检查成功') : t('update_diag_check_fail', '更新检查失败');
    $resultClass = $isOk ? 'diag-ok' : 'diag-fail';
    $resultDetail = '';
    if ($isOk) {
        $resultDetail = e(t('update_diag_latest', '最新版本') . '：') . e((string)($diagCheck['latest'] ?? ''))
            . '　' . e(t('update_diag_available', '存在可用更新') . '：') . (!empty($diagCheck['update_available']) ? 'yes' : 'no');
        if (!empty($diagCheck['changelog'])) {
            $resultDetail .= '<pre class="diag-pre">' . e((string)$diagCheck['changelog']) . '</pre>';
        }
    } else {
        $resultDetail = '<strong>' . e((string)($diagCheck['error'] ?? '')) . '</strong>';
        if (!empty($diagCheck['details'])) {
            $resultDetail .= '<p class="diag-muted">' . e(t('update_diag_details_hint', '完整诊断字段见下方「诊断数据」表。')) . '</p>';
        }
    }
    $resultDetail .= '<pre class="diag-pre">' . e(json_encode($diagCheck, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';

    // 按顶层前缀过滤渲染扁平表格（config / env），嵌套对象以缩进展示
    $renderSection = function (string $prefix) use ($diagRows) {
        $html = '<table class="diag-table">';
        foreach ($diagRows as $r) {
            if ($r['d'] === 0) {
                continue; // 跳过分区头
            }
            if (strpos($r['k'], $prefix . '.') !== 0) {
                continue;
            }
            $key = substr($r['k'], strlen($prefix) + 1);
            $indent = str_repeat('&nbsp;&nbsp;', $r['d'] - 1);
            $html .= '<tr><td class="diag-k">' . $indent . e($key) . '</td><td class="diag-v">' . e($r['v']) . '</td></tr>';
        }
        return $html . '</table>';
    };

    $cfgTable = $renderSection('config');
    $envTable = $renderSection('env');

    // 全部诊断数据（含嵌套对象头行），供「诊断数据」表展示
    $allRowsHtml = '';
    foreach ($diagRows as $r) {
        $indent = str_repeat('&nbsp;&nbsp;', $r['d']);
        $allRowsHtml .= '<tr><td class="diag-k">' . $indent . e($r['k']) . '</td><td class="diag-v">' . e($r['v']) . '</td></tr>';
    }

    // 页面文案
    $copyBtnText = e(t('update_diag_copy', '复制诊断信息'));
    $copiedText  = e(t('update_diag_copied', '已复制'));
    $backText    = e(t('update_diag_back', '← 返回更新中心'));
    $backUrl     = e(site_url('admin/update_center'));
    $cfgTitle    = e(t('update_diag_cfg_title', '更新配置'));
    $envTitle    = e(t('update_diag_env_title', '服务器环境'));
    $diagTitle   = e(t('update_diag_title', '诊断数据'));
    $diagNote    = e(t('update_diag_note', '诊断页为实时重新检查的结果（无缓存）。点击右上角「复制诊断信息」可将全部字段发给开发者协助排查。'));
    $pageTitle   = e(t('update_title', '系统更新'));
    $refreshHref = e((string)($_SERVER['REQUEST_URI'] ?? ''));

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$resultTitle} - {$pageTitle}</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Microsoft YaHei",sans-serif;background:#f5f6f8;margin:0;padding:24px;color:#222;}
  .wrap{max-width:960px;margin:0 auto;}
  .diag-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;}
  .diag-title{font-size:1.3rem;font-weight:700;margin:0;}
  .diag-back{color:#3370ff;text-decoration:none;font-size:.9rem;}
  .diag-copy{background:#3370ff;color:#fff;border:0;border-radius:6px;padding:.5rem .9rem;font-size:.85rem;cursor:pointer;}
  .diag-copy:disabled{opacity:.6;cursor:default;}
  .diag-banner{padding:.9rem 1.1rem;border-radius:8px;font-size:.95rem;line-height:1.6;word-break:break-all;margin-bottom:1rem;}
  .diag-ok{background:#e6f7ec;color:#0a6b2f;border:1px solid #b7e8c8;}
  .diag-fail{background:#fdeeee;color:#b3251c;border:1px solid #f3c0bc;}
  .card{background:#fff;border:1px solid #e3e5e8;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem;}
  .card h2{margin:0 0 .75rem;font-size:1.05rem;}
  .diag-table{width:100%;border-collapse:collapse;font-size:.85rem;}
  .diag-table td{padding:.35rem .6rem;border-bottom:1px solid #f0f1f3;vertical-align:top;}
  .diag-k{white-space:nowrap;color:#666;font-family:Consolas,Menlo,monospace;}
  .diag-v{word-break:break-all;}
  .diag-pre{background:#f6f8fa;border:1px solid #e3e5e8;border-radius:6px;padding:.75rem;font-size:.78rem;line-height:1.5;overflow:auto;max-height:340px;white-space:pre-wrap;word-break:break-all;}
  .diag-muted{color:#888;font-size:.82rem;margin:.5rem 0 0;}
  .diag-hidden{position:absolute;left:-9999px;top:0;}
  .diag-note{color:#999;font-size:.8rem;margin-top:.5rem;}
</style>
</head>
<body>
<div class="wrap">
  <div class="diag-head">
    <h1 class="diag-title">{$resultTitle}</h1>
    <div>
      <a class="diag-back" href="{$refreshHref}">⟳</a>
      <button type="button" class="diag-copy" id="diagCopyBtn">{$copyBtnText}</button>
      <a class="diag-back" href="{$backUrl}" style="margin-left:.75rem;">{$backText}</a>
    </div>
  </div>

  <div class="diag-banner {$resultClass}">{$resultDetail}</div>

  <div class="card">
    <h2>{$cfgTitle}</h2>
    {$cfgTable}
  </div>

  <div class="card">
    <h2>{$envTitle}</h2>
    {$envTable}
  </div>

  <div class="card">
    <h2>{$diagTitle}</h2>
    <table class="diag-table">{$allRowsHtml}</table>
    <p class="diag-note">{$diagNote}</p>
  </div>

  <pre class="diag-hidden" id="diagRaw">{$diagTextEsc}</pre>
</div>
<script>
(function () {
  var btn = document.getElementById('diagCopyBtn');
  var raw = document.getElementById('diagRaw');
  btn.addEventListener('click', function () {
    var text = raw.textContent;
    var done = function () {
      btn.textContent = '{$copiedText}';
      btn.disabled = true;
      setTimeout(function () {
        btn.textContent = '{$copyBtnText}';
        btn.disabled = false;
      }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text); done(); });
    } else {
      fallbackCopy(text);
      done();
    }
  });
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
})();
</script>
</body>
</html>
HTML;
    exit;
}

// 解压失败诊断页：独立页面展示最近一次「解压覆盖失败」的完整诊断（GET、仅超管）：
// 失败概要 / 服务器环境 / 关键目录与失败条目所在目录的权限检查 / 逐条失败原因 / 按
// 操作系统（Windows / Linux）给出的修复命令建议。数据来自失败时保存的报告文件。
if (($_GET['action'] ?? '') === 'extract_error') {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Type: text/html; charset=utf-8');

    $errReport = uc_load_extract_error_report();
    $errCopyBtnText = e(t('update_extract_err_copy', '复制诊断信息'));
    $errCopiedText  = e(t('update_extract_err_copied', '已复制'));
    $errBackText    = e(t('update_extract_err_back', '← 返回更新中心'));
    $errBackUrl     = e(site_url('admin/update_center'));
    $errPageTitle   = e(t('update_extract_err_title', '解压失败诊断'));

    if ($errReport === null) {
        $errEmptyText = e(t('update_extract_err_empty', '暂无解压失败记录。仅当最近一次系统更新解压失败时，本页才会显示详细诊断。'));
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$errPageTitle}</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Microsoft YaHei",sans-serif;background:#f5f6f8;margin:0;padding:24px;color:#222;}
  .wrap{max-width:960px;margin:0 auto;}
  .diag-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;}
  .diag-title{font-size:1.3rem;font-weight:700;margin:0;}
  .diag-back{color:#3370ff;text-decoration:none;font-size:.9rem;}
  .diag-banner{padding:.9rem 1.1rem;border-radius:8px;font-size:.95rem;line-height:1.6;background:#eef1f5;color:#555;border:1px solid #dde2e8;}
</style>
</head>
<body>
<div class="wrap">
  <div class="diag-head">
    <h1 class="diag-title">{$errPageTitle}</h1>
    <a class="diag-back" href="{$errBackUrl}">{$errBackText}</a>
  </div>
  <div class="diag-banner">{$errEmptyText}</div>
</div>
</body>
</html>
HTML;
        exit;
    }

    $errIsWin = (string)($errReport['env']['os'] ?? '') === 'Windows';

    // 关键目录 label → 可读名称（root/failed_dir 为报告内标记，其余直接展示）
    $errDirLabel = function (string $label) {
        if ($label === 'root') {
            return t('update_extract_err_dir_root', '安装根目录');
        }
        if ($label === 'failed_dir') {
            return t('update_extract_err_dir_failed', '失败条目所在目录');
        }
        return $label;
    };

    // 失败阶段 → 可读名称
    $errPhaseLabel = function (string $phase) {
        if ($phase === 'mkdir') {
            return t('update_extract_err_phase_mkdir', '创建目录');
        }
        if ($phase === 'read') {
            return t('update_extract_err_phase_read', '读取压缩包');
        }
        return t('update_extract_err_phase_write', '写入文件');
    };

    $errYes = t('update_extract_err_yes', '是');
    $errNo  = t('update_extract_err_no', '否');

    // ===== 概要 =====
    $errSourceText = ($errReport['source'] ?? '') === 'upload_install'
        ? t('update_extract_err_source_upload', '手动上传更新包安装')
        : t('update_extract_err_source_remote', '在线更新（下载并解压）');
    $errBannerHtml = '<strong>' . e((string)($errReport['error'] ?? '')) . '</strong><br>'
        . e(t('update_extract_err_source', '失败来源')) . '：' . e($errSourceText)
        . '　' . e(t('update_extract_err_generated', '生成时间')) . '：' . e(date('Y-m-d H:i:s', (int)($errReport['generated_at'] ?? 0))) . '<br>'
        . e(t('update_extract_err_files_ok', '成功写入')) . '：' . (int)($errReport['files_ok'] ?? 0) . ' '
        . e(t('update_extract_err_files_unit', '个文件')) . '　' . e(t('update_extract_err_failed_total', '失败条目')) . '：' . (int)($errReport['failed_count'] ?? 0);
    if (!empty($errReport['backup'])) {
        $errBannerHtml .= '<br>' . e(t('update_backup_kept', '已保留备份')) . '：' . e(basename((string)$errReport['backup']));
    }
    if (($errReport['extract_error'] ?? '') === 'package_open_failed' && $errReport['open_result'] !== null) {
        $errBannerHtml .= '<br>ZipArchive open error: ' . e((string)$errReport['open_result']);
    }

    // ===== 环境信息表 =====
    $errEnvHtml = '';
    foreach ((array)($errReport['env'] ?? []) as $ek => $ev) {
        $errEnvHtml .= '<tr><td class="diag-k">' . e((string)$ek) . '</td><td class="diag-v">' . e((string)$ev) . '</td></tr>';
    }

    // ===== 目录权限表 =====
    $errDirsHtml = '';
    foreach ((array)($errReport['dirs'] ?? []) as $d) {
        $writable = !empty($d['writable']);
        $owner = $d['owner'] ?? '';
        $ownerText = ($owner === false || $owner === '') ? '—' : (string)$owner;
        if ($errIsWin && is_numeric($ownerText)) {
            $ownerText = '—（NTFS ACL）'; // Windows 上 fileowner 返回的 UID 无意义
        }
        $permsText = ($d['perms'] ?? '') !== '' ? (string)$d['perms'] : ($errIsWin ? '—（NTFS ACL）' : '—');
        $errDirsHtml .= '<tr>'
            . '<td class="diag-k">' . e($errDirLabel((string)($d['label'] ?? ''))) . '<br><small>' . e((string)($d['path'] ?? '')) . '</small></td>'
            . '<td>' . (!empty($d['exists']) ? $errYes : '<span class="err-bad">' . $errNo . '</span>') . '</td>'
            . '<td>' . ($writable ? '<span class="err-ok">' . $errYes . '</span>' : '<span class="err-bad">' . $errNo . '</span>') . '</td>'
            . '<td>' . e($permsText) . '</td>'
            . '<td>' . e($ownerText) . '</td>'
            . '<td>' . e((string)($d['hint'] ?? '')) . '</td>'
            . '</tr>';
    }

    // ===== 失败文件表（页面上限 200 行，全量在复制文本中） =====
    $errItems = (array)($errReport['failed_items'] ?? []);
    $errShown = min(count($errItems), 200);
    $errFilesHtml = '';
    for ($ei = 0; $ei < $errShown; $ei++) {
        $it = $errItems[$ei];
        $dirCell = e((string)($it['dir'] ?? ''));
        if (!empty($it['dir_writable'])) {
            $dirCell .= '<br><small class="err-ok">' . e($errYes) . '</small>';
        } else {
            $dirCell .= '<br><small class="err-bad">' . e($errNo);
            if (($it['dir_perms'] ?? '') !== '') {
                $dirCell .= '（' . e((string)$it['dir_perms']) . '）';
            }
            $dirCell .= '</small>';
        }
        $errFilesHtml .= '<tr>'
            . '<td class="diag-k">' . e((string)($it['name'] ?? '')) . '</td>'
            . '<td>' . e($errPhaseLabel((string)($it['phase'] ?? 'write'))) . '</td>'
            . '<td class="diag-v">' . e((string)($it['why'] ?? '')) . '</td>'
            . '<td class="diag-v">' . $dirCell . '</td>'
            . '</tr>';
    }
    $errFilesMore = count($errItems) > $errShown
        ? '<p class="diag-muted">' . e(t('update_extract_err_files_more', '共 {n} 个失败条目，此处仅显示前 {shown} 个。', ['n' => count($errItems), 'shown' => $errShown])) . '</p>'
        : '';
    $errFilesEmpty = count($errItems) === 0
        ? '<p class="diag-muted">' . e(t('update_extract_err_no_items', '无逐条失败记录（可能是压缩包打开失败等早期错误，见上方概要）。')) . '</p>'
        : '';

    // ===== 修复建议 =====
    $errHintsHtml = '';
    foreach ((array)($errReport['hints'] ?? []) as $h) {
        $errHintsHtml .= '<li>' . e((string)$h) . '</li>';
    }

    // ===== 可复制的纯文本诊断（含全部失败条目） =====
    $errTextLines = [
        'error: ' . (string)($errReport['error'] ?? ''),
        'source: ' . $errSourceText,
        'generated_at: ' . date('Y-m-d H:i:s', (int)($errReport['generated_at'] ?? 0)),
        'files_ok: ' . (int)($errReport['files_ok'] ?? 0),
        'failed_count: ' . (int)($errReport['failed_count'] ?? 0),
        'backup: ' . (string)($errReport['backup'] ?? ''),
        'env:',
    ];
    foreach ((array)($errReport['env'] ?? []) as $ek => $ev) {
        $errTextLines[] = '  ' . $ek . ': ' . $ev;
    }
    $errTextLines[] = 'dirs:';
    foreach ((array)($errReport['dirs'] ?? []) as $d) {
        $errTextLines[] = '  - ' . ($d['label'] ?? '') . ' | path: ' . ($d['path'] ?? '')
            . ' | exists: ' . (!empty($d['exists']) ? 'yes' : 'no')
            . ' | writable: ' . (!empty($d['writable']) ? 'yes' : 'no')
            . ' | perms: ' . (($d['perms'] ?? '') !== '' ? $d['perms'] : '-')
            . ' | owner: ' . (($d['owner'] === false || ($d['owner'] ?? '') === '') ? '-' : (string)$d['owner']);
    }
    $errTextLines[] = 'failed_items (' . count($errItems) . '):';
    foreach ($errItems as $it) {
        $errTextLines[] = '  - [' . ($it['phase'] ?? '') . '] ' . ($it['name'] ?? '')
            . ' | why: ' . ($it['why'] ?? '')
            . ' | dir: ' . ($it['dir'] ?? '')
            . ' | dir_writable: ' . (!empty($it['dir_writable']) ? 'yes' : 'no');
    }
    $errTextLines[] = 'hints:';
    foreach ((array)($errReport['hints'] ?? []) as $h) {
        $errTextLines[] = '  - ' . $h;
    }
    $errRawText    = implode("\n", $errTextLines);
    $errRawTextEsc = e($errRawText);

    // 页面文案
    $errSummaryTitle = e(t('update_extract_err_summary', '失败概要'));
    $errEnvTitle     = e(t('update_extract_err_env_title', '服务器环境'));
    $errDirsTitle    = e(t('update_extract_err_dirs_title', '目录权限检查'));
    $errFilesTitle   = e(t('update_extract_err_files_title', '失败文件清单'));
    $errHintsTitle   = e(t('update_extract_err_hints_title', '修复建议'));
    $errNote         = e(t('update_extract_err_note', '本页数据来自最近一次解压失败时保存的诊断记录（data/tmp/update_extract_error.json）。修复问题后重新执行更新即可；新的失败会覆盖旧记录。'));
    $errColDir       = e(t('update_extract_err_col_dir', '目录'));
    $errColExists    = e(t('update_extract_err_col_exists', '存在'));
    $errColWritable  = e(t('update_extract_err_col_writable', '可写'));
    $errColPerms     = e(t('update_extract_err_col_perms', '权限'));
    $errColOwner     = e(t('update_extract_err_col_owner', '属主'));
    $errColHint      = e(t('update_extract_err_col_hint', '说明'));
    $errColFile      = e(t('update_extract_err_col_file', '文件'));
    $errColPhase     = e(t('update_extract_err_col_phase', '阶段'));
    $errColError     = e(t('update_extract_err_col_error', '错误信息'));
    $errColDirPerm   = e(t('update_extract_err_col_dirperm', '所在目录（可写）'));

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$errPageTitle}</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Microsoft YaHei",sans-serif;background:#f5f6f8;margin:0;padding:24px;color:#222;}
  .wrap{max-width:1080px;margin:0 auto;}
  .diag-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;}
  .diag-title{font-size:1.3rem;font-weight:700;margin:0;}
  .diag-back{color:#3370ff;text-decoration:none;font-size:.9rem;}
  .diag-copy{background:#3370ff;color:#fff;border:0;border-radius:6px;padding:.5rem .9rem;font-size:.85rem;cursor:pointer;}
  .diag-copy:disabled{opacity:.6;cursor:default;}
  .diag-banner{padding:.9rem 1.1rem;border-radius:8px;font-size:.92rem;line-height:1.7;word-break:break-all;margin-bottom:1rem;background:#fdeeee;color:#b3251c;border:1px solid #f3c0bc;}
  .card{background:#fff;border:1px solid #e3e5e8;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem;}
  .card h2{margin:0 0 .75rem;font-size:1.05rem;}
  .diag-table{width:100%;border-collapse:collapse;font-size:.85rem;}
  .diag-table th{text-align:left;padding:.4rem .6rem;border-bottom:2px solid #e3e5e8;color:#555;font-size:.8rem;white-space:nowrap;}
  .diag-table td{padding:.35rem .6rem;border-bottom:1px solid #f0f1f3;vertical-align:top;}
  .diag-k{color:#333;font-family:Consolas,Menlo,monospace;word-break:break-all;}
  .diag-v{word-break:break-all;}
  .err-ok{color:#0a6b2f;font-weight:600;}
  .err-bad{color:#b3251c;font-weight:600;}
  .diag-muted{color:#888;font-size:.82rem;margin:.5rem 0 0;}
  .diag-note{color:#999;font-size:.8rem;margin-top:.5rem;}
  .diag-hidden{position:absolute;left:-9999px;top:0;}
  .err-hints{margin:0;padding-left:1.25rem;font-size:.88rem;line-height:1.9;}
  .scroll-box{max-height:420px;overflow:auto;}
</style>
</head>
<body>
<div class="wrap">
  <div class="diag-head">
    <h1 class="diag-title">{$errPageTitle}</h1>
    <div>
      <button type="button" class="diag-copy" id="errCopyBtn">{$errCopyBtnText}</button>
      <a class="diag-back" href="{$errBackUrl}" style="margin-left:.75rem;">{$errBackText}</a>
    </div>
  </div>

  <div class="diag-banner">{$errBannerHtml}</div>

  <div class="card">
    <h2>{$errEnvTitle}</h2>
    <table class="diag-table">{$errEnvHtml}</table>
  </div>

  <div class="card">
    <h2>{$errDirsTitle}</h2>
    <div class="scroll-box">
    <table class="diag-table">
      <thead><tr>
        <th>{$errColDir}</th><th>{$errColExists}</th><th>{$errColWritable}</th><th>{$errColPerms}</th><th>{$errColOwner}</th><th>{$errColHint}</th>
      </tr></thead>
      <tbody>{$errDirsHtml}</tbody>
    </table>
    </div>
  </div>

  <div class="card">
    <h2>{$errFilesTitle}</h2>
    {$errFilesEmpty}
    <div class="scroll-box">
    <table class="diag-table">
      <thead><tr>
        <th>{$errColFile}</th><th>{$errColPhase}</th><th>{$errColError}</th><th>{$errColDirPerm}</th>
      </tr></thead>
      <tbody>{$errFilesHtml}</tbody>
    </table>
    </div>
    {$errFilesMore}
  </div>

  <div class="card">
    <h2>{$errHintsTitle}</h2>
    <ul class="err-hints">{$errHintsHtml}</ul>
    <p class="diag-note">{$errNote}</p>
  </div>

  <pre class="diag-hidden" id="errRaw">{$errRawTextEsc}</pre>
</div>
<script>
(function () {
  var btn = document.getElementById('errCopyBtn');
  var raw = document.getElementById('errRaw');
  btn.addEventListener('click', function () {
    var text = raw.textContent;
    var done = function () {
      btn.textContent = '{$errCopiedText}';
      btn.disabled = true;
      setTimeout(function () {
        btn.textContent = '{$errCopyBtnText}';
        btn.disabled = false;
      }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () { errFallbackCopy(text); done(); });
    } else {
      errFallbackCopy(text);
      done();
    }
  });
  function errFallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
})();
</script>
</body>
</html>
HTML;
    exit;
}

$errors   = [];
$autoResult = null;

$updateSourceUrl    = uc_get_setting('update_source_url', '');
$updateChannel      = uc_get_setting('update_channel', 'stable');
$updateAutoEnabled  = uc_get_setting('update_auto_enabled', '0') === '1';
$updateAutoInterval = (int)uc_get_setting('update_auto_interval', '24');
$updateSslVerify    = uc_get_setting('update_ssl_verify', '1') === '1';
$updateSkipHash     = uc_get_setting('update_skip_hash', '0') === '1';
$updateLastCheck    = (int)uc_get_setting('update_last_check', '0');
$updateLastVersion  = uc_get_setting('update_last_version', '');
$currentVersion     = uc_get_current_version();

// 自动更新触发（仅非 POST 访问、且已启用时，按间隔自动检查并应用）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $autoResult = uc_try_auto_update();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $updateSourceUrl = trim((string)($_POST['update_source_url'] ?? ''));
    $updateChannel   = trim((string)($_POST['update_channel'] ?? 'stable'));
    if (!in_array($updateChannel, ['stable', 'beta', 'dev'], true)) {
        $updateChannel = 'stable';
    }
    $updateAutoEnabled  = !empty($_POST['update_auto_enabled']) ? '1' : '0';
    $updateAutoInterval = (int)($_POST['update_auto_interval'] ?? '24');
    $updateSslVerify    = !empty($_POST['update_ssl_verify']) ? '1' : '0';
    $updateSkipHash     = !empty($_POST['update_skip_hash']) ? '1' : '0';
    if ($updateAutoInterval < 1)  $updateAutoInterval = 1;
    if ($updateAutoInterval > 720) $updateAutoInterval = 720;

    set_site_setting('update_source_url', $updateSourceUrl);
    set_site_setting('update_channel', $updateChannel);
    set_site_setting('update_auto_enabled', $updateAutoEnabled);
    set_site_setting('update_auto_interval', (string)$updateAutoInterval);
    set_site_setting('update_ssl_verify', $updateSslVerify);
    set_site_setting('update_skip_hash', $updateSkipHash);

    set_flash(t('update_settings_saved', '更新中心设置已保存。'), 'success');
    redirect('/admin/update_center');
}

$pageTitle   = t('update_title', '系统更新');
$activeMenu  = 'update_center';

// 历史更新备份列表（更新前自动创建的代码备份），分页每页 10 条（与「数据备份」页一致）
$updateBackups = uc_list_update_backups();
$historyPerPage = 10;
$historyTotal = count($updateBackups);
$historyTotalPages = max(1, (int)ceil($historyTotal / $historyPerPage));
$historyPage = max(1, min((int)($_GET['page'] ?? 1), $historyTotalPages));
$pagedUpdateBackups = array_slice($updateBackups, ($historyPage - 1) * $historyPerPage, $historyPerPage);

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e(t('update_title', '系统更新')); ?></h1>
        <p class="page-subtitle"><?php echo e(t('update_desc', '检查并应用云界论坛的版本更新，支持手动与自动两种方式。')); ?></p>
    </div>
</div>

<?php if ($autoResult !== null && !empty($autoResult['ran'])): ?>
    <?php $ar = $autoResult['result'] ?? []; ?>
    <?php if (!empty($ar['success'])): ?>
        <?php echo show_message(t('update_auto_applied', '已自动更新至 {to}（备份：{backup}）', ['to' => $ar['to'] ?? '', 'backup' => basename($ar['backup'] ?? '')]), 'success'); ?>
    <?php elseif (!empty($ar['error'])): ?>
        <?php echo show_message(t('update_auto_failed', '自动更新未成功：{err}', ['err' => $ar['error']]), 'error'); ?>
    <?php endif; ?>
<?php endif; ?>

<div class="card mb-2">
    <h2 class="card-title mb-1"><?php echo e(t('update_status', '更新状态')); ?></h2>
    <div class="update-meta">
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('update_current_version', '当前版本')); ?></span>
            <span class="update-meta-value" id="currentVersion"><?php echo e($currentVersion); ?></span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('update_last_check', '上次检查')); ?></span>
            <span class="update-meta-value" id="lastCheck">
                <?php echo $updateLastCheck > 0 ? e(date('Y-m-d H:i:s', $updateLastCheck)) : e(t('update_never', '从未')); ?>
            </span>
        </div>
        <div class="update-meta-item">
            <span class="update-meta-label"><?php echo e(t('update_channel', '更新通道')); ?></span>
            <span class="update-meta-value"><?php echo e($updateChannel); ?></span>
        </div>
    </div>

    <div id="updateStatus" class="update-status" style="display:none;"></div>

    <!-- 更新进度条 -->
    <div id="updateProgress" class="update-progress-wrap" style="display:none;">
        <div class="update-progress-header">
            <span class="update-progress-spinner">⟳</span>
            <span id="updateProgressStage" class="update-progress-stage"><?php echo e(t('update_progress_preparing', '准备中…')); ?></span>
            <span id="updateProgressPct" class="update-progress-pct">0%</span>
        </div>
        <div class="update-progress-bar-outer">
            <div class="update-progress-bar-inner" id="updateProgressBar" style="width:0%"></div>
        </div>
        <div id="updateProgressDetail" class="update-progress-detail"></div>
    </div>

    <div class="update-actions mt-2">
        <button type="button" class="btn btn-secondary" id="checkBtn"><?php echo e(t('update_check_now', '检查更新')); ?></button>
        <button type="button" class="btn btn-primary" id="updateBtn" disabled><?php echo e(t('update_update_now', '立即更新')); ?></button>
    </div>
    <p class="form-hint mt-1"><?php echo e(t('update_manual_hint', '「立即更新」会在下载后校验文件哈希、先自动备份现有代码，再覆盖升级。请在操作前确认已开启自动备份。')); ?></p>
</div>

<!-- 手动上传更新包（无需配置远程更新源） -->
<div class="card mb-2">
    <h2 class="card-title mb-1"><?php echo e(t('update_upload_title', '手动上传更新包')); ?></h2>
    <p class="form-hint mt-1"><?php echo e(t('update_upload_desc', '无需配置远程更新源：选择本地的云界论坛更新包（.zip），系统会校验包内版本、自动备份当前代码后覆盖升级。data/ 配置与数据不会被覆盖。')); ?></p>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <input type="file" id="uploadPkgInput" accept=".zip" class="form-control" style="max-width:340px;">
        <button type="button" class="btn btn-secondary" id="uploadPkgBtn"><?php echo e(t('update_upload_parse', '上传并解析')); ?></button>
    </div>
    <!-- 上传/安装进度：上传阶段由 XHR upload progress 驱动（百分比/已传字节/实时速度），
         安装阶段轮询后端进度文件（备份 → 解压覆盖 → 完成） -->
    <div id="uploadPkgProgress" class="update-progress-wrap" style="display:none;margin-top:.75rem;">
        <div class="update-progress-header">
            <span class="update-progress-stage" id="uploadPkgProgStage"></span>
            <span class="update-progress-pct" id="uploadPkgProgPct">0%</span>
        </div>
        <div class="update-progress-bar-outer">
            <div class="update-progress-bar-inner" id="uploadPkgProgBar" style="width:0%"></div>
        </div>
        <div class="update-progress-detail" id="uploadPkgProgDetail"></div>
    </div>
    <div id="uploadPkgInfo" style="display:none;margin-top:.75rem;padding:.75rem 1rem;background:var(--bg-subtle,#f6f6f6);border-radius:8px;"></div>
    <div id="uploadInstallRow" style="display:none;margin-top:.75rem;">
        <button type="button" class="btn btn-primary" id="installUploadBtn"><?php echo e(t('update_upload_install', '安装此更新包')); ?></button>
    </div>
    <div id="uploadResult" class="update-status mt-2" style="display:none;"></div>
</div>

<!-- 更新确认对话框（替代原生 confirm） -->
<div class="modal-overlay" id="updateConfirmModal" style="display:none;">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-header">
            <h3 class="modal-title" id="updateConfirmTitle"><?php echo e(t('update_confirm_title', '确认立即更新')); ?></h3>
            <button type="button" class="modal-close" id="updateConfirmClose">&times;</button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.5rem;">
            <p id="updateConfirmText" style="margin:0;font-size:.95rem;line-height:1.7;color:var(--text);"><?php echo e(t('update_confirm', '确定要立即更新吗？系统将先备份当前代码再覆盖升级。')); ?></p>
            <div class="update-confirm-safe" id="updateConfirmSafe">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                <span><?php echo e(t('update_confirm_data_safe', '您的配置不会丢失：data/ 目录（数据库、站点设置、SMTP 邮件服务等）在升级中不会被覆盖。升级前还会自动备份全部代码，可随时恢复。')); ?></span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="updateConfirmCancel"><?php echo e(t('update_confirm_cancel', '取消')); ?></button>
            <button type="button" class="btn btn-primary" id="updateConfirmOk"><?php echo e(t('update_confirm_ok', '确认更新')); ?></button>
        </div>
    </div>
</div>

<!-- 备份分享对话框 -->
<div class="modal-overlay" id="shareModal" style="display:none;">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header">
            <h3 class="modal-title"><?php echo e(t('update_history_share_title', '分享更新备份')); ?></h3>
            <button type="button" class="modal-close" id="shareModalClose">&times;</button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.5rem;">
            <p style="margin:0 0 .75rem;font-size:.9rem;line-height:1.6;color:var(--text-muted);"><?php echo e(t('update_history_share_desc', '获得下方链接的人无需登录即可下载该备份，链接默认 7 天内有效，请勿分享给不信任的人。')); ?></p>
            <input type="text" class="form-control" id="shareUrlInput" readonly style="font-size:.85rem;">
            <p class="form-hint mt-1" id="shareExpires" style="margin-bottom:0;"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="shareModalCancel"><?php echo e(t('update_confirm_cancel', '取消')); ?></button>
            <button type="button" class="btn btn-primary" id="shareCopyBtn"><?php echo e(t('update_history_share_copy', '复制链接')); ?></button>
        </div>
    </div>
</div>

<div class="card">
    <h2 class="card-title mb-1"><?php echo e(t('update_settings', '更新设置')); ?></h2>
    <form method="POST" action="<?php echo site_url('admin/update_center'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="form-group">
            <label class="form-label" for="update_source_url"><?php echo e(t('update_source_url', '更新源地址')); ?></label>
            <input type="text" class="form-control" id="update_source_url" name="update_source_url"
                   value="<?php echo e($updateSourceUrl); ?>" placeholder="https://update.example.com/yunjie"
                   style="max-width: 480px;">
            <p class="form-hint"><?php echo e(t('update_source_url_hint', '支持两种格式：<br>① 目录地址（如 https://example.com/updates）→ 自动拼接 /{通道}/version.json<br>② 直链文件（如 .txt/.json 结尾）→ 直接作为版本信息读取，内容为 JSON 或纯文本版本号。留空则无法进行更新检查。')); ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="update_channel"><?php echo e(t('update_channel', '更新通道')); ?></label>
            <select class="form-control" id="update_channel" name="update_channel" style="max-width: 260px;">
                <option value="stable" <?php echo $updateChannel === 'stable' ? 'selected' : ''; ?>><?php echo e(t('update_channel_stable', '稳定版（stable）')); ?></option>
                <option value="beta" <?php echo $updateChannel === 'beta' ? 'selected' : ''; ?>><?php echo e(t('update_channel_beta', '测试版（beta）')); ?></option>
                <option value="dev" <?php echo $updateChannel === 'dev' ? 'selected' : ''; ?>><?php echo e(t('update_channel_dev', '开发版（dev）')); ?></option>
            </select>
            <p class="form-hint"><?php echo e(t('update_channel_hint', '稳定版经过完整测试；测试版/开发版可能包含未稳定的功能，仅建议在测试环境启用。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" id="update_ssl_verify" name="update_ssl_verify" value="1" <?php echo $updateSslVerify ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_ssl_verify', '严格校验 SSL 证书')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_ssl_verify_hint', '默认开启校验，防止更新包在传输中被中间人篡改。若更新源使用自签名证书（如大部分个人服务器），可关闭校验。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" id="update_skip_hash" name="update_skip_hash" value="1" <?php echo $updateSkipHash ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_skip_hash', '跳过哈希校验（不推荐）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_skip_hash_hint', '默认强制校验更新包 SHA256 哈希以防篡改。若你的更新源（如网盘）不方便提供哈希值，且仅在完全信任该源时，可开启此选项跳过校验。开启后存在被篡改的更新包覆盖本站的风险。')); ?></p>
        </div>

        <div class="form-group">
            <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" id="update_auto_enabled" name="update_auto_enabled" value="1" <?php echo $updateAutoEnabled ? 'checked' : ''; ?>>
                <span style="font-weight:600;"><?php echo e(t('update_auto_enabled', '启用自动更新（自动下载并安装）')); ?></span>
            </label>
            <p class="form-hint"><?php echo e(t('update_auto_enabled_hint', '开启后，系统会按下方间隔自动检查并在发现新版本时自动下载、备份并覆盖升级。升级前会自动创建代码备份，可随时从「数据备份」恢复。')); ?></p>
            <label class="form-label mt-3" for="update_auto_interval"><?php echo e(t('update_auto_interval', '自动更新间隔（小时）')); ?></label>
            <input type="number" class="form-control" id="update_auto_interval" name="update_auto_interval"
                   value="<?php echo e($updateAutoInterval); ?>" min="1" max="720" style="max-width: 200px;">
            <p class="form-hint"><?php echo e(t('update_auto_interval_hint', '距离上次检查/更新超过该小时数后，再次访问后台将触发自动检查与安装。建议 24（每天一次）。')); ?></p>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo e(t('settings_save', '保存设置')); ?></button>
    </form>
</div>

<!-- 历史更新备份列表（位于更新设置下方；可下载 / 分享 / 删除） -->
<div class="card mt-2" id="updateHistoryCard">
    <div class="card-header">
        <h2 class="card-title"><?php echo e(t('update_history_title', '历史更新备份')); ?></h2>
        <span class="backup-refresh-hint">
            <?php echo t('update_history_count_prefix', '共 '); ?><strong id="updateHistoryCount"><?php echo count($updateBackups); ?></strong><?php echo t('update_history_count_suffix', ' 个备份'); ?>
        </span>
    </div>
    <p class="form-hint mt-1"><?php echo e(t('update_history_desc', '每次更新前系统会自动创建代码备份（update_pre_*.zip），包含 app/、public/ 及入口文件，不包含 data/ 数据。可下载留存、分享给他人或删除以释放空间。')); ?></p>
    <div class="backup-list">
        <div class="backup-empty" id="updateHistoryEmpty" <?php echo $updateBackups ? 'style="display:none;"' : ''; ?>>
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;margin-bottom:0.5rem;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            <p><?php echo e(t('update_history_empty', '暂无历史更新备份。执行一次系统更新后，这里会列出更新前自动创建的代码备份。')); ?></p>
        </div>
        <div class="backup-table-wrap" id="updateHistoryTableWrap" <?php echo $updateBackups ? '' : 'style="display:none;"'; ?>>
            <table class="backup-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('admin_backup_th_filename', '文件名')); ?></th>
                        <th><?php echo e(t('admin_backup_th_created', '创建时间')); ?></th>
                        <th><?php echo e(t('admin_backup_th_size', '大小')); ?></th>
                        <th><?php echo e(t('admin_backup_th_actions', '操作')); ?></th>
                    </tr>
                </thead>
                <tbody id="updateHistoryBody">
                    <?php foreach ($pagedUpdateBackups as $ub): ?>
                        <tr data-filename="<?php echo e($ub['filename']); ?>">
                            <td class="backup-cell-filename" title="<?php echo e($ub['filename']); ?>"><?php echo e($ub['filename']); ?></td>
                            <td><?php echo e(date('Y-m-d H:i:s', $ub['time'])); ?></td>
                            <td><?php echo e(uc_format_bytes($ub['size'])); ?></td>
                            <td class="backup-cell-actions">
                                <a href="<?php echo site_url('admin/update_center', ['action' => 'download', 'filename' => $ub['filename'], 'token' => admin_backup_download_token($ub['filename'])]); ?>" class="btn btn-secondary btn-sm backup-action-btn" title="<?php echo e(t('admin_backup_btn_download', '下载')); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <?php echo e(t('admin_backup_btn_download', '下载')); ?>
                                </a>
                                <button type="button" class="btn btn-secondary btn-sm backup-action-btn update-history-share-btn" data-filename="<?php echo e($ub['filename']); ?>" title="<?php echo e(t('update_history_share', '分享')); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                                    <?php echo e(t('update_history_share', '分享')); ?>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm backup-action-btn update-history-delete-btn is-danger" data-filename="<?php echo e($ub['filename']); ?>" title="<?php echo e(t('admin_backup_btn_delete', '删除')); ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    <?php echo e(t('admin_backup_btn_delete', '删除')); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($historyTotalPages > 1): ?>
        <div style="padding:0 1.25rem 1.25rem;">
            <?php echo pagination($historyPage, $historyTotal, $historyPerPage, site_url('admin/update_center')); ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var checkBtn = document.getElementById('checkBtn');
    var updateBtn = document.getElementById('updateBtn');
    var statusBox = document.getElementById('updateStatus');
    var currentEl = document.getElementById('currentVersion');
    var lastEl = document.getElementById('lastCheck');
    // 强制更新模式：当前已是最新（版本号相同）时仍允许重新下载安装
    var forceMode = false;

    function showStatus(html, type) {
        statusBox.style.display = '';
        statusBox.className = 'update-status' + (type ? ' is-' + type : '');
        statusBox.innerHTML = html;
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function fmtSize(n) {
        n = parseInt(n, 10) || 0;
        if (n >= 1048576) return (n / 1048576).toFixed(2) + ' MB';
        if (n >= 1024) return (n / 1024).toFixed(2) + ' KB';
        return n + ' B';
    }

    checkBtn.addEventListener('click', function () {
        checkBtn.disabled = true;
        checkBtn.innerHTML = '<?php echo e(t('update_checking', '检查中…')); ?>';
        updateBtn.disabled = true;
        fetch('/index.php?route=admin/api/update_ajax&action=check')
            .then(function (r) {
                return r.json().catch(function () {
                    // 响应不是 JSON（如 PHP 500 输出 HTML 错误页）→ 抛出带状态码的对象，由下方 .catch 统一呈现
                    throw { __httpStatus: r.status, __raw: r };
                });
            })
            .then(function (res) {
                if (!res.success) {
                    var msg = res.error === 'update_source_not_configured'
                        ? '<?php echo e(t('update_no_source', '尚未配置更新源地址，请先在下方填写。')); ?>'
                        : ('<?php echo e(t('update_check_error', '检查失败：')); ?>' + escapeHtml(res.error || ''));
                    // 显示额外调试信息
                    if (res.debug_keys) msg += ' [keys: ' + escapeHtml(res.debug_keys.join(', ')) + ']';
                    if (res.preview) msg += '<br><small style="color:var(--text-muted);word-break:break-all">' + escapeHtml(res.preview) + '</small>';
                    // 完整诊断输出：折叠块展示关键字段（放宽行数上限），并提供独立诊断页入口
                    if (res.details) msg += diagBlock(res.details, 60);
                    if (res.error !== 'update_source_not_configured') {
                        msg += '<br><a href="<?php echo e(site_url('admin/update_center', ['action' => 'check_diag'])); ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="margin-top:.5rem;display:inline-block;"><?php echo e(t('update_diag_open_btn', '在新页面查看完整诊断')); ?></a>';
                    }
                    showStatus(msg, 'error');
                    return;
                }
                currentEl.textContent = res.current;
                lastEl.textContent = new Date((res.checked_at || Date.now() / 1000) * 1000).toLocaleString();
                if (res.update_available) {
                    forceMode = false;
                    updateBtn.innerHTML = '<?php echo e(t('update_update_now', '立即更新')); ?>';
                    var html = '<div class="update-avail">'
                        + '<strong><?php echo e(t('update_new_available', '发现新版本')); ?> ' + escapeHtml(res.latest) + '</strong>'
                        + (res.release_date ? ' <span class="update-date">(' + escapeHtml(res.release_date) + ')</span>' : '')
                        + (res.size ? ' — ' + fmtSize(res.size) : '')
                        + '</div>';
                    if (res.changelog) {
                        html += '<div class="update-changelog"><pre>' + escapeHtml(res.changelog) + '</pre></div>';
                    }
                    if (res.requires_php) {
                        html += '<div class="update-req"><?php echo e(t('update_requires_php', '要求 PHP')); ?> ' + escapeHtml(res.requires_php) + '</div>';
                    }
                    showStatus(html, 'warn');
                    updateBtn.disabled = false;
                } else {
                    // 已是最新：允许「强制更新」重新应用更新包（同版本覆盖安装）
                    forceMode = true;
                    updateBtn.innerHTML = '<?php echo e(t('update_force_install', '强制更新')); ?>';
                    updateBtn.disabled = false;
                    showStatus('<?php echo e(t('update_up_to_date', '已是最新版本（')); ?>' + escapeHtml(res.current) + '）' + '<?php echo e(t('update_up_to_date_force_hint', ' 如需重新应用更新包，可点击「强制更新」')); ?>', 'ok');
                }
            })
            .catch(function (err) {
                var msg = '<?php echo e(t('update_check_network_fail', '网络错误，检查失败。')); ?>';
                if (err && err.__httpStatus) {
                    // fetch 成功但响应非 JSON（PHP 500 错误页等）→ 展示真实 HTTP 状态码
                    msg = '<?php echo e(t('update_check_http_error', '服务器返回异常状态码 {code}（响应非 JSON，可能为 500 错误页），请打开下方诊断页排查。')); ?>'
                        .replace('{code}', String(err.__httpStatus));
                } else if (err && err.message) {
                    msg += ' ' + escapeHtml(err.message);
                }
                msg += '<br><a href="<?php echo e(site_url('admin/update_center', ['action' => 'check_diag'])); ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="margin-top:.5rem;display:inline-block;"><?php echo e(t('update_diag_open_btn', '在新页面查看完整诊断')); ?></a>';
                showStatus(msg, 'error');
            })
            .finally(function () {
                checkBtn.disabled = false;
                checkBtn.innerHTML = '<?php echo e(t('update_check_now', '检查更新')); ?>';
            });
    });

    // ===== 进度条相关 =====
    var progressWrap = document.getElementById('updateProgress');
    var progressBar  = document.getElementById('updateProgressBar');
    var progressStage = document.getElementById('updateProgressStage');
    var progressPct   = document.getElementById('updateProgressPct');
    var progressDetail = document.getElementById('updateProgressDetail');
    var progTimer     = null;

    function showProgress() {
        progressWrap.style.display = '';
        progressBar.style.width = '0%';
        progressBar.className = 'update-progress-bar-inner';
        progressDetail.innerHTML = '';
    }
    function hideProgress() {
        progressWrap.style.display = 'none';
        if (progTimer) { clearInterval(progTimer); progTimer = null; }
    }
    function updateProgressUI(p) {
        var pct = Math.min(100, Math.max(0, p.progress || 0));
        progressBar.style.width = pct + '%';
        progressPct.textContent = pct + '%';
        // 阶段文案映射
        var stageMap = {
            preparing:   '<?php echo e(t('update_progress_preparing', '准备中…')); ?>',
            downloading: '<?php echo e(t('update_progress_downloading', '下载更新包…')); ?>',
            verifying:   '<?php echo e(t('update_progress_verifying', '校验文件完整性…')); ?>',
            backing_up:  '<?php echo e(t('update_progress_backing_up', '备份当前代码…')); ?>',
            verifying_pkg:'<?php echo e(t('update_progress_verifying_pkg', '校验包内版本…')); ?>',
            extracting:  '<?php echo e(t('update_progress_extracting', '解压并覆盖文件…')); ?>',
            done:        '<?php echo e(t('update_progress_done', '更新完成')); ?>',
            error:       '<?php echo e(t('update_progress_error', '出错')); ?>'
        };
        progressStage.textContent = stageMap[p.stage] || p.stage || '';
        // 下载详情
        if (p.stage === 'downloading' && p.total > 0) {
            progressDetail.textContent = fmtSize(p.downloaded || 0) + ' / ' + fmtSize(p.total);
        } else {
            progressDetail.textContent = '';
        }
        // 完成变绿 / 出错变红
        if (p.done && p.stage === 'done') {
            progressBar.classList.add('is-done');
            progressStage.classList.add('is-done');
        } else if (p.done && p.stage === 'error') {
            progressBar.classList.add('is-error');
            progressStage.classList.add('is-error');
        }
    }
    function startProgressPolling() {
        showProgress();
        // 立即拉一次
        fetch('/index.php?route=admin/api/update_ajax&action=progress')
            .then(function(r){return r.json();}).then(updateProgressUI).catch(function(){});
        // 每 800ms 轮询
        progTimer = setInterval(function () {
            fetch('/index.php?route=admin/api/update_ajax&action=progress')
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    updateProgressUI(p);
                    if (p.done) { hideProgress(); }
                })
                .catch(function () {});
        }, 800);
    }

    function doUpdate() {
        updateBtn.disabled = true;
        updateBtn.innerHTML = '<?php echo e(t('update_updating', '更新中…')); ?>';
        statusBox.style.display = 'none';
        // 启动进度轮询（后端 uc_perform_update 会写进度文件）
        startProgressPolling();
        var form = new FormData();
        form.append('action', 'update');
        form.append('force', forceMode ? '1' : '0');
        form.append('csrf_token', '<?php echo csrf_token(); ?>');
        fetch('/index.php?route=admin/api/update_ajax', {
            method: 'POST',
            body: form
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                // 停止轮询，确保最终状态同步
                hideProgress();
                if (res.success) {
                    showStatus('<?php echo e(t('update_success', '更新成功：')); ?>' + escapeHtml(res.from) + ' → ' + escapeHtml(res.to)
                        + '<br><?php echo e(t('update_backup_at', '备份文件')); ?>：' + escapeHtml(res.backup ? res.backup.split(/[\\/]/).pop() : '') + ''
                        + '（' + (res.files || 0) + ' <?php echo e(t('update_files', '个文件')); ?>）', 'ok');
                    currentEl.textContent = res.to;
                    forceMode = false;
                    updateBtn.disabled = true;
                    // 更新成功后刷新历史更新备份列表（新增了一份更新前备份）
                    refreshBackupHistory();
                } else {
                    var msg = '<?php echo e(t('update_failed', '更新失败')); ?>';
                    if (res.error === 'no_update_available') msg = '<?php echo e(t('update_none', '当前已是最新，无需更新。')); ?>';
                    else if (res.error === 'no_package_url') msg = '<?php echo e(t('update_no_package_url', '更新源未提供更新包地址（package_url）。请将更新包命名为 update.zip 放到「{通道}/」目录下，或在 version.json 中加入 package_url 字段。')); ?>';
                    else if (res.error === 'no_package_hash') msg = '<?php echo e(t('update_no_package_hash', '更新包缺少哈希校验值（package_hash）。为安全起见默认禁止无校验更新。请在 version.json 中加入 package_hash（sha256 值）后重试，或在「更新设置」中开启「跳过哈希校验」。')); ?>';
                    else if (res.error === 'hash_mismatch') msg = '<?php echo e(t('update_hash_fail', '更新包校验失败（哈希不匹配），已自动取消以保障安全。')); ?>';
                    else if (res.error === 'package_version_mismatch') msg = '<?php echo e(t('update_pkg_version_mismatch', '更新包版本名不副实：包内版本 {pkg} ≠ 声明版本 {declared}，已取消更新。请重新制作更新包（config.php 的 APP_VERSION 与 version.json 保持一致）。')); ?>'.replace('{pkg}', escapeHtml(res.package_version || '')).replace('{declared}', escapeHtml(res.declared || ''));
                    else if (res.error && res.error.indexOf('extract_failed') === 0) {
                        msg = extractFailedMsg(res);
                    }
                    else if (res.error === 'backup_failed') msg = '<?php echo e(t('update_backup_err', '更新前备份失败，已取消更新以防数据丢失。')); ?>';
                    else if (res.error && res.error.indexOf('check_failed') === 0) msg = '<?php echo e(t('update_check_failed', '检查更新失败（网络错误或更新源不可用）：')); ?>' + escapeHtml((res.error || '').replace('check_failed: ', ''));
                    else msg += '：' + escapeHtml(res.error || '');
                    if (res.hint) msg += '<br><small style="color:var(--text-muted);word-break:break-word">' + escapeHtml(res.hint) + '</small>';
                    if (res.backup) msg += '<br><?php echo e(t('update_backup_kept', '已保留备份')); ?>：' + escapeHtml(res.backup.split(/[\\/]/).pop());
                    if (res.details) msg += diagBlock(res.details);
                    showStatus(msg, 'error');
                    updateBtn.disabled = false;
                }
            })
            .catch(function () {
                hideProgress();
                showStatus('<?php echo e(t('update_network_fail', '网络错误，更新失败。')); ?>', 'error');
                updateBtn.disabled = false;
            })
            .finally(function () {
                updateBtn.innerHTML = (forceMode ? '<?php echo e(t('update_force_install', '强制更新')); ?>' : '<?php echo e(t('update_update_now', '立即更新')); ?>');
            });
    }

    // 自定义确认对话框（替代原生 confirm，更新确认与删除备份确认共用）
    var confirmModal = document.getElementById('updateConfirmModal');
    var confirmTitleEl = document.getElementById('updateConfirmTitle');
    var confirmTextEl = document.getElementById('updateConfirmText');
    var confirmSafeEl = document.getElementById('updateConfirmSafe');
    var confirmOkBtn = document.getElementById('updateConfirmOk');
    var pendingConfirmOk = null;
    function openConfirm(title, text, showSafeHint, onOk, okText) {
        confirmTitleEl.textContent = title;
        confirmTextEl.textContent = text;
        confirmTextEl.style.whiteSpace = showSafeHint ? '' : 'pre-line';
        confirmSafeEl.style.display = showSafeHint ? '' : 'none';
        confirmOkBtn.textContent = okText || '<?php echo e(t('update_confirm_ok', '确认更新')); ?>';
        // 破坏性操作（如删除）用红色主按钮，与「确认更新」区分
        confirmOkBtn.classList.toggle('btn-primary', showSafeHint);
        confirmOkBtn.classList.toggle('btn-danger', !showSafeHint);
        pendingConfirmOk = onOk;
        confirmModal.style.display = 'flex';
    }
    function closeUpdateConfirm() { confirmModal.style.display = 'none'; pendingConfirmOk = null; }

    updateBtn.addEventListener('click', function () {
        // 强制模式下切换确认框文案
        openConfirm(
            (forceMode ? '<?php echo e(t('update_confirm_force_title', '确认强制更新')); ?>' : '<?php echo e(t('update_confirm_title', '确认立即更新')); ?>'),
            (forceMode ? '<?php echo e(t('update_confirm_force', '当前已是最新版本，确定要强制重新安装吗？将重新下载更新包并覆盖代码（data/ 配置与数据不会被覆盖，升级前自动备份）。')); ?>' : '<?php echo e(t('update_confirm', '确定要立即更新吗？系统将先备份当前代码再覆盖升级。')); ?>'),
            true,
            doUpdate,
            '<?php echo e(t('update_confirm_ok', '确认更新')); ?>'
        );
    });
    document.getElementById('updateConfirmOk').addEventListener('click', function () {
        var cb = pendingConfirmOk;
        closeUpdateConfirm();
        if (typeof cb === 'function') { cb(); }
    });
    document.getElementById('updateConfirmCancel').addEventListener('click', closeUpdateConfirm);
    document.getElementById('updateConfirmClose').addEventListener('click', closeUpdateConfirm);
    confirmModal.addEventListener('click', function (e) {
        if (e.target === confirmModal) { closeUpdateConfirm(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && confirmModal.style.display !== 'none') { closeUpdateConfirm(); }
    });

    // ===== 历史更新备份：删除 / 列表刷新 =====
    var historyCsrf = '<?php echo csrf_token(); ?>';

    function bindHistoryDeleteBtn(btn) {
        btn.addEventListener('click', function () {
            var filename = btn.dataset.filename;
            openConfirm(
                <?php echo json_encode(t('admin_backup_js_confirm_delete_title', '删除备份')); ?>,
                <?php echo json_encode(t('admin_backup_js_confirm_delete_msg', '确定要删除备份文件 "{name}" 吗？')); ?>.replace('{name}', filename) + '\n' + <?php echo json_encode(t('admin_backup_js_confirm_delete_warn', '此操作不可恢复，删除后无法找回该备份。')); ?>,
                false,
                function () {
                    var form = new FormData();
                    form.append('action', 'backup_delete');
                    form.append('csrf_token', historyCsrf);
                    form.append('filename', filename);
                    fetch('/index.php?route=admin/api/update_ajax', { method: 'POST', body: form })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                refreshBackupHistory();
                            } else {
                                showStatus('<?php echo e(t('admin_backup_js_delete_failed', '删除失败：')); ?>' + escapeHtml(res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                            }
                        })
                        .catch(function () { showStatus(<?php echo json_encode(t('admin_backup_js_network_delete_fail', '网络错误，删除失败。')); ?>, 'error'); });
                },
                <?php echo json_encode(t('update_history_delete_ok', '确认删除')); ?>
            );
        });
    }

    // ===== 历史更新备份：分享 =====
    var shareModal = document.getElementById('shareModal');
    var shareUrlInput = document.getElementById('shareUrlInput');
    var shareExpiresEl = document.getElementById('shareExpires');
    var shareCopyBtn = document.getElementById('shareCopyBtn');
    function closeShareModal() { shareModal.style.display = 'none'; }
    shareCopyBtn.addEventListener('click', function () {
        var url = shareUrlInput.value;
        var done = function () {
            shareCopyBtn.textContent = '<?php echo e(t('update_history_share_copied', '已复制')); ?>';
            setTimeout(function () {
                shareCopyBtn.textContent = '<?php echo e(t('update_history_share_copy', '复制链接')); ?>';
            }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done).catch(function () {
                shareUrlInput.select();
                document.execCommand('copy');
                done();
            });
        } else {
            shareUrlInput.select();
            document.execCommand('copy');
            done();
        }
    });
    document.getElementById('shareModalCancel').addEventListener('click', closeShareModal);
    document.getElementById('shareModalClose').addEventListener('click', closeShareModal);
    shareModal.addEventListener('click', function (e) {
        if (e.target === shareModal) { closeShareModal(); }
    });

    // 将服务端生成的绝对链接的域名替换为浏览器地址栏的「当前访问域名」：
    // 服务端拿到的 HTTP_HOST 是请求头域名，CDN/反代改写 Host 时可能与实际访问域名不一致；
    // 以 window.location 为准，路径与参数保持不变。
    function toCurrentAccessDomain(absUrl) {
        try {
            var u = new URL(absUrl);
            if (u.origin !== window.location.origin) {
                u.protocol = window.location.protocol;
                u.host = window.location.host;
            }
            return u.href;
        } catch (e) {
            return absUrl;
        }
    }

    function bindHistoryShareBtn(btn) {
        btn.addEventListener('click', function () {
            var filename = btn.dataset.filename;
            btn.disabled = true;
            var form = new FormData();
            form.append('action', 'backup_share');
            form.append('csrf_token', historyCsrf);
            form.append('filename', filename);
            fetch('/index.php?route=admin/api/update_ajax', { method: 'POST', body: form })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        shareUrlInput.value = toCurrentAccessDomain(res.url);
                        shareExpiresEl.textContent = '<?php echo e(t('update_history_share_expires_prefix', '链接有效期至：')); ?>' + new Date(res.expires * 1000).toLocaleString();
                        shareModal.style.display = 'flex';
                    } else {
                        showStatus('<?php echo e(t('update_history_share_failed', '生成分享链接失败：')); ?>' + escapeHtml(res.error || <?php echo json_encode(t('admin_backup_js_unknown_error', '未知错误')); ?>), 'error');
                    }
                })
                .catch(function () { showStatus(<?php echo json_encode(t('update_history_share_network_fail', '网络错误，生成分享链接失败。')); ?>, 'error'); })
                .finally(function () { btn.disabled = false; });
        });
    }

    function refreshBackupHistory() {
        // 重新拉取当前页 HTML，仅替换历史更新备份卡片（含分页），
        // 与服务端渲染保持完全一致；页码超界时后端会自动夹取到最后一页
        fetch(window.location.href, { cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newCard = doc.getElementById('updateHistoryCard');
                var curCard = document.getElementById('updateHistoryCard');
                if (newCard && curCard) {
                    curCard.innerHTML = newCard.innerHTML;
                    curCard.querySelectorAll('.update-history-delete-btn').forEach(bindHistoryDeleteBtn);
                    curCard.querySelectorAll('.update-history-share-btn').forEach(bindHistoryShareBtn);
                }
            })
            .catch(function () {});
    }

    // ===== 手动上传更新包 =====
    var uploadInput   = document.getElementById('uploadPkgInput');
    var uploadBtn     = document.getElementById('uploadPkgBtn');
    var uploadInfo    = document.getElementById('uploadPkgInfo');
    var installRow    = document.getElementById('uploadInstallRow');
    var installBtn    = document.getElementById('installUploadBtn');
    var uploadResult  = document.getElementById('uploadResult');
    var lastUpload    = null; // { version, current, relation, files, size_text }

    // ===== 上传/安装进度面板 =====
    // 上传阶段：XHR upload progress 实时百分比/已传字节/速度（fetch 不支持上传进度）；
    // 安装阶段：轮询 action=progress（后端 uc_perform_upload_update 写进度文件）。
    var upkgProgWrap   = document.getElementById('uploadPkgProgress');
    var upkgProgStage  = document.getElementById('uploadPkgProgStage');
    var upkgProgPct    = document.getElementById('uploadPkgProgPct');
    var upkgProgBar    = document.getElementById('uploadPkgProgBar');
    var upkgProgDetail = document.getElementById('uploadPkgProgDetail');
    var upkgProgTimer  = null;
    var upkgProgTxt = {
        uploading:  '<?php echo e(t('update_upload_prog_uploading', '正在上传…')); ?>',
        parsing:    '<?php echo e(t('update_upload_prog_parsing', '上传完成，服务器解析中…')); ?>',
        speed:      '<?php echo e(t('update_upload_prog_speed', '速度')); ?>',
        installing: '<?php echo e(t('update_upload_installing', '正在安装…')); ?>'
    };
    // 安装阶段后端 stage → 文案（与「立即更新」进度条共用同一套词）
    var upkgStageMap = {
        preparing:     '<?php echo e(t('update_progress_preparing', '准备中…')); ?>',
        backing_up:    '<?php echo e(t('update_progress_backing_up', '备份当前代码…')); ?>',
        verifying_pkg: '<?php echo e(t('update_progress_verifying_pkg', '校验包内版本…')); ?>',
        extracting:    '<?php echo e(t('update_progress_extracting', '解压并覆盖文件…')); ?>',
        done:          '<?php echo e(t('update_progress_done', '更新完成')); ?>',
        error:         '<?php echo e(t('update_progress_error', '出错')); ?>'
    };
    function upkgProgShow(stageText) {
        upkgProgWrap.style.display = '';
        upkgProgStage.textContent = stageText;
        upkgProgPct.textContent = '0%';
        upkgProgBar.style.width = '0%';
        upkgProgDetail.textContent = '';
    }
    function upkgProgSet(stageText, pct, detail) {
        upkgProgStage.textContent = stageText;
        upkgProgPct.textContent = pct + '%';
        upkgProgBar.style.width = pct + '%';
        upkgProgDetail.textContent = detail || '';
    }
    function upkgProgHide() {
        upkgProgWrap.style.display = 'none';
        if (upkgProgTimer) { clearInterval(upkgProgTimer); upkgProgTimer = null; }
    }
    function upkgInstallPollStart() {
        upkgProgShow(upkgProgTxt.installing);
        var poll = function () {
            fetch('/index.php?route=admin/api/update_ajax&action=progress')
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    if (!p || !p.stage) return;
                    var pct = Math.min(100, Math.max(0, p.progress || 0));
                    upkgProgSet(upkgStageMap[p.stage] || p.stage, pct, '');
                    if (p.done && upkgProgTimer) { clearInterval(upkgProgTimer); upkgProgTimer = null; }
                })
                .catch(function () {});
        };
        poll();
        upkgProgTimer = setInterval(poll, 800);
    }

    // 渲染后端返回的诊断信息（details）为可折叠块，便于排查上传/安装失败根因
    // maxLines 控制最多展示的字段行数（检查失败等场景可放宽，完整内容在独立诊断页）
    function diagBlock(d, maxLines) {
        if (!d) return '';
        if (typeof maxLines !== 'number' || maxLines < 1) maxLines = 16;
        var lines = [];
        (function walk(obj, prefix) {
            if (lines.length >= maxLines) return;
            for (var k in obj) {
                if (!Object.prototype.hasOwnProperty.call(obj, k) || lines.length >= maxLines) continue;
                var v = obj[k];
                if (v === null || v === undefined || v === '') continue;
                var label = prefix ? prefix + '.' + k : k;
                if (typeof v === 'object') { walk(v, label); }
                else { lines.push(escapeHtml(label + ': ' + v)); }
            }
        })(d, '');
        if (!lines.length) return '';
        return '<details style="margin-top:.5rem;font-size:.8rem;color:var(--text-muted,#666);word-break:break-all;">'
            + '<summary><?php echo e(t('update_upload_diag', '诊断信息')); ?></summary>'
            + lines.join('<br>')
            + '</details>';
    }

    // 解压失败的详细错误输出：前 8 条失败条目内联展示 + 独立诊断页入口
    // （诊断页含逐条失败原因、目录权限与修复建议，Windows/Linux 适配）
    function extractFailedMsg(res) {
        var msg = '<?php echo e(t('update_extract_failed', '更新文件解压失败')); ?>'
            + (res.failed && res.failed.length ? '（' + res.failed.length + ' <?php echo e(t('update_extract_err_items_unit', '个')); ?>）' : '')
            + '<?php echo e(t('update_extract_err_inline_hint', '，更新不完整，请检查文件权限或从备份恢复。')); ?>';
        if (res.failed && res.failed.length) {
            msg += '<br><small style="color:var(--text-muted);word-break:break-word">'
                + res.failed.slice(0, 8).map(escapeHtml).join('<br>') + '</small>';
        }
        msg += '<br><a href="<?php echo e(site_url('admin/update_center', ['action' => 'extract_error'])); ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="margin-top:.5rem;display:inline-block;"><?php echo e(t('update_extract_view_detail', '在新页面查看解压失败详情（含目录权限）')); ?></a>';
        return msg;
    }

    function showUploadResult(html, type) {
        uploadResult.style.display = '';
        uploadResult.className = 'update-status mt-2' + (type ? ' is-' + type : '');
        uploadResult.innerHTML = html;
    }

    uploadBtn.addEventListener('click', function () {
        if (!uploadInput.files || !uploadInput.files.length) {
            showUploadResult('<?php echo e(t('update_upload_no_file', '请先选择 .zip 更新包文件。')); ?>', 'error');
            return;
        }
        var file = uploadInput.files[0];
        if (!/\.zip$/i.test(file.name)) {
            showUploadResult('<?php echo e(t('update_upload_err_not_zip', '仅支持 .zip 格式的更新包。')); ?>', 'error');
            return;
        }
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = upkgProgTxt.uploading;
        uploadInfo.style.display = 'none';
        installRow.style.display = 'none';
        uploadResult.style.display = 'none';
        upkgProgShow(upkgProgTxt.uploading);

        var restoreUploadBtn = function () {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<?php echo e(t('update_upload_parse', '上传并解析')); ?>';
        };

        // 解析结果渲染（错误映射与原逻辑一致）
        var handleInspectResult = function (res) {
            upkgProgHide();
            if (!res.success) {
                    var errMsg = res.error === 'no_file' || res.error === 'upload_err_no_file'
                        ? '<?php echo e(t('update_upload_err_no_file', '未收到上传文件。')); ?>'
                        : res.error === 'not_zip'
                            ? '<?php echo e(t('update_upload_err_not_zip', '仅支持 .zip 格式的更新包。')); ?>'
                            : res.error === 'file_too_large'
                                ? '<?php echo e(t('update_upload_err_too_large', '文件过大（超过 256MB）。')); ?>'
                                : res.error === 'invalid_zip'
                                    ? '<?php echo e(t('update_upload_err_invalid_zip', '上传的 ZIP 文件无效或已损坏。')); ?>'
                                    : (res.error === 'upload_err_ini_size' || res.error === 'upload_err_form_size')
                                        ? '<?php echo e(t('update_upload_err_php_limit', '文件超过 PHP 上传限制（upload_max_filesize / post_max_size），请调大 php.ini 对应配置后重试。')); ?>'
                                        : (res.error === 'upload_err_no_tmp_dir' || res.error === 'upload_err_cant_write')
                                            ? '<?php echo e(t('update_upload_err_tmp_dir', 'PHP 临时上传目录（upload_tmp_dir）不可用或不可写，请检查 php.ini 与 php-fpm 运行用户权限。')); ?>'
                                            : res.error === 'tmp_dir_not_writable'
                                                ? '<?php echo e(t('update_upload_err_data_tmp', 'data/tmp 目录不存在或不可写，请检查目录权限（Web 用户需可写）。')); ?>'
                                                : res.error === 'zip_extension_missing'
                                                    ? '<?php echo e(t('update_upload_err_zip_ext', '服务器未启用 ZipArchive 扩展（php-zip），请安装后重试。')); ?>'
                                                    : res.error === 'upload_err_partial'
                                                        ? '<?php echo e(t('update_upload_err_partial', '文件上传不完整（网络中断或代理截断），请重新上传。')); ?>'
                                                        : ('<?php echo e(t('update_upload_parse_failed', '解析失败：')); ?>' + escapeHtml(res.error || ''));
                    if (res.details) errMsg += diagBlock(res.details);
                    showUploadResult(errMsg, 'error');
                    restoreUploadBtn();
                    return;
                }
                lastUpload = res;
                var relText = '';
                if (res.relation === 'upgrade') relText = '<?php echo e(t('update_upload_relation_upgrade', '（升级）')); ?>';
                else if (res.relation === 'same') relText = '<?php echo e(t('update_upload_relation_same', '（与当前版本相同，将重新安装）')); ?>';
                else if (res.relation === 'downgrade') relText = '<?php echo e(t('update_upload_relation_downgrade', '（低于当前版本，将执行降级）')); ?>';
                else relText = '<?php echo e(t('update_upload_relation_unknown', '（无法识别包内版本）')); ?>';
                uploadInfo.innerHTML =
                    '<div style="display:grid;grid-template-columns:auto 1fr;gap:.25rem .75rem;font-size:.9rem;">'
                    + '<span style="color:var(--text-muted);"><?php echo e(t('update_upload_pkg_version', '包内版本')); ?></span><span><strong>' + escapeHtml(res.version || '?') + '</strong> ' + relText + '</span>'
                    + '<span style="color:var(--text-muted);"><?php echo e(t('update_upload_current_version', '当前版本')); ?></span><span>' + escapeHtml(res.current) + '</span>'
                    + '<span style="color:var(--text-muted);"><?php echo e(t('update_upload_files_count', '文件数')); ?></span><span>' + escapeHtml(res.files) + '</span>'
                    + '<span style="color:var(--text-muted);"><?php echo e(t('update_upload_size', '大小')); ?></span><span>' + escapeHtml(res.size_text || fmtSize(res.size)) + '</span>'
                    + '</div>';
                uploadInfo.style.display = '';
                installRow.style.display = '';
                uploadResult.style.display = 'none';
                restoreUploadBtn();
        };

        // 改用 XMLHttpRequest：fetch 不支持上传进度，XHR 可获得 upload progress 事件
        var xhr = new XMLHttpRequest();
        var upkgLastLoaded = 0, upkgLastTs = Date.now();
        if (xhr.upload) {
            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable || ev.total <= 0) return;
                var pct = Math.min(99, Math.floor(ev.loaded / ev.total * 100));
                var detail = fmtSize(ev.loaded) + ' / ' + fmtSize(ev.total);
                var now = Date.now();
                if (now - upkgLastTs >= 500 && ev.loaded > upkgLastLoaded) {
                    var bps = (ev.loaded - upkgLastLoaded) / ((now - upkgLastTs) / 1000);
                    detail += ' · ' + upkgProgTxt.speed + ' ' + fmtSize(Math.round(bps)) + '/s';
                    upkgLastLoaded = ev.loaded;
                    upkgLastTs = now;
                }
                upkgProgSet(upkgProgTxt.uploading, pct, detail);
            });
            // 上传完成 → 等待服务端保存与解析（进度停 100%）
            xhr.upload.addEventListener('load', function () {
                upkgProgSet(upkgProgTxt.parsing, 100, '');
            });
        }
        xhr.open('POST', '/index.php?route=admin/api/update_ajax');
        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
            if (!res || typeof res !== 'object') {
                upkgProgHide();
                showUploadResult('<?php echo e(t('update_upload_parse_network_fail', '网络错误，解析失败。')); ?>', 'error');
                restoreUploadBtn();
                return;
            }
            handleInspectResult(res);
        };
        xhr.onerror = function () {
            upkgProgHide();
            showUploadResult('<?php echo e(t('update_upload_parse_network_fail', '网络错误，解析失败。')); ?>', 'error');
            restoreUploadBtn();
        };
        var form = new FormData();
        form.append('action', 'upload_inspect');
        form.append('csrf_token', historyCsrf);
        form.append('file', file);
        xhr.send(form);
    });

    installBtn.addEventListener('click', function () {
        if (!lastUpload) return;
        var confirmTitle = '<?php echo e(t('update_upload_confirm_title', '确认安装上传的更新包')); ?>';
        var confirmText = '<?php echo e(t('update_upload_confirm', '确定要安装上传的更新包吗？系统将先自动备份当前代码，再覆盖升级。')); ?>';
        if (lastUpload.relation === 'downgrade') {
            confirmText = '<?php echo e(t('update_upload_confirm_downgrade', '该更新包版本低于当前版本，确定要执行降级安装吗？系统将先自动备份当前代码，再覆盖升级。')); ?>';
        } else if (lastUpload.relation === 'unknown') {
            confirmText = '<?php echo e(t('update_upload_confirm_unknown', '无法识别该更新包的版本，安装后可能无法正确记录版本号。确定要继续吗？系统将先自动备份当前代码，再覆盖升级。')); ?>';
        }
        openConfirm(confirmTitle, confirmText, true, doInstallUpload, '<?php echo e(t('update_upload_install_ok', '确认安装')); ?>');
    });

    function doInstallUpload() {
        installBtn.disabled = true;
        installBtn.innerHTML = '<?php echo e(t('update_upload_installing', '正在安装…')); ?>';
        uploadBtn.disabled = true;
        uploadResult.style.display = 'none';
        // 启动安装进度轮询（后端 uc_perform_upload_update 会写进度文件：备份 → 解压 → 完成）
        upkgInstallPollStart();
        var form = new FormData();
        form.append('action', 'install_upload');
        form.append('csrf_token', historyCsrf);
        fetch('/index.php?route=admin/api/update_ajax', { method: 'POST', body: form })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    // 安装成功：进度条定格 100% 后收起
                    upkgProgSet(upkgStageMap.done, 100, '');
                    if (upkgProgTimer) { clearInterval(upkgProgTimer); upkgProgTimer = null; }
                    setTimeout(upkgProgHide, 900);
                    showUploadResult('<?php echo e(t('update_upload_success', '更新包安装成功：')); ?>' + escapeHtml(res.from) + ' → ' + escapeHtml(res.to)
                        + '<br><?php echo e(t('update_backup_at', '备份文件')); ?>：' + escapeHtml(res.backup ? res.backup.split(/[\\/]/).pop() : '')
                        + '（' + (res.files || 0) + ' <?php echo e(t('update_files', '个文件')); ?>）', 'ok');
                    currentEl.textContent = res.to;
                    uploadInfo.style.display = 'none';
                    installRow.style.display = 'none';
                    lastUpload = null;
                    uploadInput.value = '';
                    refreshBackupHistory();
                } else {
                    upkgProgHide();
                    var msg = '<?php echo e(t('update_upload_failed', '更新包安装失败')); ?>';
                    if (res.error === 'bad_package') msg = '<?php echo e(t('update_upload_bad_pkg', '上传的压缩包不是有效的云界论坛更新包（缺少 app/includes/config.php 或无法识别版本）。')); ?>';
                    else if (res.error === 'pkg_not_found') msg = '<?php echo e(t('update_upload_pkg_lost', '上传的更新包已不存在，请重新上传。')); ?>';
                    else if (res.error && res.error.indexOf('backup_failed') === 0) msg = '<?php echo e(t('update_backup_err', '更新前备份失败，已取消更新以防数据丢失。')); ?>';
                    else if (res.error && res.error.indexOf('extract_failed') === 0) {
                        msg = extractFailedMsg(res);
                    }
                    else msg += '：' + escapeHtml(res.error || '');
                    if (res.backup) msg += '<br><?php echo e(t('update_backup_kept', '已保留备份')); ?>：' + escapeHtml(res.backup.split(/[\\/]/).pop());
                    if (res.details) msg += diagBlock(res.details);
                    showUploadResult(msg, 'error');
                }
            })
            .catch(function () { upkgProgHide(); showUploadResult('<?php echo e(t('update_upload_network_fail', '网络错误，安装失败。')); ?>', 'error'); })
            .finally(function () {
                installBtn.disabled = false;
                installBtn.innerHTML = '<?php echo e(t('update_upload_install', '安装此更新包')); ?>';
                uploadBtn.disabled = false;
            });
    }

    document.querySelectorAll('.update-history-delete-btn').forEach(bindHistoryDeleteBtn);
    document.querySelectorAll('.update-history-share-btn').forEach(bindHistoryShareBtn);
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
