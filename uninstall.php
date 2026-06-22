<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $widgetDest = "{$abs_us_root}{$us_url_root}usersc/widgets/settingsmigrate/";
    if (file_exists($widgetDest)) {
        foreach (array_diff(scandir($widgetDest), ['.', '..']) as $file) {
            @unlink($widgetDest . $file);
        }
        @rmdir($widgetDest);
    }

    // Deactivate = retain data. The settingsmigrate_log table is dropped only on actual delete
    // (see delete.php), so the audit trail survives a deactivate/reactivate.
    $db->query('DELETE FROM us_plugins WHERE plugin = ?', [$plugin_name]);
    $db->query('DELETE FROM us_plugin_hooks WHERE folder = ?', [$plugin_name]);
    logger($user->data()->id, 'USPlugins', $plugin_name . ' uninstalled (log table retained)');
    err($plugin_name . ' uninstalled. The audit log table was retained - delete the plugin to drop it.');
}
