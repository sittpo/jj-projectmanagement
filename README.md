# JJ Project Management

Initial foundation: PHP 8.4, a PDO database connection, and a mobile-friendly Hello World page that follows the device's light/dark preference. Platform features and authentication are not implemented yet.

## Local development (Windows)

From this directory in PowerShell:

```powershell
.\scripts\setup.ps1
.\scripts\dev.ps1
```

Open http://127.0.0.1:8080. Save PHP changes and refresh the browser. Stop with Ctrl+C. Use `-Port 8081` if 8080 is occupied.

Setup downloads official PHP 8.4.25 x64, verifies its published SHA-256, and installs it under `.runtime/php`. It does not change the system PHP installation or PATH. Requires the Microsoft Visual C++ 2022 x64 runtime. Local configuration is `.env`, created from `.env.example`, with `APP_ENV=dev`. SQLite creates `storage/dev.sqlite` on first connection. Runtime, configuration, databases and logs are ignored by Git.

Only `public/` is served. PHP source, configuration and database files remain outside the document root. The page executes `SELECT 1` on each request and returns HTTP 503 on connection failure. Details go to the server error log.

## Production target

Debian 13 with nginx, PHP 8.4-FPM and MariaDB is the intended production stack. Configure nginx's document root to the deployed `public/` directory and pass PHP requests to PHP-FPM. The PHP development server is for local use only.

Use environment variables or a private `.env` outside `public/`:

```ini
APP_ENV=production
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jj_project_management
DB_USERNAME=jj_app
DB_PASSWORD="replace-with-a-secret"
```

Environment variables take precedence over `.env`. Install/enable `pdo_mysql` in production. Provision the database and a dedicated database user before connecting. Deployment and production database provisioning are not performed by this scaffold.

SQLite is a convenient local starting point, not a guarantee of MariaDB SQL compatibility. Both use the same PDO connection function; future migrations and queries must be tested against MariaDB before deployment. No application tables exist yet.

## Git

Local development uses the `dev` branch and `APP_ENV=dev` for the time being. Push development changes to `origin/dev`. Only merge or push to `master` when explicitly requested. The remote is https://github.com/sittpo/jj-projectmanagement.git.

```powershell
git switch dev
git push -u origin dev
```

Do not commit `.env`, credentials, photo uploads or local database files.

## Planned scope

- PM access to all stores; contractors restricted to explicitly assigned stores.
- Per-store networking, audio, DVR replacement and rack mounting tasks.
- Photo documentation per task with mobile-friendly uploads.
- Compact interface with selectable dark mode.
- Microsoft Entra ID and username/password sign-in.

These features will follow after the foundation is confirmed.
