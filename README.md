# Rollout Management

PHP 8.4 application for a store equipment rollout. Local development uses `dev`, `APP_ENV=dev`, and **MariaDB 11.8.6**, matching the intended production database version. All interface text and documentation are English.

## Available now

- Responsive teal/green dashboard with sample statistics, workstreams, visits, and activity.
- Persistent light/dark themes and a collapsible mobile sidebar.
- Username/password sign-in, hashed passwords, CSRF checks, session rotation, and sign-in throttling.
- Admin-only user creation/editing, passwords, roles, deactivation and reactivation.
- Contractor, contractor admin, PM, and Admin roles with company-scoped and individual store access.
- Store contacts, assignments, task templates, photo evidence, PM sign-off, and printable report previews.
- Global/per-store reminder schedules, email logs, and an admin-configurable SMTP2Go connector.
- CSV sample summary from the same report provider used by the dashboard.
- Versioned schema and portable data export/import.

The development administrator is `admin`. The requested password is set locally and is not stored in source control.

## Local development (Windows)

The current workstation is configured with a running MariaDB service, database `jj_project_management_dev`, and dedicated database account `jj_pm_dev`. Its generated password is stored only in the ignored `.env`. The application does not use root.

```powershell
.\scripts\dev.ps1
```

Open http://127.0.0.1:8080. Save changes and refresh. Stop the foreground server with Ctrl+C. Use `-Port 8081` if needed; browser tests use port 8081.

For a fresh workstation:

1. Install MariaDB 11.8.6 and provision the database/account below.
2. Copy `.env.example` to `.env` and enter the dedicated database password.
3. Run `.\scripts\setup.ps1` to install project-local PHP and run migrations.
4. Seed a development administrator if needed, then start the dev server.

Example SQL to run as a local MariaDB administrator, choosing a private password:

```sql
CREATE DATABASE jj_project_management_dev
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'jj_pm_dev'@'localhost' IDENTIFIED BY 'choose-a-private-password';
GRANT ALL PRIVILEGES ON jj_project_management_dev.* TO 'jj_pm_dev'@'localhost';
GRANT ALL PRIVILEGES ON `jjtest\_%`.* TO 'jj_pm_dev'@'localhost';
```

The last grant permits disposable test databases with the `jjtest_` prefix. It grants no global server privileges. Tests generate random names, create their own databases, and remove them afterward.

Setup downloads official PHP 8.4.25 x64, verifies its SHA-256, and installs it in `.runtime/php` without changing system PHP or PATH. It requires the Microsoft Visual C++ 2022 x64 runtime. Database configuration must be ready before migrations run.

To seed an administrator on a fresh dev database:

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
| `public/index.php` | Requests, authentication and permission checks |
| `public/assets/` | Shared styling and interface controls |
| `views/` | Shared layout and individual page templates |
| `src/UserRepository.php` | User persistence and validation |
| `src/Auth.php` | Sign-in, throttling and session validation |
| `src/DashboardReport.php` | Sample report model for HTML and CSV |
| `src/Schema.php` | Versioned schema and transfer table registry |
| `src/DataTransfer.php` | Transactional data export/import |
| `scripts/console.php` | Migrations, dev seeding and data transfer |
| `tests/` | Repository, transfer and isolated browser checks |

Only `public/` is served. Private configuration, logs, exports and backups stay outside it.

## Database and production-to-dev transfers

MariaDB is the standard database for dev and production. The schema uses InnoDB, utf8mb4, stable application-generated string IDs, explicit foreign keys and UTC timestamps.

- `users`: identity, role, active status and session version.
- `stores`: store code, location and target date.
- `store_assignments`: store-to-user relationships.
- `tasks`: per-store networking, audio, DVR and rack work.
- `task_photos`: task/uploader references and file metadata.
- `schema_versions` and `login_attempts`: operational tables, excluded from data exports.

Store/task/photo workflows are implemented. See [workflow documentation](docs/workflows.md) for roles, template snapshots, SMTP settings, and reminder-worker operation. Sample dashboard statistics remain separate from application tables.

Use the matching application/schema version in production and dev. Export on the production server:

```bash
php scripts/console.php data:export /private/path/rollout.json
```

Copy the JSON to local `storage/`, then:

```powershell
.\.runtime\php\php.exe scripts/console.php migrate
.\.runtime\php\php.exe scripts/console.php data:import storage/rollout.json --replace
```

Import requires `APP_ENV=dev` and `--replace`. It first saves the current dev data to `storage/`, then replaces application records in a transaction. IDs and relationships are preserved, invalid data rolls back, and imported session versions are randomized to invalidate existing sessions. Local configuration is unchanged.

Exports contain user details and password hashes; keep them private and out of Git. Imported users retain their passwords and roles. Photo files are not embedded: when uploads are implemented, copy the private upload directory separately while preserving storage keys.

