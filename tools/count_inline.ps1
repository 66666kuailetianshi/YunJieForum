$root = Split-Path -Parent $PSScriptRoot
$dir = Join-Path $root 'app\admin'
Get-ChildItem -Path $dir -Recurse -Include *.php -File | ForEach-Object {
    $c = (Select-String -Path $_.FullName -Pattern 'style=' -AllMatches | ForEach-Object { $_.Matches.Count } | Measure-Object -Sum).Sum
    $s = (Select-String -Path $_.FullName -Pattern '<style' -AllMatches | ForEach-Object { $_.Matches.Count } | Measure-Object -Sum).Sum
    $rel = $_.FullName.Substring($dir.Length + 1)
    Write-Output ("{0} inline={1} styleblocks={2}" -f $rel, $c, $s)
}
