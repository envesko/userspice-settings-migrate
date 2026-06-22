# Settings Migrate - AI instructions

Export/import UserSpice site configuration as a redacted JSON bundle, with a dry-run diff before
apply. For moving a site between dev/staging/production.

## Public functions (functions.php, auto-loaded when active)
- `settingsMigrateExport($opts=['include_secrets'=>bool])` - bundle of `settings`, `permissions`,
  `pages` (guards), `us_plugins` states, menus (`us_menus`/`us_menu_items`/`groups_menus`), and any
  plugin-contributed config. Secrets redacted unless explicitly included.
- `settingsMigrateImport($bundle, $dryRun=true)` - returns a list of changes. Settings are
  column-whitelisted (via `information_schema`) and secret-skipping; menus import ADD-ONLY; pages
  going public and security-plugins being disabled are flagged `sensitive`; version mismatch emits a
  `warning`. Apply only when `$dryRun=false`.
- `settingsMigrateIsSecret($col)` · `settingsMigrateColumns($table)` · `settingsMigrateLog(...)`.

## Tables
`settingsmigrate_log` - audit trail of exports/imports (dropped only on delete).

## Lifecycle files
install/activate/uninstall (retain log)/delete (drop log)/migrate/configure. Download endpoint
`files/download.php` (master + CSRF gated, streams the JSON, logs to core logger).

## Extension point
Any other plugin can contribute portable config by shipping `migrate_export.php` that `return`s an
array - it appears under `plugin_config` in the bundle.

## Key conventions
Import is dry-run-first, column-whitelisted, secret-skipping, 5 MB-capped, and parameter-bound.
Security-sensitive diffs are highlighted; the admin must read the diff before applying.
