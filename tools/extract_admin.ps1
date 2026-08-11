$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$css = Join-Path $root 'public\css\style.css'
$segDir = Join-Path $PSScriptRoot 'segments'
if (-not (Test-Path $segDir)) { New-Item -ItemType Directory -Path $segDir | Out-Null }

Copy-Item $css (Join-Path $PSScriptRoot 'style.css.bak') -Force

$enc = New-Object System.Text.UTF8Encoding($false)
$raw = [System.IO.File]::ReadAllText($css, [System.Text.Encoding]::UTF8)
$hasBom = $raw.Length -gt 0 -and [int][char]$raw[0] -eq 0xFEFF
$lines = [System.Collections.Generic.List[string]]::new()
foreach ($l in ($raw -split "\r?\n")) { $lines.Add($l) }
Write-Output ("total lines: " + $lines.Count)

# load markers (UTF-8 file: KEY<TAB>VALUE)
$M = @{}
$mraw = [System.IO.File]::ReadAllText((Join-Path $PSScriptRoot 'markers.txt'), [System.Text.Encoding]::UTF8)
foreach ($ml in ($mraw -split "\r?\n")) {
    if ($ml.Trim() -eq '') { continue }
    $idx = $ml.IndexOf("`t")
    $M[$ml.Substring(0, $idx)] = $ml.Substring($idx + 1)
}
Write-Output ("markers loaded: " + $M.Count)

function Find-Line($pattern, $from) {
    for ($i = $from; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -ceq $pattern) { return $i }
    }
    return -1
}
function Find-Header($title, $from) {
    for ($i = $from; $i -lt $lines.Count - 1; $i++) {
        if ($lines[$i] -match '^/\* =+$' -and $lines[$i + 1].Trim() -ceq $title) { return $i }
    }
    return -1
}

$ranges = [System.Collections.Generic.List[object]]::new()

$s = Find-Line $M['L_CARD_START'] 0
$e = Find-Line $M['L_CARD_END'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_CARD_ADMIN not found' }
$ranges.Add(@('card_admin', $s, $e))

$s = Find-Header $M['H_ADMIN'] 0
$e = Find-Header $M['H_TABLE'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_ADMIN not found' }
$ranges.Add(@('admin_section', $s, $e))

$s = Find-Line $M['L_TABLEC_START'] 0
$e = Find-Line $M['L_RESPONSIVE_END'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_TABLE_COMPACT not found' }
$ranges.Add(@('table_compact', $s, $e))

$s = Find-Line $M['L_MODAL_START'] 0
$e = Find-Line $M['L_MODAL_END'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_MODAL_BOX not found' }
$ranges.Add(@('modal_box', $s, $e))

$s = Find-Header $M['H_MAIL'] 0
$e = Find-Header $M['H_LIGHTBOX'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_MAIL_1 not found' }
$ranges.Add(@('mail_1', $s, $e))

$s = Find-Line $M['L_MAIL2_START'] 0
$e = Find-Header $M['H_RISK'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_MAIL_2 not found' }
$ranges.Add(@('mail_2', $s, $e))

$s = Find-Header $M['H_RISK'] 0
$e = Find-Header $M['H_FOOTLANG'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_RISK not found' }
$ranges.Add(@('risk', $s, $e))

$s = Find-Header $M['H_USERS1'] 0
if ($s -lt 0) { throw 'SEG_USERS_1 start not found' }
$e = -1
for ($i = $s; $i -lt $lines.Count; $i++) { if ($lines[$i] -ceq $M['L_EMPTY']) { $e = $i; break } }
if ($e -lt 0) { throw 'SEG_USERS_1 end not found' }
$ranges.Add(@('users_1', $s, $e))

$s = Find-Line $M['L_USERS2_START'] 0
$e = Find-Line $M['L_USERS2_END'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_USERS_2 not found' }
$ranges.Add(@('users_2', $s, $e))

$s = Find-Line $M['L_USERSRESP_START'] 0
if ($s -lt 0) { throw 'SEG_USERS_RESP start not found' }
$e = $s
$depth = 0; $started = $false
for ($i = $s; $i -lt $lines.Count; $i++) {
    if ($lines[$i] -match '@media') { $started = $true }
    if ($started) {
        foreach ($ch in $lines[$i].ToCharArray()) {
            if ($ch -eq '{') { $depth++ }
            if ($ch -eq '}') { $depth-- }
        }
        if ($depth -eq 0 -and $started -and $lines[$i] -match '^\}') { $e = $i + 1; break }
    }
}
$ranges.Add(@('users_resp', $s, $e))

$s = Find-Line $M['L_TABLET_ADMIN_START'] 0
$e = Find-Line $M['L_TABLET_ADMIN_END'] $s
if ($s -lt 0 -or $e -lt 0) { throw 'SEG_RESP_TABLET not found' }
$ranges.Add(@('resp_tablet_admin', $s, $e))

# verify: ranges must be sorted and disjoint
$sorted = $ranges | Sort-Object { $_[1] }
for ($i = 1; $i -lt $sorted.Count; $i++) {
    if ($sorted[$i][1] -lt $sorted[$i - 1][2]) { throw ('overlap: ' + $sorted[$i - 1][0] + ' / ' + $sorted[$i][0]) }
}

$total = 0
foreach ($r in $ranges) {
    $name = $r[0]; $a = $r[1]; $b = $r[2]
    $segLines = $lines.GetRange($a, $b - $a)
    [System.IO.File]::WriteAllLines((Join-Path $segDir ($name + '.css')), $segLines, $enc)
    $total += ($b - $a)
    Write-Output ("segment {0}: L{1}-L{2} ({3} lines)" -f $name, ($a + 1), $b, ($b - $a))
}
Write-Output ("TOTAL extracted: " + $total)

$del = New-Object bool[] $lines.Count
foreach ($r in $ranges) { for ($i = $r[1]; $i -lt $r[2]; $i++) { $del[$i] = $true } }
$out = [System.Collections.Generic.List[string]]::new()
for ($i = 0; $i -lt $lines.Count; $i++) { if (-not $del[$i]) { $out.Add($lines[$i]) } }
$final = [System.Collections.Generic.List[string]]::new()
$blank = 0
foreach ($l in $out) {
    if ($l.Trim() -eq '') { $blank++; if ($blank -le 2) { $final.Add($l) } } else { $blank = 0; $final.Add($l) }
}
$finalEnc = New-Object System.Text.UTF8Encoding($hasBom)
[System.IO.File]::WriteAllLines($css, $final, $finalEnc)
Write-Output ("new style.css lines: " + $final.Count)
