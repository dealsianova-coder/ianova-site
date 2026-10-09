<?php
require_once 'includes/auth.php';

$defaults = [
    'announcement_enabled' => '0',
    'announcement_text' => '',
    'delivery_fee' => '3.5',
    'free_delivery_threshold' => '1500',
    'show_flash' => '1',
    'show_sale' => '1',
    'show_recent' => '1',
    'show_popular' => '1',
    'show_categories' => '1',
    'support_phone' => '',
    'support_email' => '',
];
$toggles = ['announcement_enabled', 'show_flash', 'show_sale', 'show_recent', 'show_popular', 'show_categories'];
$numbers = ['delivery_fee', 'free_delivery_threshold'];

$pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB");
foreach ($defaults as $k => $v) {
    $pdo->prepare('INSERT IGNORE INTO settings(setting_key, setting_value) VALUES(?, ?)')->execute([$k, $v]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    foreach ($defaults as $k => $d) {
        if (in_array($k, $toggles, true)) {
            $value = isset($_POST[$k]) ? '1' : '0';
        } elseif (in_array($k, $numbers, true)) {
            $value = (string) max(0, (float) ($_POST[$k] ?? 0));
        } else {
            $value = trim((string) ($_POST[$k] ?? ''));
        }
        $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $k]);
    }
    header('Location: store.php?saved=1');
    exit;
}

$s = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $r) {
    $s[$r['setting_key']] = $r['setting_value'];
}
$on = function (string $k) use ($s): string { return (($s[$k] ?? '1') === '1') ? 'checked' : ''; };

$page_title = 'Store control';
require 'includes/header.php';
?>

<div class="card form-card">
<?php if (isset($_GET['saved'])): ?>
  <div class="notice">Saved. The app and the website use these right away.</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">

<h3>Announcement bar</h3>
<p class="muted">A message across the top of the website and the app home screen.</p>
<div class="field"><label><input type="checkbox" name="announcement_enabled" value="1" <?= (($s['announcement_enabled'] ?? '0') === '1') ? 'checked' : '' ?>> Show the announcement</label></div>
<div class="field"><label>Message</label><input name="announcement_text" maxlength="160" value="<?= admin_h($s['announcement_text'] ?? '') ?>" placeholder="e.g. Weekend sale: up to 40% off"></div>

<h3 style="margin-top:28px">Delivery</h3>
<div class="row">
  <div class="field"><label>Free delivery on orders over (KSh)</label><input type="number" min="0" step="1" name="free_delivery_threshold" value="<?= admin_h($s['free_delivery_threshold'] ?? '1500') ?>"></div>
  <div class="field"><label>Delivery fee below that (KSh)</label><input type="number" min="0" step="0.01" name="delivery_fee" value="<?= admin_h($s['delivery_fee'] ?? '3.5') ?>"></div>
</div>

<h3 style="margin-top:28px">Home screen sections</h3>
<p class="muted">Turn parts of the front store on or off. Applies to the app and, where it exists, the website.</p>
<div class="field"><label><input type="checkbox" name="show_flash" value="1" <?= $on('show_flash') ?>> Flash deals</label></div>
<div class="field"><label><input type="checkbox" name="show_sale" value="1" <?= $on('show_sale') ?>> Sale shortcut (app)</label></div>
<div class="field"><label><input type="checkbox" name="show_categories" value="1" <?= $on('show_categories') ?>> Category shortcuts (app)</label></div>
<div class="field"><label><input type="checkbox" name="show_recent" value="1" <?= $on('show_recent') ?>> Recently viewed (app)</label></div>
<div class="field"><label><input type="checkbox" name="show_popular" value="1" <?= $on('show_popular') ?>> Popular picks (app)</label></div>

<h3 style="margin-top:28px">Support contact</h3>
<div class="row">
  <div class="field"><label>Phone</label><input name="support_phone" value="<?= admin_h($s['support_phone'] ?? '') ?>"></div>
  <div class="field"><label>Email</label><input name="support_email" value="<?= admin_h($s['support_email'] ?? '') ?>"></div>
</div>

<button class="btn">Save changes</button>
<a class="btn gray" href="flash.php" style="margin-left:8px">Manage flash deals</a>
</form>
</div>

<?php require 'includes/footer.php'; ?>
