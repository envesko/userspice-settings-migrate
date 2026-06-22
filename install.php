<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $db->query('CREATE TABLE IF NOT EXISTS settingsmigrate_log (
        id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
        Action VARCHAR(40) NOT NULL,
        Summary VARCHAR(255) NULL,
        Created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CreatedBy INT(11) UNSIGNED NULL
    )');
    if (!$db->error()) {
        logger($user->data()->id, 'SettingsMigrate', '[INSTALL] [SQL] [SUCCESS] Created settingsmigrate_log table');
    } else {
        logger($user->data()->id, 'SettingsMigrate', '[INSTALL] [SQL] [WARNING] settingsmigrate_log failed', json_encode(['ERROR' => $db->errorString()]));
    }

    // Copy dashboard widget
    $widgetSrc = "{$abs_us_root}{$us_url_root}usersc/plugins/{$plugin_name}/assets/widget/";
    $widgetDest = "{$abs_us_root}{$us_url_root}usersc/widgets/settingsmigrate/";
    if (!file_exists($widgetDest)) {
        @mkdir($widgetDest, 0777, true);
    }
    if (file_exists($widgetSrc) && file_exists($widgetDest)) {
        foreach (array_diff(scandir($widgetSrc), ['.', '..']) as $file) {
            copy($widgetSrc . $file, $widgetDest . $file);
        }
    }

    $hooks = [];
    registerHooks($hooks, $plugin_name);

    $check = $db->query('SELECT * FROM us_plugins WHERE plugin = ?', [$plugin_name])->count();
    if ($check > 0) {
        err($plugin_name . ' has already been installed!');
    } else {
        $db->insert('us_plugins', ['plugin' => $plugin_name, 'status' => 'installed']);
        if (!$db->error()) {
            err($plugin_name . ' installed');
            logger($user->data()->id, 'USPlugins', $plugin_name . ' installed');
        } else {
            err($plugin_name . ' was not installed');
            logger($user->data()->id, 'USPlugins', 'Failed to install plugin, Error: ' . $db->errorString());
        }
    }
}
