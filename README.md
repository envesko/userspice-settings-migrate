# Settings Migrate (UserSpice plugin)

Move site configuration between installs (dev → staging → production). Export a JSON bundle on
one site, import it on another. Secrets are redacted by default; import shows a dry-run diff
before changing anything.

## Install
1. Copy this folder to `usersc/plugins/settingsmigrate/`.
2. Admin → Plugin Manager → activate **Settings Migrate**.

## Use
On the plugin's config page:
- **Export** - downloads a JSON bundle of `settings`, `permissions`, page guards (`pages`), plugin
  enable-states, and **menus** (`us_menus`/`us_menu_items`/`groups_menus`). Secrets (API keys, SMTP
  passwords, tokens) are redacted unless you opt in.
- **Import** - upload or paste a bundle (capped at 5 MB). A **dry-run diff** lists every add/change
  first; nothing is written until you click **Apply**. Unknown columns and secret columns are never
  imported. **Security-sensitive changes are highlighted in red** - a page becoming public, a
  security plugin being disabled - and a **version-mismatch warning** appears if the bundle was made
  on a different UserSpice major.minor. Menus import **add-only** (existing rows untouched so
  referenced ids never break; review added menu rows, as ids can differ across sites).

## Extending
Any other plugin can contribute its own portable config to the bundle by shipping a
`migrate_export.php` that `return`s an array. It appears under `plugin_config` in the export.

## Tables
`settingsmigrate_log` - a short audit trail of exports/imports. Dropped on uninstall.

## Notes
Import covers settings, permissions, page guards, plugin states, and menus - the high-value config
that is tedious to move by hand. It does not move user accounts (use the CSV Importer/Exporter for
that) or copy plugin *code* (use Spice Shaker to install the same plugins on the target first).

---

© 2026 **Envesko** - released under the MIT License (see `LICENSE`). Author, maintainer, and code owner: Envesko.
