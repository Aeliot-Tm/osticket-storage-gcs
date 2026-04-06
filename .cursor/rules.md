# osticket-storage-gcp plugin

Extends [global `.cursor/rules/development.mdc`](../../../../.cursor/rules/development.mdc).

## Scope

- Do not modify osTicket core for this plugin; see **Plugin development vs core changes**
  in the global [development.mdc](../../../../.cursor/rules/development.mdc) (core edits only if the user explicitly asks).
- PHP 8.2 compatible code; comments and log messages in English.
- At most one consecutive blank line in source files.
- After `composer install`, dependencies live under `vendor/` (Composer default). Do not hand-edit generated files in `vendor/`.

## Bootstrap

- `storage.php` loads `vendor/autoload.php` before defining classes that reference `Google\Cloud\*`.

## Security

- Never commit real service account credentials. Prefer a mounted secret path in `service-account-json`,
  or paste inline JSON only when the database and backups are treated as sensitive (credentials are stored in osTicket config).
