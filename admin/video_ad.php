<?php
require_once 'includes/auth.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT NULL
    ) ENGINE=InnoDB
");

$defaults = [
    'video_ad_enabled'          => '0',
    'video_ad_src'               => '',
    'video_ad_interval_minutes'  => '3',
    'video_ad_min_seconds'       => '30',
    'video_ad_link_type'         => 'none',
    'video_ad_link_value'        => '',
];
foreach ($defaults as $key => $value) {
    $pdo->prepare('INSERT IGNORE INTO settings(setting_key, setting_value) VALUES(?, ?)')->execute([$key, $value]);
}

function video_ad_upload(): ?string {
    if (empty($_FILES['video']['name']) || ($_FILES['video']['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'webm', 'mov'], true)) return null;
    if ($_FILES['video']['size'] > 40 * 1024 * 1024) return null; // 40MB cap
    $dir = __DIR__ . '/../uploads/ads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    return move_uploaded_file($_FILES['video']['tmp_name'], "$dir/$name") ? "uploads/ads/$name" : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    $enabled  = isset($_POST['video_ad_enabled']) ? '1' : '0';
    $interval = max(1, (int) ($_POST['video_ad_interval_minutes'] ?? 3));
    $minSec   = max(5, (int) ($_POST['video_ad_min_seconds'] ?? 30));

    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$enabled, 'video_ad_enabled']);
    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([(string) $interval, 'video_ad_interval_minutes']);
    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([(string) $minSec, 'video_ad_min_seconds']);

    $linkType = in_array($_POST['video_ad_link_type'] ?? '', ['none', 'product', 'category', 'url'], true) ? $_POST['video_ad_link_type'] : 'none';
    $linkValue = '';
    if ($linkType === 'product')  $linkValue = (string) (int) ($_POST['video_ad_link_product'] ?? 0);
    if ($linkType === 'category') $linkValue = (string) (int) ($_POST['video_ad_link_category'] ?? 0);
    if ($linkType === 'url')      $linkValue = substr(trim((string) ($_POST['video_ad_link_url'] ?? '')), 0, 255);
    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$linkType, 'video_ad_link_type']);
    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$linkValue, 'video_ad_link_value']);

    $uploaded = video_ad_upload();
    if ($uploaded) {
        $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$uploaded, 'video_ad_src']);
    } elseif (!empty($_POST['clear_video'])) {
        $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute(['', 'video_ad_src']);
    }

    header('Location: video_ad.php?saved=1');
    exit;
}

$rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
$settings = [];
foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];

$products = $pdo->query("SELECT id, name FROM products WHERE status='approved' ORDER BY name LIMIT 500")->fetchAll();
$cats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$page_title = 'Video ad';
require 'includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?><div class="notice">Saved. The website will pick this up right away.</div><?php endif; ?>

<div class="card form-card">
<h3>Front-store video ad</h3>
<p class="muted">The video ad plays automatically over the home-page banner carousel, on a timer, then the site returns to rotating your regular banners.</p>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">

<div class="field">
  <label><input type="checkbox" name="video_ad_enabled" value="1" <?= ($settings['video_ad_enabled'] ?? '0') === '1' ? 'checked' : '' ?>> Show the video ad on the website</label>
</div>

<div class="field">
  <label>Video file (MP4, WebM or MOV, up to 40MB)</label>
  <input type="file" name="video" accept="video/mp4,video/webm,video/quicktime">
  <?php if (!empty($settings['video_ad_src'])): ?>
    <p class="muted">Current: <?= admin_h($settings['video_ad_src']) ?></p>
    <label><input type="checkbox" name="clear_video" value="1"> Remove current video (uncheck "Show the video ad" too if you want it fully off)</label>
  <?php endif; ?>
</div>

<div class="row">
  <div class="field">
    <label>Play every (minutes)</label>
    <input type="number" name="video_ad_interval_minutes" min="1" value="<?= (int) ($settings['video_ad_interval_minutes'] ?? 3) ?>">
    <p class="hint">How long the banner carousel rotates before the video ad interrupts it.</p>
  </div>
  <div class="field">
    <label>Minimum play length (seconds)</label>
    <input type="number" name="video_ad_min_seconds" min="5" value="<?= (int) ($settings['video_ad_min_seconds'] ?? 30) ?>">
    <p class="hint">The ad stays up at least this long, even if the video itself is shorter.</p>
  </div>
</div>

<div class="field">
  <label>When tapped (shows a small "Shop now" button on the ad), open</label>
  <select name="video_ad_link_type" id="videoAdLinkType">
    <?php foreach (['none' => 'Nothing (no button shown)', 'product' => 'A product', 'category' => 'A category', 'url' => 'A custom link'] as $k => $name): ?>
      <option value="<?= $k ?>" <?= ($settings['video_ad_link_type'] ?? 'none') === $k ? 'selected' : '' ?>><?= $name ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="row">
  <div class="field">
    <label>Product (if "A product")</label>
    <select name="video_ad_link_product">
      <option value="0">-</option>
      <?php foreach ($products as $p): ?>
        <option value="<?= (int) $p['id'] ?>" <?= (($settings['video_ad_link_type'] ?? '') === 'product' && (int) ($settings['video_ad_link_value'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>><?= admin_h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>Category (if "A category")</label>
    <select name="video_ad_link_category">
      <option value="0">-</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (($settings['video_ad_link_type'] ?? '') === 'category' && (int) ($settings['video_ad_link_value'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>><?= admin_h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="field">
  <label>Custom link (if "A custom link") — full URL or a path like category.php?slug=electronics</label>
  <input name="video_ad_link_url" maxlength="255" value="<?= ($settings['video_ad_link_type'] ?? '') === 'url' ? admin_h($settings['video_ad_link_value'] ?? '') : '' ?>">
</div>

<button class="btn">Save video ad settings</button>
</form>
</div>

<?php require 'includes/footer.php'; ?>
