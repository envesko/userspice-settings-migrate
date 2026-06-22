<?php
// Settings Migrate - admin config page. admin.php?view=plugins_config&plugin=settingsmigrate
if (!in_array($user->data()->id, $master_account)) {
    die('You do not have permission to configure this plugin.');
}
global $db;
$selfUrl = $us_url_root . 'users/admin.php?view=plugins_config&plugin=settingsmigrate';
$dlUrl = $us_url_root . 'usersc/plugins/settingsmigrate/files/download.php';

$dryRunResult = null;
$importJson = '';

if (Input::exists()) {
    if (!Token::check(Input::get('csrf'))) {
        usError('Invalid security token. Please refresh and try again.');
        Redirect::to($selfUrl);
    }
    $do = Input::get('do');

    if ($do === 'dryrun' || $do === 'apply') {
        // Source: uploaded file wins, else pasted textarea
        $raw = $_POST['import_json'] ?? '';
        if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $uploaded = file_get_contents($_FILES['import_file']['tmp_name']);
            if ($uploaded !== false && trim($uploaded) !== '') {
                $raw = $uploaded;
            }
        }
        $importJson = $raw;
        if (strlen((string)$raw) > 5 * 1024 * 1024) {
            usError('Bundle is too large (over 5 MB). Refusing to import.');
            Redirect::to($selfUrl);
        }
        $bundle = json_decode($raw, true);
        if (!is_array($bundle)) {
            usError('Could not parse that JSON bundle.');
        } else {
            $dryRunResult = settingsMigrateImport($bundle, $do === 'dryrun');
            if ($do === 'apply') {
                $applied = array_filter($dryRunResult['changes'], function ($c) {
                    return in_array($c['type'], ['add', 'change'], true);
                });
                settingsMigrateLog('import', count($applied) . ' change(s) applied');
                logger($user->data()->id, 'SettingsMigrate', count($applied) . ' config change(s) applied via import');
                usSuccess(count($applied) . ' change(s) applied.');
                Redirect::to($selfUrl);
            }
        }
    }
}

