param(
    [string]$LegacyMysqlData = 'C:\xampp\htdocs\onerp\data\mysql-data'
)

$ErrorActionPreference = 'Stop'

Write-Host 'ONEERP legacy local DB recovery' -ForegroundColor Cyan
Write-Host 'This script NEVER deletes the current DB without first renaming it to a timestamped backup.'

if (-not (Test-Path -LiteralPath $LegacyMysqlData)) {
    throw "Legacy mysql-data not found: $LegacyMysqlData"
}
if (-not (Test-Path -LiteralPath (Join-Path $LegacyMysqlData 'saas_pos'))) {
    throw "Legacy saas_pos database folder not found under: $LegacyMysqlData"
}
if (-not (Test-Path -LiteralPath (Join-Path $LegacyMysqlData 'mysql'))) {
    throw "Legacy MariaDB system tables folder not found under: $LegacyMysqlData"
}

$running = Get-Process -ErrorAction SilentlyContinue | Where-Object {
    $_.ProcessName -match '^(mysqld|ONEERP Offline)$'
}
if ($running) {
    Write-Host ''
    Write-Host 'STOPPED: ONEERP/MySQL is still running.' -ForegroundColor Red
    $running | Select-Object ProcessName, Id | Format-Table -AutoSize
    Write-Host 'Close ONEERP Offline and stop XAMPP/MySQL, then run this script again.' -ForegroundColor Yellow
    exit 2
}

$markers = Get-ChildItem -LiteralPath $env:APPDATA -Filter '.onerp_db_imported' -File -Recurse -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTime -Descending

$target = $null
if ($markers -and $markers.Count -gt 0) {
    $target = $markers[0].Directory.FullName
}

if (-not $target) {
    $candidates = Get-ChildItem -LiteralPath $env:APPDATA -Directory -Recurse -ErrorAction SilentlyContinue |
        Where-Object {
            $_.Name -eq 'mysql-data' -and
            (Test-Path -LiteralPath (Join-Path $_.FullName 'mysql')) -and
            (Test-Path -LiteralPath (Join-Path $_.FullName 'saas_pos'))
        } |
        Sort-Object LastWriteTime -Descending

    if ($candidates -and $candidates.Count -gt 0) {
        $target = $candidates[0].FullName
    }
}

if (-not $target) {
    throw 'Active packaged ONEERP mysql-data could not be found under %APPDATA%. Open ONEERP 1.0.15 once, close it, then run this script.'
}

$legacyResolved = (Resolve-Path -LiteralPath $LegacyMysqlData).Path.TrimEnd('\')
$targetResolved = (Resolve-Path -LiteralPath $target).Path.TrimEnd('\')
if ($legacyResolved -ieq $targetResolved) {
    throw 'Legacy and active mysql-data paths are the same. Nothing to recover.'
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$targetParent = Split-Path -Parent $targetResolved
$backup = Join-Path $targetParent ("mysql-data-before-legacy-recovery-$stamp")

Write-Host "Legacy source : $legacyResolved" -ForegroundColor Green
Write-Host "Active target : $targetResolved" -ForegroundColor Green
Write-Host "Safety backup: $backup" -ForegroundColor Green
Write-Host ''

Rename-Item -LiteralPath $targetResolved -NewName (Split-Path -Leaf $backup)
New-Item -ItemType Directory -Path $targetResolved -Force | Out-Null

$null = & robocopy $legacyResolved $targetResolved /MIR /COPY:DAT /DCOPY:DAT /R:2 /W:1 /NFL /NDL /NP
$code = $LASTEXITCODE
if ($code -gt 7) {
    throw "robocopy failed with exit code $code. Current pre-recovery DB is safe at: $backup"
}

$importMarker = Join-Path $targetResolved '.onerp_db_imported'
if (Test-Path -LiteralPath $importMarker) {
    Remove-Item -LiteralPath $importMarker -Force
}

$userDataRoot = Split-Path -Parent $targetResolved
$desktopData = Join-Path $userDataRoot 'desktop-data'
if (Test-Path -LiteralPath $desktopData) {
    @(
        '.desktop_schema_1_0_10_done',
        '.desktop_schema_1_0_11_done',
        '.desktop_schema_1_0_12_done',
        '.desktop_schema_1_0_13_done',
        '.desktop_schema_1_0_15_done'
    ) | ForEach-Object {
        $marker = Join-Path $desktopData $_
        if (Test-Path -LiteralPath $marker) {
            Remove-Item -LiteralPath $marker -Force
        }
    }
}

Write-Host ''
Write-Host 'Recovery copy completed.' -ForegroundColor Green
Write-Host 'Now open ONEERP Offline 1.0.15. It will re-apply schema migrations to the recovered DB.' -ForegroundColor Cyan
Write-Host "If you need to undo recovery, your previous packaged DB is preserved at: $backup" -ForegroundColor Yellow
