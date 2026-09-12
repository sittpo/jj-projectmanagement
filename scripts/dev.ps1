param([ValidateRange(1024,65535)][int]$Port = 8080)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$php = Join-Path $projectRoot '.runtime/php/php.exe'
if (-not (Test-Path $php)) { throw 'Run .\scripts\setup.ps1 first.' }
Push-Location $projectRoot
try {
    Write-Host "Development server: http://127.0.0.1:$Port (Ctrl+C to stop)"
    & $php -c (Join-Path $projectRoot '.runtime/php/php.ini') -S "127.0.0.1:$Port" -t public
    if ($LASTEXITCODE -ne 0) { throw 'Development server exited with an error.' }
} finally { Pop-Location }
