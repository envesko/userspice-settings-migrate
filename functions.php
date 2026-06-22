<?php
/**
 * Settings Migrate - export/import engine. Auto-loaded globally for the active plugin.
 *
 * Export produces a portable JSON bundle of site configuration. Import diffs it against the
 * current install (dry-run) and optionally applies it. Secrets are redacted unless explicitly
 * included. All writes are parameter-bound and column-whitelisted.
 */

if (!function_exists('settingsMigrateSecretKeys')) {
    /** Substrings that mark a settings/email column as sensitive (redacted by default). */
    function settingsMigrateSecretKeys()
    {
        return ['api', 'key', 'secret', 'pass', 'token', 'pwd', 'smtp', 'private', 'client_id', 'salt'];
    }
}

if (!function_exists('settingsMigrateIsSecret')) {
    function settingsMigrateIsSecret($column)
    {
        $column = strtolower((string)$column);
        foreach (settingsMigrateSecretKeys() as $needle) {
            if (strpos($column, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('settingsMigrateExport')) {
    /**
     * Build the export bundle.
     * $opts: ['include_secrets' => bool]
     */
    function settingsMigrateExport($opts = [])
    {
        global $db, $user_spice_ver;
        $includeSecrets = !empty($opts['include_secrets']);

        $bundle = [
            'meta' => [
                'bundle_version'   => 1,
                'userspice_version' => isset($user_spice_ver) ? $user_spice_ver : 'unknown',
                'generated'        => date('c'),
                'include_secrets'  => $includeSecrets,
            ],
            'redacted' => [],
        ];

        // --- settings (single row) ---
        $settingsRow = $db->query('SELECT * FROM settings WHERE id = 1')->first();
        $settingsExport = [];
        foreach ((array)$settingsRow as $col => $val) {
            if (!$includeSecrets && settingsMigrateIsSecret($col)) {
                $bundle['redacted'][] = "settings.$col";
                continue;
            }
            $settingsExport[$col] = $val;
        }
        $bundle['settings'] = $settingsExport;

        // --- permissions ---
        $bundle['permissions'] = array_map(function ($r) {
            return (array)$r;
        }, $db->query('SELECT id, name FROM permissions ORDER BY id ASC')->results());

        // --- pages (page guards) ---
        $bundle['pages'] = array_map(function ($r) {
            return (array)$r;
        }, $db->query('SELECT page, private, permission_page_matches_id FROM pages ORDER BY page ASC')->results());

        // --- plugin enable-states ---
        $bundle['plugins'] = array_map(function ($r) {
            return (array)$r;
        }, $db->query('SELECT plugin, status FROM us_plugins ORDER BY plugin ASC')->results());

        // --- menus (Bootstrap menu system) ---
        $bundle['menus'] = [];
        foreach (['us_menus', 'us_menu_items', 'groups_menus'] as $mt) {
            try {
                $bundle['menus'][$mt] = array_map(function ($r) {
                    return (array)$r;
                }, $db->query("SELECT * FROM `$mt`")->results());
            } catch (Exception $e) {
                $bundle['menus'][$mt] = [];
            }
        }

        // --- contributed config from other plugins (migrate_export.php returning an array) ---
        $bundle['plugin_config'] = settingsMigrateCollectPluginConfig();

        return $bundle;
    }
}

if (!function_exists('settingsMigrateCollectPluginConfig')) {
    function settingsMigrateCollectPluginConfig()
    {
        global $abs_us_root, $us_url_root, $usplugins;
        $out = [];
        $base = $abs_us_root . $us_url_root . 'usersc/plugins/';
        if (!is_array($usplugins)) {
            return $out;
        }
        foreach ($usplugins as $p => $active) {
            $file = $base . $p . '/migrate_export.php';
            if ($active && file_exists($file)) {
                try {
                    $data = include $file; // expected to RETURN an array
                    if (is_array($data)) {
                        $out[$p] = $data;
                    }
                } catch (Exception $e) {
                }
            }
        }
        return $out;
    }
}

if (!function_exists('settingsMigrateColumns')) {
    /** Real column names of a table via information_schema (works even on an empty table). */
    function settingsMigrateColumns($table)
    {
        global $db;
        static $cache = [];
        $table = preg_replace('/[^a-z_]/i', '', $table);
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $cols = [];
        try {
            $rows = $db->query(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            )->results();
            foreach ($rows as $r) {
                $cols[] = $r->COLUMN_NAME;
            }
        } catch (Exception $e) {
        }
        $cache[$table] = $cols;
        return $cols;
    }
}

if (!function_exists('settingsMigrateImport')) {
    /**
     * Apply (or dry-run) an import bundle.
     * Returns ['changes' => [ ['area','detail','type'=>add|change|skip] ... ], 'applied' => bool].
     */
    function settingsMigrateImport($bundle, $dryRun = true)
    {
        global $db, $user_spice_ver;
        $changes = [];
        if (!is_array($bundle) || !isset($bundle['meta'])) {
            return ['changes' => [['area' => 'bundle', 'detail' => 'Invalid or unrecognised bundle', 'type' => 'error']], 'applied' => false];
        }

        // Version compatibility warning (major.minor mismatch)
        $bundleVer = $bundle['meta']['userspice_version'] ?? 'unknown';
        $here = isset($user_spice_ver) ? $user_spice_ver : 'unknown';
        $mm = function ($v) {
            return preg_match('/^(\d+\.\d+)/', (string)$v, $m) ? $m[1] : null;
        };
        if ($mm($bundleVer) && $mm($here) && $mm($bundleVer) !== $mm($here)) {
            $changes[] = ['area' => 'compatibility', 'detail' => "Bundle was made on UserSpice $bundleVer; this site is $here. Review carefully - schemas may differ.", 'type' => 'warning'];
        }

        // --- settings ---
        if (!empty($bundle['settings']) && is_array($bundle['settings'])) {
            $cols = settingsMigrateColumns('settings');
            $current = (array)$db->query('SELECT * FROM settings WHERE id = 1')->first();
            $update = [];
            foreach ($bundle['settings'] as $col => $val) {
                if ($col === 'id' || !in_array($col, $cols, true)) {
                    continue; // unknown column - skip
                }
                if (settingsMigrateIsSecret($col)) {
                    continue; // never import secrets
                }
                $cur = array_key_exists($col, $current) ? $current[$col] : null;
                if ((string)$cur !== (string)$val) {
                    $changes[] = ['area' => 'settings', 'detail' => "$col: '" . settingsMigrateTrunc($cur) . "' → '" . settingsMigrateTrunc($val) . "'", 'type' => 'change'];
                    $update[$col] = $val;
                }
            }
            if (!$dryRun && !empty($update)) {
                $db->update('settings', 1, $update);
            }
        }

        // --- permissions (by id) ---
        if (!empty($bundle['permissions'])) {
            foreach ($bundle['permissions'] as $perm) {
                if (!isset($perm['id'])) {
                    continue;
                }
                $existing = $db->query('SELECT id, name FROM permissions WHERE id = ?', [(int)$perm['id']]);
                if (!$existing->count()) {
                    $changes[] = ['area' => 'permissions', 'detail' => "add #{$perm['id']} '{$perm['name']}'", 'type' => 'add'];
                    if (!$dryRun) {
                        $db->insert('permissions', ['id' => (int)$perm['id'], 'name' => $perm['name']]);
                    }
                } elseif ($existing->first()->name !== $perm['name']) {
                    $changes[] = ['area' => 'permissions', 'detail' => "#{$perm['id']} rename → '{$perm['name']}'", 'type' => 'change'];
                    if (!$dryRun) {
                        $db->update('permissions', (int)$perm['id'], ['name' => $perm['name']]);
                    }
                }
            }
        }

        // --- pages (by page name) ---
        if (!empty($bundle['pages'])) {
            foreach ($bundle['pages'] as $page) {
                if (empty($page['page'])) {
                    continue;
                }
                $existing = $db->query('SELECT id, private FROM pages WHERE page = ?', [$page['page']]);
                if (!$existing->count()) {
                    $changes[] = ['area' => 'pages', 'detail' => "add page '{$page['page']}' (private={$page['private']})", 'type' => 'add'];
                    if (!$dryRun) {
                        $db->insert('pages', ['page' => $page['page'], 'private' => (int)$page['private']]);
                    }
                } elseif ((string)$existing->first()->private !== (string)$page['private']) {
                    // private 1 -> 0 means a guarded page becomes PUBLIC: security-sensitive
                    $sensitive = ((int)$existing->first()->private === 1 && (int)$page['private'] === 0);
                    $changes[] = ['area' => 'pages', 'detail' => "'{$page['page']}' private: {$existing->first()->private} → {$page['private']}" . ($sensitive ? '  (page becomes PUBLIC)' : ''), 'type' => 'change', 'sensitive' => $sensitive];
                    if (!$dryRun) {
                        $db->update('pages', (int)$existing->first()->id, ['private' => (int)$page['private']]);
                    }
                }
            }
        }

        // --- plugin enable-states ---
        if (!empty($bundle['plugins'])) {
            foreach ($bundle['plugins'] as $pl) {
                if (empty($pl['plugin'])) {
                    continue;
                }
                $existing = $db->query('SELECT id, status FROM us_plugins WHERE plugin = ?', [$pl['plugin']]);
                if (!$existing->count()) {
                    $changes[] = ['area' => 'plugins', 'detail' => "{$pl['plugin']} not installed here (status '{$pl['status']}' in bundle) - install it manually", 'type' => 'skip'];
                } elseif ($existing->first()->status !== $pl['status']) {
                    // disabling a known security plugin is sensitive
                    $securityPlugins = ['recaptcha', 'twofactor', 'hasher', 'html_purifier', 'iplock', 'ipcheckcloudflare', 'cloakplus'];
                    $disabling = ($existing->first()->status === 'active' && $pl['status'] !== 'active');
                    $sensitive = $disabling && in_array($pl['plugin'], $securityPlugins, true);
                    $changes[] = ['area' => 'plugins', 'detail' => "{$pl['plugin']} status: {$existing->first()->status} → {$pl['status']}" . ($sensitive ? '  (disables a security plugin)' : ''), 'type' => 'change', 'sensitive' => $sensitive];
                    if (!$dryRun) {
                        $db->update('us_plugins', (int)$existing->first()->id, ['status' => $pl['status']]);
                    }
                }
            }
        }

        // --- menus (ADD-ONLY: existing rows are left untouched so referenced ids never break) ---
        if (!empty($bundle['menus']) && is_array($bundle['menus'])) {
            foreach (['us_menus', 'us_menu_items', 'groups_menus'] as $mt) {
                if (empty($bundle['menus'][$mt])) {
                    continue;
                }
                $cols = settingsMigrateColumns($mt);
                if (empty($cols)) {
                    continue;
                }
                $existingSigs = [];
                try {
                    foreach ($db->query("SELECT * FROM `$mt`")->results() as $er) {
                        $existingSigs[settingsMigrateRowSig((array)$er, $cols)] = true;
                    }
                } catch (Exception $e) {
                    continue;
                }
                foreach ($bundle['menus'][$mt] as $brow) {
                    $sig = settingsMigrateRowSig($brow, $cols);
                    if (isset($existingSigs[$sig])) {
                        continue;
                    }
                    $changes[] = ['area' => "menus.$mt", 'detail' => 'add row [' . substr($sig, 0, 60) . '] - ids may differ across sites; review', 'type' => 'add'];
                    if (!$dryRun) {
                        $insert = [];
                        foreach ($brow as $k => $v) {
                            if ($k !== 'id' && in_array($k, $cols, true)) {
                                $insert[$k] = $v;
                            }
                        }
                        if ($insert) {
                            try {
                                $db->insert($mt, $insert);
                            } catch (Exception $e) {
                            }
                        }
                    }
                }
            }
        }

        return ['changes' => $changes, 'applied' => !$dryRun];
    }
}

if (!function_exists('settingsMigrateRowSig')) {
    /** Stable signature of a row from its non-id columns (for add-only dedup). */
    function settingsMigrateRowSig($row, $cols)
    {
        $parts = [];
        foreach ($cols as $c) {
            if ($c === 'id') {
                continue;
            }
            $parts[$c] = isset($row[$c]) ? (string)$row[$c] : '';
        }
        ksort($parts);
        return md5(json_encode($parts));
    }
}

if (!function_exists('settingsMigrateTrunc')) {
    function settingsMigrateTrunc($v)
    {
        $v = (string)$v;
        return strlen($v) > 40 ? substr($v, 0, 40) . '…' : $v;
    }
}

if (!function_exists('settingsMigrateLog')) {
    function settingsMigrateLog($action, $summary)
    {
        global $db, $user;
        try {
            $db->insert('settingsmigrate_log', [
                'Action'    => substr((string)$action, 0, 40),
                'Summary'   => substr((string)$summary, 0, 255),
                'Created'   => date('Y-m-d H:i:s'),
                'CreatedBy' => isset($user) ? (int)$user->data()->id : null,
            ]);
        } catch (Exception $e) {
        }
    }
}
