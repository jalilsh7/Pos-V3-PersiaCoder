#requires -Version 5.1
[CmdletBinding()]
param(
    [switch]$WithDatabase
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
Push-Location $repoRoot
try {
    Write-Host "=== PersiaCoder POS V3 local validation (Windows) ===" -ForegroundColor Cyan

    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) { throw "PHP CLI پیدا نشد. PHP 8.2+ را نصب و به PATH اضافه کنید." }

    $phpVersion = (& php -r 'echo PHP_VERSION_ID;').Trim()
    if ([int]$phpVersion -lt 80200) { throw "PHP 8.2+ لازم است. PHP_VERSION_ID فعلی: $phpVersion" }

    $extensions = (& php -r 'echo implode(",", get_loaded_extensions());').Split(',')
    foreach ($extension in @('PDO', 'pdo_mysql', 'zlib', 'json')) {
        if ($extensions -notcontains $extension) { throw "افزونه PHP لازم فعال نیست: $extension" }
    }

    $phpFiles = @()
    $phpFiles += Get-ChildItem 'database/v3/bin' -Filter '*.php' -File | ForEach-Object { $_.FullName }
    $phpFiles += Get-ChildItem 'scripts' -Filter 'check-v3-*.php' -File | ForEach-Object { $_.FullName }
    foreach ($file in $phpFiles) {
        & php -l $file
        if ($LASTEXITCODE -ne 0) { throw "PHP lint شکست خورد: $file" }
    }

    & php 'scripts/check-v3-sql-parser.php'
    if ($LASTEXITCODE -ne 0) { throw 'تست SQL parser شکست خورد.' }

    $python = Get-Command py -ErrorAction SilentlyContinue
    if ($python) {
        & py -3 -m py_compile 'scripts/tamper-v3-backup.py'
    } else {
        $python = Get-Command python -ErrorAction SilentlyContinue
        if (-not $python) { throw 'Python 3 برای بررسی اسکریپت tamper لازم است.' }
        & python -m py_compile 'scripts/tamper-v3-backup.py'
    }
    if ($LASTEXITCODE -ne 0) { throw 'Python compile شکست خورد.' }
    Remove-Item 'scripts/__pycache__' -Recurse -Force -ErrorAction SilentlyContinue

    Write-Host "PASS: PHP lint، بررسی پیش‌نیازها، SQL parser و Python compile" -ForegroundColor Green

    if ($WithDatabase) {
        foreach ($name in @('MYSQL_TEST_HOST','MYSQL_TEST_DATABASE','MYSQL_TEST_USERNAME','POS_V3_DB_HOST','POS_V3_DB_NAME','POS_V3_DB_USER','POS_V3_ADMIN_USERNAME','POS_V3_ADMIN_PASSWORD','POS_V3_ADMIN_NAME')) {
            if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($name))) {
                throw "برای -WithDatabase متغیر محیطی لازم تنظیم نشده: $name"
            }
        }
        if ([string]::IsNullOrWhiteSpace($env:POS_V3_DB_PASSWORD)) {
            Write-Warning 'POS_V3_DB_PASSWORD خالی است؛ فقط اگر کاربر دیتابیس واقعاً بدون رمز است ادامه دهید.'
        }

        Write-Host 'اجرای Preflight روی دیتابیس مقصد تنظیم‌شده...' -ForegroundColor Yellow
        & php 'database/v3/bin/preflight.php'
        if ($LASTEXITCODE -ne 0) { throw 'V3 database preflight شکست خورد؛ هیچ نصب/تغییری از این اسکریپت انجام نشده است.' }

        Write-Host 'اجرای تست metadata/constraints روی دیتابیس تست از پیش آماده‌شده...' -ForegroundColor Yellow
        $env:MYSQL_TEST_PORT = if ($env:MYSQL_TEST_PORT) { $env:MYSQL_TEST_PORT } else { '3306' }
        & php 'scripts/check-v3-database-schema.php'
        if ($LASTEXITCODE -ne 0) { throw 'تست schema metadata/constraints شکست خورد.' }

        Write-Host 'PASS: Preflight و تست واقعی اتصال/metadata دیتابیس' -ForegroundColor Green
        Write-Warning 'Installer، migration و backup/restore عمداً خودکار اجرا نشدند؛ این عملیات نیازمند دیتابیس‌های disposable مجزا و مجوز صریح است.'
    }

    Write-Host '=== Local validation finished successfully ===' -ForegroundColor Green
}
finally {
    Pop-Location
}
