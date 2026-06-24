param(
    [switch]$Fresh
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

function Invoke-DockerCompose {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Args)
    docker compose @Args
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error '找不到 docker 指令。請先安裝並啟動 Docker Desktop，再重新執行 scripts/setup-local.ps1。'
}

if (-not (Test-Path '.env')) {
    Copy-Item '.env.example' '.env'
    Write-Host '已建立 .env'
}

if ($Fresh) {
    Write-Host 'Fresh 模式：停止容器並移除 db_data...'
    Invoke-DockerCompose down
    if (Test-Path 'db_data') {
        Remove-Item -Recurse -Force 'db_data'
    }
}

Write-Host '安裝 PHP Composer 套件...'
Invoke-DockerCompose run --rm composer install

Write-Host '啟動 MariaDB / PHP Apache / phpMyAdmin...'
Invoke-DockerCompose up -d --build

Write-Host '等待資料庫啟動...'
for ($i = 1; $i -le 30; $i++) {
    docker exec db09-db mariadb-admin ping -uroot -pmyPotato --silent *> $null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}

if ($LASTEXITCODE -ne 0) {
    Write-Error '資料庫尚未啟動完成，請稍後執行 scripts/check-env.ps1 或重跑 setup。'
}

if (Test-Path 'migrations') {
    Get-ChildItem 'migrations' -Filter '*.sql' | Sort-Object Name | ForEach-Object {
        Write-Host ('套用 migration: ' + $_.Name)
        Get-Content $_.FullName | docker exec -i db09-db mariadb -uroot -pmyPotato csieDBTeam09
    }
}

Write-Host ''
Write-Host '環境已啟動：'
Write-Host 'Web App:    http://localhost:8080'
Write-Host '後台總覽:   http://localhost:8080/admin_dashboard.php'
Write-Host 'phpMyAdmin: http://localhost:8082'
Write-Host ''
Write-Host '後台測試帳號：liuzq/password5'
Write-Host '如果資料表已存在但缺欄位，請確認 migrations 目錄內 SQL 已全部套用。'