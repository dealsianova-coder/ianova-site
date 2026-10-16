<?php
require_once 'includes/auth.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS banners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  subtitle VARCHAR(160) NOT NULL DEFAULT '',
  button_label VARCHAR(40) NOT NULL DEFAULT 'Shop now',
  image VARCHAR(255) NOT NULL DEFAULT '',
  link_type VARCHAR(20) NOT NULL DEFAULT 'none',
  link_value VARCHAR(255) NOT NULL DEFAULT '',
  color VARCHAR(20) NOT NULL DEFAULT 'blush',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function banner_upload(): ?string {
    if (empty($_FILES['image']['name']) || ($_FILES['image']['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return null;
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) return null;
    $dir = __DIR__ . '/../uploads/banners';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    return move_uploaded_file($_FILES['image']['tmp_name'], "$dir/$name") ? "uploads/banners/$name" : null;
}

$colors = ['blush' => 'Pink', 'sky' => 'Blue', 'sand' => 'Sand', 'rose' => 'Rose'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $action = $_POST['action'] ?? 'save';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM banners WHERE id = ?')->execute([$id]);
    } elseif ($action === 'toggle' && $id) {
        $pdo->prepare('UPDATE banners SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
    } else {
        $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
        if ($title !== '') {
            $type = in_array($_POST['link_type'] ?? '', ['none', 'product', 'category', 'search'], true) ? $_POST['link_type'] : 'none';
            $value = '';
            if ($type === 'product') $value = (string) (int) ($_POST['link_product'] ?? 0);
            if ($type === 'category') $value = (string) (int) ($_POST['link_category'] ?? 0);
            if ($type === 'search') $value = substr(trim((string) ($_POST['link_search'] ?? '')), 0, 200);
            $color = array_key_exists($_POST['color'] ?? '', $colors) ? $_POST['color'] : 'blush';
            $sub = substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 160);
            $label = substr(trim((string) ($_POST['button_label'] ?? '')), 0, 40) ?: 'Shop now';
            $order = (int) ($_POST['sort_order'] ?? 0);
            $active = isset($_POST['is_active']) ? 1 : 0;
            $image = banner_upload();
            if ($id) {
                $pdo->prepare('UPDATE banners SET title=?, subtitle=?, button_label=?, link_type=?, link_value=?, color=?, sort_order=?, is_active=?, show_on=? WHERE id=?')
                    ->execute([$title, $sub, $label, $type, $value, $color, $order, $active, display_clean($_POST['show_on'] ?? 'both'), $id]);
                if ($image) $pdo->prepare('UPDATE banners SET image=? WHERE id=?')->execute([$image, $id]);
            } else {
                $pdo->prepare('INSERT INTO banners (title, subtitle, button_label, image, link_type, link_value, color, sort_order, is_active, show_on) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$title, $sub, $label, $image ?: '', $type, $value, $color, $order, $active, display_clean($_POST['show_on'] ?? 'both')]);
            }
        }
    }
    header('Location: banners.php?saved=1');
    exit;
}

$edit = null;
if (!empty($_GET['edit'])) {
    $e = $pdo->prepare('SELECT * FROM banners WHERE id = ?');
    $e->execute([(int) $_GET['edit']]);
    $edit = $e->fetch() ?: null;
}
$banners = $pdo->query('SELECT * FROM banners ORDER BY sort_order, id')->fetchAll();
$products = $pdo->query("SELECT id, name FROM products WHERE status='approved' ORDER BY name LIMIT 500")->fetchAll();
$cats = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$v = fn(string $k, $d = '') => $edit[$k] ?? $d;

$page_title = 'Banners';
require 'includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?><div class="notice">Saved. The app and the website show your banners right away.</div><?php endif; ?>

<div class="card form-card" style="margin-bottom:20px">
<h3><?= $edit ? 'Edit banner' : 'Add a banner' ?></h3>
<p class="muted">Banners slide across the top of the app and the website home page. With no banners, the app shows your flash-deal products instead.</p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<input type="hidden" name="id" value="<?= (int) $v('id', 0) ?>">
<div class="row">
  <div class="field"><label>Title</label><input name="title" required maxlength="120" value="<?= admin_h($v('title')) ?>" placeholder="e.g. Weekend sale"></div>
  <div class="field"><label>Subtitle</label><input name="subtitle" maxlength="160" value="<?= admin_h($v('subtitle')) ?>" placeholder="e.g. Up to 40% off"></div>
</div>
<div class="row">
  <div class="field"><label>Button text</label><input name="button_label" maxlength="40" value="<?= admin_h($v('button_label', 'Shop now')) ?>"></div>
  <div class="field"><label>Colour</label><select name="color"><?php foreach ($colors as $k => $name): ?><option value="<?= $k ?>" <?= $v('color', 'blush') === $k ? 'selected' : '' ?>><?= $name ?></option><?php endforeach; ?></select></div>
</div>
<div class="field"><label>Image (JPG, PNG or WebP, up to 5 MB). Leave empty to use the linked product's photo.</label><input type="file" name="image" accept="image/*"><?php if ($v('image')): ?><p class="muted">Current: <?= admin_h($v('image')) ?></p><?php endif; ?></div>
<div class="field"><label>When tapped, open</label>
  <select name="link_type">
    <?php foreach (['none' => 'Nothing', 'product' => 'A product', 'category' => 'A category', 'search' => 'A search'] as $k => $name): ?><option value="<?= $k ?>" <?= $v('link_type', 'none') === $k ? 'selected' : '' ?>><?= $name ?></option><?php endforeach; ?>
  </select></div>
<div class="row">
  <div class="field"><label>Product (if "A product")</label><select name="link_product"><option value="0">-</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= ($v('link_type') === 'product' && (int) $v('link_value') === (int) $p['id']) ? 'selected' : '' ?>><?= admin_h($p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Category (if "A category")</label><select name="link_category"><option value="0">-</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= ($v('link_type') === 'category' && (int) $v('link_value') === (int) $c['id']) ? 'selected' : '' ?>><?= admin_h($c['name']) ?></option><?php endforeach; ?></select></div>
</div>
<div class="row">
  <div class="field"><label>Search words (if "A search")</label><input name="link_search" maxlength="200" value="<?= $v('link_type') === 'search' ? admin_h($v('link_value')) : '' ?>"></div>
  <div class="field"><label>Order (smallest first)</label><input type="number" name="sort_order" value="<?= (int) $v('sort_order', 0) ?>"></div>
</div>
<div class="field"><label>Show this banner on</label><select name="show_on"><?php foreach (display_values() as $k => $name): ?><option value="<?= $k ?>" <?= display_clean($v('show_on', 'both')) === $k ? 'selected' : '' ?>><?= $name ?></option><?php endforeach; ?></select></div>
<div class="field"><label><input type="checkbox" name="is_active" value="1" <?= ($edit ? !empty($edit['is_active']) : true) ? 'checked' : '' ?>> Show this banner</label></div>
<button class="btn"><?= $edit ? 'Save banner' : 'Add banner' ?></button>
<?php if ($edit): ?><a class="btn gray" href="banners.php" style="margin-left:8px">Cancel</a><?php endif; ?>
</form>
</div>

<div class="table-wrap">
<table class="table">
<tr><th>Order</th><th>Banner</th><th>Opens</th><th>Shows on</th><th>Status</th><th></th></tr>
<?php foreach ($banners as $b): ?>
<tr>
  <td><?= (int) $b['sort_order'] ?></td>
  <td><b><?= admin_h($b['title']) ?></b><br><span class="muted"><?= admin_h($b['subtitle']) ?></span></td>
  <td><?= admin_h($b['link_type']) ?></td>
  <td><?= admin_h(display_label($b['show_on'] ?? 'both')) ?></td>
  <td><?= $b['is_active'] ? 'Showing' : 'Hidden' ?></td>
  <td style="white-space:nowrap">
    <a class="btn gray" href="banners.php?edit=<?= (int) $b['id'] ?>">Edit</a>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn gray" name="action" value="toggle"><?= $b['is_active'] ? 'Hide' : 'Show' ?></button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this banner?')"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn red" name="action" value="delete">Delete</button></form>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$banners): ?><tr><td colspan="5" class="muted">No banners yet.</td></tr><?php endif; ?>
</table>
</div>

<?php require 'includes/footer.php'; ?>
