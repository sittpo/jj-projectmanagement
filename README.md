# JJ Project Management

PHP 8.4 application for a store equipment rollout. Local development uses `dev` and `APP_ENV=dev`. All interface text and documentation are English.

## Available now

- Responsive dashboard with clearly labeled sample statistics, workstreams, visits, and activity.
- Teal/green design, light and dark themes, and a collapsible mobile sidebar.
- Username/password sign-in, hashed passwords, CSRF checks, session rotation, and sign-in throttling.
- Admin-only user management: create/edit users, change passwords and roles, deactivate/reactivate accounts. Deactivation preserves references for future reports.
- Administrator, project manager, and contractor roles. Contractors see an empty workspace until store assignment is implemented; sample project-wide reports are available only to administrators and PMs.
- CSV sample summary from the same report provider used by the dashboard.
- Versioned schema and portable JSON data export/import commands.

The local development administrator has username `admin`. Its requested password was set locally and is not stored in source control.

## Local development (Windows)

From this directory:

```powershell
.\scripts\setup.ps1
.\scripts\dev.ps1
```

Open http://127.0.0.1:8080. Save changes and refresh. Stop the foreground server with Ctrl+C. Use `-Port 8081` if needed.

Setup downloads official PHP 8.4.25 x64, verifies its published SHA-256, installs it in `.runtime/php`, creates `.env` if missing, and runs schema migrations. It does not change system PHP or PATH. The Microsoft Visual C++ 2022 x64 runtime is required.

To create the initial administrator on a fresh dev database, supply a password for the seed command:

```powershell
$env:DEV_ADMIN_PASSWORD = Read-Host 'Development administrator password' -MaskInput
try {
    .\.runtime\php\php.exe scripts/console.php seed-admin
} finally {
    Remove-Item Env:DEV_ADMIN_PASSWORD
}
```

The seed command refuses production and refuses to overwrite an existing admin user. The web application never creates default accounts.

## Project structure

| Location | Responsibility |
| --- | --- |
| `public/index.php` | Request handling, authentication and permission checks |
| `public/assets/` | Shared CSS, theme and mobile navigation controls |
| `views/` | Shared layout and individual page templates |
| `src/UserRepository.php` | User persistence and validation |
| `src/Auth.php` | Sign-in, throttling, current-session validation |
| `src/DashboardReport.php` | Sample report model used by both HTML and CSV |
| `src/Schema.php` | Versioned schema and parent-first transfer table registry |
| `src/DataTransfer.php` | Transactional portable data export/import |
| `scripts/console.php` | CLI migrations, dev seeding and data transfer |
| `tests/` | Repository, transfer and isolated HTTP/browser checks |

Only `public/` is served. Configuration, application code, database files, logs, and data exports stay outside the document root. Keep private files under `storage/`, which is ignored by Git.

## Database design and production-to-dev transfers

Production targets MariaDB on Debian 13; local development currently uses SQLite through PDO. Tables use application-generated stable string IDs, explicit foreign keys, UTC timestamps, and simple SQL types. Relationships are normalized:

- `users`: sign-in identity, role, active status and session version.
- `stores`: store code, location and target date.
- `store_assignments`: many-to-many relationships between stores and users.
- `tasks`: per-store networking, audio, DVR and rack work.
- `task_photos`: task and uploader references with storage metadata.
- `schema_versions` and `login_attempts`: operational tables, excluded from data exports.

Store/task/photo tables are ready for the next phase, but their interfaces are not implemented and contain no sample dashboard records. Dashboard sample data is intentionally isolated in its report provider. Future database-backed reports can replace that provider without changing the shared layout.

Use the matching application/schema version on both environments. Run migrations before transferring data:

```bash
# On the production server, using its environment configuration:
php scripts/console.php migrate
php scripts/console.php data:export /private/path/rollout.json
```

Copy the JSON into local `storage/`, then:

```powershell
.\.runtime\php\php.exe scripts/console.php migrate
.\.runtime\php\php.exe scripts/console.php data:import storage/rollout.json --replace
```

Import is restricted to `APP_ENV=dev`, requires `--replace`, and automatically saves the current dev data to a new file in `storage/` first. It replaces registered application tables in a transaction, preserving IDs and relationships, and rolls back on invalid data. Imported session versions are randomized to invalidate existing sessions. Local environment configuration remains unchanged.

Exports include user details and password hashes; keep them private and out of Git. Imported users retain their passwords and roles. Actual photo files are not embedded in JSON: when uploads are implemented, copy the private upload directory alongside the database export, preserving storage keys. This is an application-level transfer format, not a raw MariaDB SQL dump.

SQLite migration, round-trip transfer, foreign-key validation, and rollback are tested. Live MariaDB migration and cross-engine transfer still require verification before production deployment.

## Production target

Use Debian 13, nginx, PHP 8.4-FPM and MariaDB. Point nginx at `public/` and pass PHP requests to PHP-FPM. The built-in server is only for local development.

Configure environment variables or a private `.env`:

```ini
APP_ENV=production
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jj_project_management
DB_USERNAME=jj_app
DB_PASSWORD="replace-with-a-secret"
```

Environment variables take precedence. Enable `pdo_mysql` and `mbstring`, provision a dedicated MariaDB database/user, run migrations, and configure HTTPS. Production account provisioning, Entra ID, deployment, store assignment screens, task workflows, and photo uploads are subsequent phases.

## Verification

```powershell
.\.runtime\php\php.exe tests/run.php
node tests/browser.cjs
```

Browser tests require Playwright and Microsoft Edge, use a temporary SQLite database and a server on port 8081, and generate screenshots in `storage/`. They never modify the normal dev users or database. Set `PHP_BINARY` to override the local PHP executable. The current workstation uses the bundled Playwright installation through `NODE_PATH`.

## Git

Remote: https://github.com/sittpo/jj-projectmanagement.git

```powershell
git switch dev
git push -u origin dev
```

Push development work to `origin/dev`. Only merge or push to `master` when explicitly requested. Identity is configured for this repository only. Never commit `.env`, credentials, databases, runtime files, exports, or uploads.