$log = $db->query('SELECT * FROM settingsmigrate_log ORDER BY id DESC LIMIT 10')->results();
?>
<style>
  .ehb-hero { background:linear-gradient(135deg,#2ea5cb 0%,#2edcb7 100%); color:#fff; border-radius:.6rem; padding:1rem 1.25rem; margin-bottom:1rem; }
  .ehb-hero h2 { color:#fff; margin:0; font-weight:600; }
  .ehb-hero p { margin:.25rem 0 0; opacity:.95; }
</style>
<div class="container-fluid">
  <div class="ehb-hero">
    <h2><i class="fa fa-right-left me-2"></i>Settings Migrate <span style="font-weight:400;opacity:.85;font-size:1rem">by Envesko</span></h2>
    <p>Move site configuration between installs. Export here, import on the target.</p>
  </div>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-export" type="button">Export</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-import" type="button">Import</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-log" type="button">History</button></li>
  </ul>

  <div class="tab-content">
    <!-- Export -->
    <div class="tab-pane fade show active" id="tab-export">
      <div class="card" style="max-width:640px">
        <div class="card-body">
          <p>Download a JSON bundle of this site's <strong>settings</strong>, <strong>permissions</strong>,
             <strong>page guards</strong>, and <strong>plugin enable-states</strong>.</p>
          <form method="post" action="<?= safeReturn($dlUrl) ?>">
            <?= tokenHere() ?>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="include_secrets" id="include_secrets" value="1">
              <label class="form-check-label" for="include_secrets">
                Include secrets (API keys, SMTP passwords, tokens)
                <span class="text-danger">- off by default; only enable for a trusted destination</span>
              </label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-download me-1"></i> Download bundle</button>
          </form>
          <hr>
          <p class="small text-muted mb-0">Other plugins can add their own config to the bundle by shipping a
            <code>migrate_export.php</code> that returns an array.</p>
        </div>
      </div>
    </div>

    <!-- Import -->
    <div class="tab-pane fade" id="tab-import">
      <div class="alert alert-info">Import always shows a <strong>dry-run diff first</strong>. Nothing changes until you click <em>Apply</em>.</div>
      <form method="post" action="<?= safeReturn($selfUrl) ?>" enctype="multipart/form-data" style="max-width:760px">
        <?= tokenHere() ?>
        <div class="mb-3">
          <label class="form-label">Upload bundle file</label>
          <input type="file" name="import_file" class="form-control" accept="application/json,.json">
        </div>
        <div class="mb-3">
          <label class="form-label">…or paste bundle JSON</label>
          <textarea name="import_json" class="form-control" rows="6" placeholder='{"meta":{...},"settings":{...}}'><?= safeReturn($importJson) ?></textarea>
        </div>
        <button type="submit" name="do" value="dryrun" class="btn btn-secondary">Dry run (preview)</button>
      </form>

      <?php if ($dryRunResult !== null) { ?>
        <div class="card mt-3" style="max-width:760px">
          <div class="card-header">Proposed changes (<?= count($dryRunResult['changes']) ?>)</div>
          <div class="card-body p-0">
            <?php if (empty($dryRunResult['changes'])) { ?>
              <div class="p-3 text-muted">No differences - the target already matches this bundle.</div>
            <?php } else { ?>
              <table class="table table-sm mb-0">
                <thead><tr><th>Area</th><th>Change</th><th>Type</th></tr></thead>
                <tbody>
                <?php foreach ($dryRunResult['changes'] as $ch) {
                    $sensitive = !empty($ch['sensitive']) || $ch['type'] === 'warning';
                    $cls = ['add' => 'text-success', 'change' => 'text-primary', 'skip' => 'text-muted', 'error' => 'text-danger', 'warning' => 'text-danger'][$ch['type']] ?? '';
                    if (!empty($ch['sensitive'])) { $cls = 'text-danger fw-bold'; }
                ?>
                  <tr<?= $sensitive ? ' class="table-danger"' : '' ?>>
                    <td><?= safeReturn($ch['area']) ?></td>
                    <td class="<?= $cls ?>"><?php if ($sensitive) { ?><i class="fa fa-triangle-exclamation me-1"></i><?php } ?><?= safeReturn($ch['detail']) ?></td>
                    <td><span class="badge bg-light text-dark"><?= safeReturn($ch['type']) ?></span></td>
                  </tr>
                <?php } ?>
                </tbody>
              </table>
            <?php } ?>
          </div>
          <?php
            $applicable = array_filter($dryRunResult['changes'], function ($c) {
                return in_array($c['type'], ['add', 'change'], true);
            });
            if (!empty($applicable)) { ?>
            <div class="card-footer">
              <form method="post" action="<?= safeReturn($selfUrl) ?>"
                onsubmit="return confirm('Apply <?= count($applicable) ?> change(s) to this install?');">
                <?= tokenHere() ?>
                <input type="hidden" name="import_json" value="<?= safeReturn($importJson) ?>">
                <button type="submit" name="do" value="apply" class="btn btn-danger">
                  <i class="fa fa-check me-1"></i> Apply <?= count($applicable) ?> change(s)
                </button>
              </form>
            </div>
          <?php } ?>
        </div>
      <?php } ?>
    </div>

    <!-- History -->
    <div class="tab-pane fade" id="tab-log">
      <?php if (empty($log)) { ?>
        <p class="text-muted">No exports or imports yet.</p>
      <?php } else { ?>
        <table class="table table-sm" style="max-width:760px">
          <thead><tr><th>When</th><th>Action</th><th>Summary</th></tr></thead>
          <tbody>
          <?php foreach ($log as $l) { ?>
            <tr><td><?= safeReturn($l->Created) ?></td><td><span class="badge bg-secondary"><?= safeReturn($l->Action) ?></span></td><td><?= safeReturn($l->Summary) ?></td></tr>
          <?php } ?>
          </tbody>
        </table>
      <?php } ?>
    </div>
  </div>
</div>
