# Local setup for Team 09 backend

## Requirements

1. Install Docker Desktop.
2. Start Docker Desktop before running the setup script.

## First-time setup

```powershell
cd C:\Users\ASUS\Documents\Codex\2026-06-23\github-plugin-github-openai-curated-remote\work\nfu1142-db09
powershell -ExecutionPolicy Bypass -File scripts\setup-local.ps1 -Fresh
```

Use `-Fresh` when you want to rebuild the database from `schema.sql` and `seed.sql`.
It removes `db_data`, so do not use it if you need to keep local DB changes.

## Normal startup

```powershell
powershell -ExecutionPolicy Bypass -File scripts\setup-local.ps1
```

## URLs

- Web app: http://localhost:8080
- Admin dashboard: http://localhost:8080/admin_dashboard.php
- phpMyAdmin: http://localhost:8082

## Test account from seed.sql

- Backend admin: `liuzq` / `password5`

## Notes

The setup script runs `composer install`, starts containers, then applies every SQL file under `migrations/` for the backend admin fields and role/login cleanup.