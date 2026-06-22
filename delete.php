<?php

require_once 'init.php';
// Called only when a plugin is actively DELETED (not on deactivate). Drops the audit log.
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $db->query('DROP TABLE IF EXISTS settingsmigrate_log');
    logger($user->data()->id, 'USPlugins', $plugin_name . ' tables dropped on delete');
}