MariaDB migrations, relationships, transactional rollback, authentication, user management, and browser flows have been tested on 11.8.6. The portable transfer format has also been tested in both directions between MariaDB and SQLite.

### Earlier SQLite migration

The existing SQLite database is preserved at `storage/dev.sqlite`. The migration also saved a JSON export and the former configuration under `storage/sqlite-before-mariadb-*` and `storage/env-before-mariadb-*`. These are historical backups, not the active database.

`scripts/migrate-to-mariadb.php` supports the one-time migration of an older SQLite dev checkout. It reads a local root password file from `storage/`, refuses existing target databases/accounts, preserves the source, compares migrated rows, and writes a candidate configuration to `storage/mariadb.env`. Activate that candidate only after verification. Provision the test-database grant above if needed. Remove the temporary root-password file afterward.

## Production target

Use Debian 13, nginx, PHP 8.4-FPM and MariaDB 11.8.6. Point nginx at `public/` and use HTTPS. Enable `pdo_mysql` and `mbstring`. Provision a dedicated production database/account, configure `APP_ENV=production`, and run migrations.

The database version now matches locally. Debian/nginx deployment and environment-specific settings still need verification on the production server. Entra ID and a dedicated server-side PDF generator are subsequent phases. SMTP2Go requires credentials and a scheduled worker before live reminders can run.

## Verification

```powershell
.\.runtime\php\php.exe tests/run.php
node tests/browser.cjs
```

Both suites automatically use MariaDB when configured in `.env`. They use disposable databases, never the application database. Set `TEST_MARIADB=0` only when explicitly checking the legacy SQLite path.

Browser tests require Playwright and Microsoft Edge, run a temporary server on port 8081, and save screenshots under `storage/`. Set `PHP_BINARY` to override PHP. This workstation uses bundled Playwright through `NODE_PATH`.

## Git

Remote: https://github.com/sittpo/jj-projectmanagement.git

Push development work to `origin/dev`. Only merge or push to `master` when explicitly requested. Git identity is repository-local. Never commit credentials, `.env`, databases, runtime files, exports or uploads.

## Reminder email editor

PMs and Admins can edit the plain-text subject and message under Project management > Reminder schedule. Listed placeholders insert store details into both automatic and manual reminders. The template is stored in project settings and included in database exports; SMTP credentials remain environment-specific.

Send test reminder sends the current draft with sample store data to the entered recipient through the saved SMTP connector. It does not save the draft or change store reminder logs. The existing CLI development-send flag remains required for scheduled sending.

## Add missing templates to existing stores

PMs and Admins can use Stores > Multi-edit, select stores, then choose Add missing task templates and confirm. All active templates missing by template ID are added as unchecked steps. Existing snapshots, notes, photos, sign-offs and ordering are preserved. New steps are appended in the current template interface/report orders independently. Repeating the action is safe; inactive templates are skipped. Completed stores may become unfinished when new work is added. Additions appear in recent activity.

## User management and optional MFA

Project Managers can create, edit, disable and set passwords for Contractor and Contractor admin accounts. They cannot access or change PM/Admin accounts or grant those roles. Administrators retain full user-management rights.

Click your avatar in the top bar to open Account security. An amber badge and hover hint appear until MFA is enabled. Enter your current password, scan the locally generated QR code with Google Authenticator or Microsoft Authenticator (Other account), and confirm a six-digit code. Save the eight one-use recovery codes shown after activation; they cannot be viewed again. Setup expires after ten minutes. MFA sign-in challenges expire after five minutes, accept a single adjacent 30-second time step for clock drift, reject reused codes, and are rate-limited. Keep server time synchronized.

The Users list shows MFA status. On Edit user, Admins can reset MFA for anyone; PMs can reset contractor accounts only. Reset requires the acting user's current password and explicit confirmation. Enrollment revokes other sessions; reset revokes all target sessions and recovery codes. A password change alone does not disable MFA.

Deploy with `composer install --no-dev --prefer-dist`, PHP XMLWriter support (`php8.4-xml` on Debian), and `php scripts/console.php migrate` (schema version 9). Serve production over HTTPS. TOTP uses OTPHP and QR rendering uses BaconQrCode; no enrollment secret is sent to an external QR service.

MFA secrets are encrypted in the environment-specific `user_mfa` table using `storage/config/mfa.key` (override the directory with `MFA_CONFIG_DIR`). The key is generated on first enrollment; keep its directory writable by PHP and restrict access to the service account. Back up this key securely alongside full production database backups. A full production restore must restore both the database and its matching key. Recovery codes can still be used if the key is unavailable.

Portable `data:export` intentionally excludes MFA secrets and recovery hashes. A production-to-dev `data:import` clears dev MFA enrollments and invalidates sessions, allowing development accounts to enroll independently. Portable exports are therefore not complete authentication backups.

Additional security checks: `.runtime/php/php.exe tests/security.php`. The browser suite includes PM permission checks and MFA enrollment, sign-in and reset flows using disposable MariaDB databases and isolated keys.
