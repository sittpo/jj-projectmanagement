$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $projectRoot '.runtime'
$phpDirectory = Join-Path $runtime 'php'
New-Item -ItemType Directory -Force -Path $runtime | Out-Null
if (-not (Test-Path (Join-Path $phpDirectory 'php.exe'))) {
    $archive = Join-Path $runtime 'php.zip'
    Invoke-WebRequest 'https://windows.php.net/downloads/releases/php-8.4.25-nts-Win32-vs17-x64.zip' -OutFile $archive
    if ((Get-FileHash $archive -Algorithm SHA256).Hash -ne '43a8f67ed2e5223fafb21293c85976361808855405278cef2cf3037c3ae2529c') {
        throw 'PHP archive checksum mismatch.'
    }
    Expand-Archive -LiteralPath $archive -DestinationPath $phpDirectory -Force
    Remove-Item -LiteralPath $archive
}
$extensionDirectory = (Join-Path $phpDirectory 'ext').Replace('\', '/')
@"
extension_dir="$extensionDirectory"
extension=pdo_sqlite
extension=pdo_mysql
extension=mbstring
extension=openssl
date.timezone=Europe/Copenhagen
error_reporting=E_ALL
display_errors=Off
log_errors=On
expose_php=Off
"@ | Set-Content -LiteralPath (Join-Path $phpDirectory 'php.ini') -Encoding ascii
if (-not (Test-Path (Join-Path $projectRoot '.env'))) {
    Copy-Item -LiteralPath (Join-Path $projectRoot '.env.example') -Destination (Join-Path $projectRoot '.env')
}
& (Join-Path $phpDirectory 'php.exe') -v
if ($LASTEXITCODE -ne 0) { throw 'PHP failed to start. Check the Microsoft Visual C++ 2022 x64 runtime.' }
& (Join-Path $phpDirectory 'php.exe') (Join-Path $projectRoot 'scripts/console.php') migrate
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed.' }
Write-Host 'Setup complete. Run .\scripts\dev.ps1 to start.'
