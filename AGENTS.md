# Project instructions

- Keep responses short and concrete. Ask if unsure.
- Local development uses the `dev` Git branch and `APP_ENV=dev` for the time being.
- Push development changes to `origin/dev`. Only merge or push to `master` when the user explicitly requests it.
- Keep Git identity configuration local to this repository: `sittpo <tpo@serenit.dk>`.
- Never commit `.env`, credentials, local databases, runtime files, or photo uploads.
- Keep all conversation, interface text, documentation, and code comments in English.
- Use the reference dashboard color scheme: dark teal sidebar, teal/green accents, neutral surfaces. No pink accents.
- Keep report data providers separate from views; preserve portable IDs and versioned schema/data exports for production-to-dev workflows.

- Local development uses MariaDB 11.8.6, matching the intended production database engine/version. Keep SQLite only for compatibility tests and migration backups.
- Run the repository and browser suites against MariaDB by default. Tests use disposable jjtest_<10 hex digits> databases, never the application database.
- Role hierarchy: Contractor < Contractor admin < PM < Admin. Admin always has all rights. Contractor admins are scoped to their own company; contractors additionally require a store assignment.
- Global reminder lead time defaults to 7 days before installation, with an exact-date store override.
- Global task templates are copied into new stores. Preserve existing store evidence and sign-offs when templates change; keep interface and report order independent.
- Keep SMTP configuration environment-specific and out of production-to-dev database exports. Dev sending requires an explicit --allow-dev-send flag.

- Do not use em dashes in interface text, documentation, comments, or responses. Use a spaced hyphen ( - ) instead.
