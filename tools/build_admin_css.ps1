param()
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$tools = $PSScriptRoot
$out = Join-Path $root 'public\css\admin.css'
$enc = New-Object System.Text.UTF8Encoding($false)

function Read-Utf8($p) { return [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8).TrimEnd("`r", "`n") }

$sb = [System.Text.StringBuilder]::new()
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'admin_css_header.txt')))
$null = $sb.Append("`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\card_admin.css')))
$null = $sb.Append("`r`n`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\admin_section.css')))
$null = $sb.Append("`r`n`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\table_compact.css')))
$null = $sb.Append("`r`n`r`n")

# tablet wrapper
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'label_tablet.txt')))
$null = $sb.Append("`r`n@media (max-width: 1024px) {`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\resp_tablet_admin.css')))
$null = $sb.Append("`r`n}`r`n`r`n")

# mobile wrapper
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'label_mobile.txt')))
$null = $sb.Append("`r`n@media (max-width: 768px) {`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'admin_resp_mobile_inner.css')))
$null = $sb.Append("`r`n}`r`n`r`n")

# small wrapper
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'label_small.txt')))
$null = $sb.Append("`r`n@media (max-width: 640px) {`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'admin_resp_small_inner.css')))
$null = $sb.Append("`r`n}`r`n`r`n")

$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\modal_box.css')))
$null = $sb.Append("`r`n`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\mail_1.css')))
$null = $sb.Append("`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\mail_2.css')))
$null = $sb.Append("`r`n`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\risk.css')))
$null = $sb.Append("`r`n`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\users_1.css')))
$null = $sb.Append("`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\users_2.css')))
$null = $sb.Append("`r`n")
$null = $sb.Append((Read-Utf8 (Join-Path $tools 'segments\users_resp.css')))
$null = $sb.Append("`r`n`r`n")
$null = $sb.Append("/* __APPEND_PAGES__ */`r`n")

[System.IO.File]::WriteAllText($out, $sb.ToString(), $enc)
$lc = ($sb.ToString() -split "\r?\n").Count
Write-Output ("admin.css written: " + $lc + " lines")
