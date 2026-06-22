<?php
// Settings Migrate dashboard widget.
global $db;
$smLast = null;
try {
    $r = $db->query('SELECT * FROM settingsmigrate_log ORDER BY id DESC LIMIT 1');
    $smLast = $r->count() ? $r->first() : null;
} catch (Exception $e) {
}
?>
<div class="card dash-card" data-id="<?= $widgetName ?>" id="<?= $widgetName ?>-card">
  <div class="card-header" id="<?= $widgetName ?>-card-header">
    <span class="collapseCard" data-card="<?= $widgetName ?>" id="<?= $widgetName ?>-caret"><i class="fa fa-caret-down"></i></span>
    <span class="card-title-text">Settings Migrate</span>
    <span class="float-end"><i class="fa-solid fa-grip ps-2 grippy"></i></span>
  </div>
  <div class="card-body" id="<?= $widgetName ?>-card-body">
    <?php if ($smLast) { ?>
      <p class="card-text mb-2"><small class="text-muted">Last <?= safeReturn($smLast->Action) ?> - <?= safeReturn($smLast->Created) ?></small><br><?= safeReturn($smLast->Summary) ?></p>
    <?php } else { ?>
      <p class="card-text text-muted mb-2">No exports or imports yet.</p>
    <?php } ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= $us_url_root ?>users/admin.php?view=plugins_config&plugin=settingsmigrate">Export / Import</a>
  </div>
</div>
