<?php
require_once 'includes/auth.php';
date_default_timezone_set('Africa/Nairobi');

$pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB");
$pdo->prepare("INSERT IGNORE INTO settings(setting_key, setting_value) VALUES('flash_ends_at', '')")->execute();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    if (($_POST['action'] ?? '') === 'clear') {
        $pdo->exec('UPDATE products SET is_flash_deal = 0');
        header('Location: flash.php?saved=1');
        exit;
    }

    $endsIn = trim((string) ($_POST['flash_ends_at'] ?? ''));
    $ends = '';
    if ($endsIn !== '') {
        $ts = strtotime(str_replace('T', ' ', $endsIn));
        if ($ts) $ends = date('Y-m-d H:i:s', $ts);
    }
    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='flash_ends_at'")->execute([$ends]);

    $upd = $pdo->prepare('UPDATE products SET is_flash_deal=?, price=?, original_price=? WHERE id=?');
    foreach (array_map('intval', $_POST['ids'] ?? []) as $id) {
        $price = (float) ($_POST['price'][$id] ?? 0);
        $orig = (float) ($_POST['orig'][$id] ?? 0);
        if ($price <= 0) continue;
        $upd->execute([isset($_POST['flash'][$id]) ? 1 : 0, $price, $orig > 0 ? $orig : $price, $id]);
    }
    header('Location: flash.php?saved=1');
    exit;
}

$endsRaw = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key='flash_ends_at'")->fetchColumn();
$endsValue = ($endsRaw !== '' && strtotime($endsRaw)) ? date('Y-m-d\TH:i', strtotime($endsRaw)) : '';
$ended = ($endsRaw !== '' && strtotime($endsRaw) && strtotime($endsRaw) < time());

$q = trim((string) ($_GET['q'] ?? ''));
$stmt = $pdo->prepare("SELECT * FROM products WHERE status='approved' AND name LIKE ? ORDER BY is_flash_deal DESC, name, id LIMIT 500");
$stmt->execute(['%' . $q . '%']);
$products = $stmt->fetchAll();

$page_title = 'Flash deals';
require 'includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?><div class="notice">Saved. The app and the website show the new flash deals right away.</div><?php endif; ?>

<form method="get" style="margin-bottom:14px"><input class="search" name="q" value="<?= admin_h($q) ?>" placeholder="Search products by name"> <button class="btn gray">Search</button></form>

<form method="post">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">

<div class="card form-card" style="margin-bottom:16px">
  <div class="field">
    <label>Sale ends at (Nairobi time, optional)</label>
    <input type="datetime-local" name="flash_ends_at" value="<?= admin_h($endsValue) ?>">
    <p class="muted" style="margin:6px 0 0">Shows a real countdown in the app. When this time passes, the flash deals section hides by itself. Leave it empty for no countdown. Now: <?= date('Y-m-d H:i') ?>.</p>
    <?php if ($ended): ?><p class="error" style="margin:8px 0 0;padding:8px 12px;border-radius:12px">This end time has passed, so flash deals are hidden. Pick a new time to show them again.</p><?php endif; ?>
  </div>
</div>

<div class="table-wrap">
<table class="table">
<tr><th>Flash</th><th>Product</th><th>Sale price</th><th>Old price</th><th>Stock</th></tr>
<?php foreach ($products as $p): $id = (int) $p['id']; $opt = trim(($p['color'] ?? '') . ' ' . ($p['size'] ?? '')); ?>
<tr>
  <td><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="checkbox" name="flash[<?= $id ?>]" value="1" <?= !empty($p['is_flash_deal']) ? 'checked' : '' ?>></td>
  <td><?= admin_h($p['name']) ?><?php if ($opt !== ''): ?> <span class="muted">· <?= admin_h($opt) ?></span><?php endif; ?></td>
  <td><input type="number" step="0.01" min="0" name="price[<?= $id ?>]" value="<?= admin_h($p['price']) ?>" style="width:110px"></td>
  <td><input type="number" step="0.01" min="0" name="orig[<?= $id ?>]" value="<?= admin_h($p['original_price']) ?>" style="width:110px"></td>
  <td><?= (int) $p['stock'] ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<p class="muted">The discount % is worked out from the sale price and the old price.</p>
<button class="btn">Save flash deals</button>
<button class="btn red" name="action" value="clear" onclick="return confirm('Remove every product from flash deals?')" style="margin-left:8px">Clear all</button>
</form>

<?php require 'includes/footer.php'; ?>
