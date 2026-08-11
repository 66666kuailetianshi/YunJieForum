param([string]$Pattern, [string]$Include = '*.php', [switch]$ExcludeAdmin)
$root = Split-Path -Parent $PSScriptRoot
$files = Get-ChildItem -Path $root -Recurse -Include $Include -File | Where-Object {
    $_.FullName -notmatch '\\\.git\\' -and $_.FullName -notmatch '\\\.workbuddy\\' -and $_.FullName -notmatch '\\\.qoder\\'
}
if ($ExcludeAdmin) { $files = $files | Where-Object { $_.FullName -notmatch '\\app\\admin\\' } }
foreach ($f in $files) {
    $hits = Select-String -Path $f.FullName -Pattern $Pattern -AllMatches
    foreach ($h in $hits) {
        $rel = $f.FullName.Substring($root.Length + 1)
        Write-Output ("{0}:{1}: {2}" -f $rel, $h.LineNumber, $h.Line.Trim())
    }
}
