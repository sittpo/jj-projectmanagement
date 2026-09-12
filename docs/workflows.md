# Store workflows and reminders

## Roles

Permissions are ordered **Contractor → Contractor admin → PM → Admin**. Admin has all application rights.

| Role | Store access | Complete steps, notes and photos | Sign off / reopen | Stores, companies, templates, schedule | User / SMTP administration |
| --- | --- | --- | --- | --- | --- |
| Contractor | Individually assigned stores within their company | Yes | No | No | No |
| Contractor admin | Every store assigned to their company; read-only company team list | Yes | No | No | No |
| PM | All stores | Yes | Yes | Yes | No |
| Admin | All stores | Yes | Yes | Yes | Yes |

Contractor accounts without a company have no store access. An individual assignment is valid only for an active contractor/contractor admin in the selected company. Inactive companies cannot receive new assignments; existing access and history remain available. Deactivate individual users to revoke their access.

## Suggested workflow

1. Create contracting companies.
2. Create/edit contractor accounts under **Users**, choosing their role and company.
3. Review **Task templates**. Each template is a checklist step under networking, audio, DVR, or rack cabinet work.
4. Set the two independent template orders: **Interface order** and **Report order**. Drag rows or use the accessible arrow controls, then save each order.
5. Create a store with its owner, optional contact, installation date, contracting company, and individual contractors.
6. Contractors open their stores, add notes/photos, check completion, and save each step.
7. A PM or Admin reviews the evidence and signs off each completed step.

New stores receive snapshots of all active templates, including both orders. Later template changes apply to new stores only. Existing instructions, step order, notes, and sign-off history remain intact.

A signed-off step is locked. A PM or Admin can reopen it to permit changes. The audit records the acting user, display name, action, notes/completion snapshot, and time. Concurrent updates are checked using a step version to prevent stale forms overwriting newer changes.

## Photos and reports

Each save accepts up to six JPEG, PNG, or WebP files, at most 10 MB each and 60 megapixels. File contents are checked; files receive generated names under private `storage/uploads/`. Photos are served through a permission-checked PHP route, not directly through the web server.

The store report preview uses the independent report order and includes instructions, notes, photos, completion attribution, and the PM/Admin sign-off name and timestamp. **Print / Save as PDF** uses the browser print dialog. A dedicated server-side PDF generator is not part of this phase.

For production, configure PHP `upload_max_filesize=10M`, `post_max_size=64M`, and nginx `client_max_body_size 64m`. Enable `fileinfo`. Give the application account write access to private storage.

## Reminder settings

The global schedule defaults to **7 days before installation**. PMs and Admins can change it to 0–365 days. A store may override this with an exact reminder date, on or before installation. Scheduling dates use **Europe/Copenhagen**; audit timestamps are stored in UTC.

The worker skips disabled stores, missing installation dates, past installations, missing email addresses, and reminder attempts already logged for the same recipient/installation/scheduled date. If owner and contact use the same email, only one message is attempted.

The store log records recipient, due date, attempt status, and SMTP acceptance time. **Accepted by SMTP** does not mean inbox delivery; check SMTP2Go activity for delivery/bounce information.

Failed or interrupted attempts are not automatically retried because SMTP acceptance can be uncertain after a connection failure. Inspect SMTP2Go before arranging a retry. The current interface does not reset failed attempts.

## SMTP2Go configuration

Open **Administration → SMTP connector** as Admin. Enter the SMTP2Go username/password and a verified sender email/name. The server is `mail.smtp2go.com`; STARTTLS defaults to port 587. Supported alternative STARTTLS ports are selectable.

Credentials are encrypted using AES-256-GCM in private `storage/config/smtp.json`, with the key in `storage/config/smtp.key`. Settings pages never return the stored password. These files are environment-specific and are excluded from database exports and Git. Back them up privately for server recovery; do not copy production SMTP configuration into dev.

Saving settings does not send email. Enable reminders when ready, then arrange the worker schedule.

## Running the reminder worker

Preview due messages without sending:

```powershell
.\.runtime\php\php.exe scripts/console.php reminders:run
```

An explicit development send requires both flags:

```powershell
.\.runtime\php\php.exe scripts/console.php reminders:run --send --allow-dev-send
```

On production, schedule this command using cron or a systemd timer, for example every five minutes:

```bash
cd /srv/jj-projectmanagement
php scripts/console.php reminders:run --send
```

Use the deployed service account and project directory. The unique reminder record prevents competing workers from claiming the same reminder twice. CLI dry runs make no log entries and send no messages.

No SMTP credentials have been configured and no real emails were sent during implementation. No OS-level sending schedule is installed on the dev workstation.

## Schema and deployment

Schema version 2 adds companies, template snapshots, subtask evidence/audit, and reminder logs while preserving existing user/store/task IDs. Run `composer install` with PHP 8.4 and `php scripts/console.php migrate` when deploying. PHPMailer is pinned through Composer's lock file.

The Windows setup script installs dependencies through Composer, enables upload extensions, and configures a trusted CA bundle for SMTP TLS. Debian should use its system CA certificates.

Database exports now include all workflow tables and global reminder settings. Photo files must still be copied separately, preserving storage keys. Restore SMTP configuration separately only to its intended environment.

## Tests

```powershell
.\.runtime\php\php.exe tests/run.php
.\.runtime\php\php.exe tests/workflows.php
node tests/browser.cjs
```

Tests use disposable MariaDB databases and isolated upload/config directories. They cover company isolation, role guards, template snapshots, independent ordering, file validation, photo access, sign-off/reopen, stale forms, reminder overrides, duplicate prevention, encrypted secrets, and database transfer compatibility. Reminder tests use an injected fake transport; live SMTP2Go delivery remains to be verified after credentials are supplied.
