$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error '找不到 docker 指令。'
}

docker compose ps
Write-Host ''
Write-Host 'Web App:    http://localhost:8080'
Write-Host '後台總覽:   http://localhost:8080/admin_dashboard.php'
Write-Host 'phpMyAdmin: http://localhost:8082'