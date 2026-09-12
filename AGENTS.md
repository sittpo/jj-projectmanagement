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
