<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/dash_prefs.php';
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    if (($_POST['do'] ?? '') === 'reset') { dpf_save($pdo, $meId, [], DPF_QUICK_DEFAULT); $notice = 'Dashboard reset.'; }
    else {
        $shown = (array) ($_POST['show'] ?? []);
        $hide = array_values(array_diff(array_keys(DPF_SECTIONS), array_map('strval', $shown)));
        dpf_save($pdo, $meId, $hide, array_map('strval', (array) ($_POST['quick'] ?? [])));
        $notice = 'Dashboard saved.';
    }
}
$P = dpf_get($pdo, $meId);
$page_title = 'Customize dashboard';
require 'includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/admin-dash-gold.css?v=4">
<?php if ($notice): ?><div class="notice"><?= admin_h($notice) ?> <a href="index.php" style="font-weight:800">Back to dashboard ›</a></div><?php endif; ?>
<p class="muted">Only you see these changes. Other admins keep their own dashboard.</p>
<form method="post" class="cz"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
  <div class="pan"><h2>Show on my dashboard</h2>
    <?php foreach (DPF_SECTIONS as $id => $label): ?><label><input type="checkbox" name="show[]" value="<?= $id ?>" <?= in_array($id, $P['hide'], true) ? '' : 'checked' ?>> <?= admin_h($label) ?></label><?php endforeach; ?></div>
  <div class="pan"><h2>Quick actions</h2><div class="sub">Pick up to 8. They show in the order below.</div>
    <?php foreach (DPF_QUICK as $id => $d): ?><label><input type="checkbox" name="quick[]" value="<?= $id ?>" <?= in_array($id, $P['quick'], true) ? 'checked' : '' ?>> <?= $d[2] ?> <b><?= admin_h($d[0]) ?></b> <small><?= admin_h($d[1]) ?></small></label><?php endforeach; ?></div>
  <button class="btn" type="submit">Save</button> <button class="btn gray" type="submit" name="do" value="reset" onclick="return confirm('Reset your dashboard to the default?')">Reset</button>
</form>
<?php require 'includes/footer.php'; ?>
