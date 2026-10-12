<?php
require_once 'includes/auth.php';
require_once 'includes/ui.php';
adm_ensure_category_image($pdo);

function cat_save_image(PDO $pdo, int $id): void {
    if (empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) return;
    $f = $_FILES['image'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : $f['type'];
    if (!isset($allowed[$mime]) || $f['size'] > 5 * 1024 * 1024) return;
    $dir = __DIR__ . '/../uploads/categories';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fn = 'uploads/categories/' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $fn)) {
        $pdo->prepare('UPDATE categories SET image = ? WHERE id = ?')->execute([$fn, $id]);
    }
}

$edit = (int) ($_GET['id'] ?? 0); $row = null;
if ($edit) { $s = $pdo->prepare('SELECT * FROM categories WHERE id=?'); $s->execute([$edit]); $row = $s->fetch(); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $id = (int) $_POST['id']; $name = trim($_POST['name']); $slug = trim($_POST['slug']);
    $emoji = trim($_POST['emoji'] ?? '🛍️'); $sort = (int) $_POST['sort_order'];
    try {
        if ($id) {
            $pdo->prepare('UPDATE categories SET name=?,slug=?,emoji=?,sort_order=? WHERE id=?')->execute([$name, $slug, $emoji, $sort, $id]);
        } else {
            $pdo->prepare('INSERT INTO categories(name,slug,emoji,sort_order) VALUES(?,?,?,?)')->execute([$name, $slug, $emoji, $sort]);
            $id = (int) $pdo->lastInsertId();
        }
        cat_save_image($pdo, $id);
        if (!empty($_POST['remove_image'])) $pdo->prepare("UPDATE categories SET image='' WHERE id=?")->execute([$id]);
        header('Location: categories.php'); exit;
    } catch (Throwable $e) { $error = 'Could not save category. Slug must be unique.'; }
}
if (isset($_GET['delete'])) { $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([(int) $_GET['delete']]); header('Location: categories.php'); exit; }
if (isset($_GET['toggle_hide'])) { $pdo->prepare('UPDATE categories SET is_hidden = 1 - is_hidden WHERE id=?')->execute([(int) $_GET['toggle_hide']]); header('Location: ' . (isset($_GET['back']) ? 'categories.php?view=' . (int) $_GET['back'] : 'categories.php')); exit; }

$cats = $pdo->query('SELECT c.*,COUNT(p.id) products FROM categories c LEFT JOIN products p ON p.category_id=c.id GROUP BY c.id ORDER BY c.sort_order,c.name')->fetchAll();
$view = (int) ($_GET['view'] ?? 0); $vcat = null; $vprods = [];
if ($view) {
    foreach ($cats as $c) if ((int) $c['id'] === $view) $vcat = $c;
    if ($vcat) { $s = $pdo->prepare('SELECT * FROM products WHERE category_id=? ORDER BY id DESC'); $s->execute([$view]); $vprods = $s->fetchAll(); }
}
$page_title = $vcat ? $vcat['name'] : 'Categories';
require 'includes/header.php';

if ($vcat): ?>
<div class="actions" style="margin-bottom:16px"><a class="btn gray" href="categories.php">← All categories</a><a class="btn" href="product_edit.php">+ Add product</a><a class="btn" href="categories.php?id=<?= (int) $vcat['id'] ?>">Edit category</a><a class="btn" href="categories.php?toggle_hide=<?= (int) $vcat['id'] ?>&back=<?= (int) $vcat['id'] ?>"><?= !empty($vcat['is_hidden']) ? 'Show' : 'Hide' ?></a></div>
<div class="blk"><div class="blk-head"><h2><?= count($vprods) ?> product<?= count($vprods) === 1 ? '' : 's' ?> in <?= admin_h($vcat['name']) ?></h2></div>
<?php if (!$vprods): ?><p class="muted">No products in this category yet.</p><?php endif; ?>
<div class="pgrid">
<?php foreach ($vprods as $p): $st = $p['status'] ?? 'approved'; ?>
  <div class="pcard">
    <a href="product_edit.php?id=<?= (int) $p['id'] ?>"><?= adm_photo($p['image'] ?? '', $p['name'], 'ph', $p['emoji'] ?: '🛍️') ?></a>
    <div class="pc-body">
      <div class="pc-top"><?= adm_pill((string) $st) ?><?php if (!empty($p['is_flash_deal'])): ?><span class="spill bad">⚡ Flash</span><?php endif; ?></div>
      <b class="pc-name"><?= admin_h($p['name']) ?></b>
      <div class="pc-price"><?= CURRENCY . number_format($p['price'], 2) ?></div>
      <div class="muted">Stock <?= (int) $p['stock'] ?></div>
      <a class="btn" href="product_edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
    </div>
  </div>
<?php endforeach; ?>
</div></div>
<?php require 'includes/footer.php'; exit; endif; ?>

<div class="blk">
  <div class="blk-head"><h2>All categories</h2><a class="btn" href="categories.php?id=0#form">+ Add category</a></div>
  <div class="cgrid">
  <?php foreach ($cats as $c): ?>
    <a class="ccard<?= !empty($c['is_hidden']) ? ' is-hidden' : '' ?>" href="categories.php?view=<?= (int) $c['id'] ?>">
      <?= adm_photo($c['image'] ?? '', $c['name'], 'ph', $c['emoji'] ?: '🛍️') ?>
      <span class="cc-name"><?= admin_h($c['name']) ?></span>
      <span class="cc-meta"><?= (int) $c['products'] ?> product<?= (int) $c['products'] === 1 ? '' : 's' ?> · <?= !empty($c['is_hidden']) ? 'Hidden' : 'Visible' ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</div>

<div class="blk" id="form">
  <div class="blk-head"><h2><?= $row ? 'Edit' : 'Add' ?> category</h2><?php if ($row): ?><a class="btn gray" href="categories.php">Cancel</a><?php endif; ?></div>
  <?php if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="cform">
    <input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= intval($row['id'] ?? 0) ?>">
    <?php if ($row && !empty($row['image'])): ?><div class="field"><label>Current image</label><?= adm_photo($row['image'], $row['name'], 'ph small', '🛍️', true) ?><label style="margin-top:8px;font-weight:600"><input type="checkbox" name="remove_image" value="1" style="width:auto"> Remove image</label></div><?php endif; ?>
    <div class="field"><label>Category image</label><input type="file" name="image" accept="image/*"></div>
    <div class="row"><div class="field"><label>Name</label><input name="name" required value="<?= admin_h($row['name'] ?? '') ?>"></div><div class="field"><label>Slug</label><input name="slug" required value="<?= admin_h($row['slug'] ?? '') ?>"></div></div>
    <div class="row"><div class="field"><label>Emoji (shown only when there is no image)</label><input name="emoji" value="<?= admin_h($row['emoji'] ?? '🛍️') ?>"></div><div class="field"><label>Sort order</label><input type="number" name="sort_order" value="<?= intval($row['sort_order'] ?? 0) ?>"></div></div>
    <button class="btn">Save category</button>
  </form>
  <?php if ($row): ?><p style="margin-top:14px"><a class="btn red" onclick="return confirm('Delete category and its products?')" href="categories.php?delete=<?= (int) $row['id'] ?>">Delete this category</a></p><?php endif; ?>
</div>
<?php require 'includes/footer.php';
