<?php
// Streams the export bundle as a JSON download. POST-only, CSRF + master-account gated.
require_once dirname(__DIR__, 4) . '/users/init.php';

if (!isset($user) || !$user->isLoggedIn() || !in_array($user->data()->id, $master_account)) {
    http_response_code(403);
    exit('Forbidden');
}
if (!Token::check(Input::get('csrf'))) {
    http_response_code(403);
    exit('Invalid security token');
}

$includeSecrets = Input::get('include_secrets') ? true : false;
$bundle = settingsMigrateExport(['include_secrets' => $includeSecrets]);

if (function_exists('settingsMigrateLog')) {
    settingsMigrateLog('export', count($bundle['settings'] ?? []) . ' settings, ' . count($bundle['permissions'] ?? []) . ' permissions, secrets=' . ($includeSecrets ? 'yes' : 'no'));
}
logger($user->data()->id, 'SettingsMigrate', 'Config bundle exported' . ($includeSecrets ? ' WITH secrets' : ' (secrets redacted)'));

$filename = 'userspice-settings-' . date('Ymd-His') . '.json';
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
