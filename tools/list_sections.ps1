$path = Join-Path (Split-Path -Parent $PSScriptRoot) 'public\css\style.css'
$lines = Get-Content $path
for ($i = 0; $i -lt $lines.Count; $i++) {
    if ($lines[$i] -match '^/\* =') {
        $title = $lines[$i + 1]
        Write-Output ("{0} {1}" -f ($i + 1), $title)
    }
}
