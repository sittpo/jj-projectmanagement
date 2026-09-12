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

## Manual reminder and SMTP test actions

PMs and Admins can use **Send reminder now** on the store page. It sends to the saved owner/contact addresses immediately, ignoring automatic reminder dates and enable switches. An installation date, recipient address, and saved SMTP credentials are required. It does not cancel or change the automatic reminder.

Each manual send records its recipients, the acting user, attempt time, SMTP acceptance time, and result in the store log. Duplicate submission of the same request does not send twice; deliberately clicking again from a freshly loaded store creates another attempt. Failed or ambiguous attempts should be checked in SMTP2Go before resending.

Admins can enter a **Test recipient** and click **Test SMTP** on the SMTP settings page. It sends one test message using saved settings, even with automatic reminders disabled. Save edited credentials/settings before testing. Test messages do not create store-reminder log entries.

Photo thumbnails open in an in-page lightbox with previous/next buttons, arrow keys, Escape-to-close, mobile swipe support, and focus restoration. Photo access remains protected by store permissions.

Schema version 3 adds a separate manual reminder log; automatic reminder history and duplicate prevention remain unchanged. Both log tables are included in portable data exports.

## Store listing

Store search updates after 750 ms and matches names, store codes, and internal IDs. Results remain scoped to the signed-in user's store access. Unfinished stores appear by installation date (oldest first), with undated stores last. Checklist stores remain unfinished until every step is complete and signed off; legacy stores without checklists use task completion. Due-today rows are teal and overdue rows red, based on Europe/Copenhagen dates. Completed stores are not highlighted as overdue.

Show all stores is stored per account in schema version 4's user_preferences table and included in data exports. It applies across sessions and devices when the Stores page loads or refreshes. Search text is retained in the page URL, independently of the saved preference.

## Project Manager notes

PMs and Admins can add store notes and control each note's inclusion in reports. The creation form defaults to inclusion; existing notes save the report checkbox automatically. Delete note requires confirmation before permanently removing the note. Author name and original creation time are stored with each note. Times are stored in UTC and displayed in Europe/Copenhagen time. Excluded notes are visible only to PMs/Admins on the store page and never rendered in report HTML. Included notes appear in the Print / Save as PDF report with author and date/time. Schema version 5 adds store_pm_notes, included in portable database exports.

## Live dashboard

The PM/Admin dashboard and CSV summary use DashboardReport::live. Stores live uses the same completion definition as the Stores list. Upcoming visits show the next six unfinished stores scheduled today or later. Needs attention links to Edit store for missing installation dates and overdue unfinished stores. Recent activity lists the latest five recorded step changes, note additions, and store creations; it is not a history of every field edit. Workstream counts also use actual completion/sign-off data. Report preview opens in a new tab; PM notes sit below the checklist and above manual reminders.

The Recent activity dashboard heading opens the full PM/Admin activity history with 10, 50, or 100 entries per page, Previous/Next links, and a page-number control. Entries are ordered newest first.
