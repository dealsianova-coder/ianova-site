<?php require_once 'includes/auth.php';
$page_title = 'Products';
$views = ['blocks' => 'Blocks', 'list' => 'List', 'table' => 'Table'];
$view = $_GET['view'] ?? ($_COOKIE['adm_view'] ?? 'blocks');
if (!isset($views[$view])) $view = 'blocks';
if (isset($_GET['view'])) @setcookie('adm_view', $view, time() + 86400 * 365, '/');
require 'includes/header.php';

$q = trim($_GET['q'] ?? ''); $filter = $_GET['status'] ?? ''; $cat = (int) ($_GET['cat'] ?? 0);
$sql = 'SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id'; $where = []; $params = [];
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.slug LIKE ? OR p.seller_name LIKE ?)'; $like = '%' . $q . '%'; $params = array_merge($params, [$like, $like, $like]); }
if (in_array($filter, ['pending', 'approved', 'rejected', 'hidden'], true)) { $where[] = 'p.status=?'; $params[] = $filter; }
if ($cat) { $where[] = 'p.category_id=?'; $params[] = $cat; }
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY (p.status='pending') DESC, p.id DESC";
$s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll();
$cats = $pdo->query('SELECT id,name FROM categories ORDER BY sort_order,name')->fetchAll();
$qs = function (array $over) { return 'products.php?' . http_build_query(array_filter(array_merge($_GET, $over), function ($v) { return $v !== '' && $v !== null && $v !== 0; })); };

function prod_actions(array $p): string {
    $id = (int) $p['id']; $st = $p['status'] ?? ''; $h = '';
    if ($st === 'pending') {
        $h .= '<a class="btn ok" href="product_approve.php?id=' . $id . '&action=approve" onclick="return confirm(\'Approve this product?\')">Approve</a> ';
        $h .= '<a class="btn red" href="product_approve.php?id=' . $id . '&action=reject" onclick="return confirm(\'Reject this product?\')">Reject</a> ';
    }
    $h .= '<a class="btn" href="product_edit.php?id=' . $id . '">Edit</a> ';
    $h .= $st === 'hidden'
        ? '<a class="btn gray" href="product_unhide.php?id=' . $id . '">Unhide</a> '
        : '<a class="btn gray" href="product_hide.php?id=' . $id . '" onclick="return confirm(\'Hide this product from the storefront?\')">Hide</a> ';
    $h .= '<a class="btn red" href="product_delete.php?id=' . $id . '" onclick="return confirm(\'Delete this product?\')">Delete</a>';
    return $h;
}
?>
<div class="toolbar">
  <div class="actions"><a class="btn" href="product_edit.php">+ Add product</a><a class="btn gray" href="products_bulk.php">📤 Bulk upload (CSV)</a></div>
  <div class="seg" role="tablist" aria-label="View">
    <?php foreach ($views as $k => $label): ?><a class="<?= $view === $k ? 'on' : '' ?>" href="<?= $qs(['view' => $k]) ?>"><?= $k === 'blocks' ? '▦' : ($k === 'list' ? '☰' : '▤') ?> <?= $label ?></a><?php endforeach; ?>
  </div>
</div>
<form method="get" class="filters">
  <input type="hidden" name="view" value="<?= admin_h($view) ?>">
  <input class="search" name="q" placeholder="Search products..." value="<?= admin_h($q) ?>">
  <select name="cat" onchange="this.form.submit()"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= admin_h($c['name']) ?></option><?php endforeach; ?></select>
  <select name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (['pending', 'approved', 'rejected', 'hidden'] as $st): ?><option value="<?= $st ?>" <?= $filter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select>
  <button class="btn gray">Search</button>
</form>
<p class="muted"><?= count($rows) ?> product<?= count($rows) === 1 ? '' : 's' ?></p>

<?php if ($view === 'blocks'): ?>
<div class="pgrid">
<?php foreach ($rows as $p): $st = $p['status'] ?? 'approved'; ?>
  <div class="pcard">
    <a href="product_edit.php?id=<?= (int) $p['id'] ?>"><?= adm_photo($p['image'] ?? '', $p['name'], 'ph', $p['emoji'] ?: '🛍️') ?></a>
    <div class="pc-body">
      <div class="pc-top"><?= adm_pill((string) $st) ?><?php if (!empty($p['is_flash_deal'])): ?><span class="spill bad">⚡ Flash</span><?php endif; ?></div>
      <b class="pc-name"><?= admin_h($p['name']) ?></b>
      <div class="pc-price"><?= CURRENCY . number_format($p['price'], 2) ?></div>
      <div class="muted"><?= admin_h($p['category']) ?> · Stock <?= (int) $p['stock'] ?><?php if (!empty($p['seller_name'])): ?> · <?= admin_h($p['seller_name']) ?><?php endif; ?></div>
      <div class="pc-actions"><?= prod_actions($p) ?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php elseif ($view === 'list'): ?>
<div class="plist">
<?php foreach ($rows as $p): $st = $p['status'] ?? 'approved'; ?>
  <div class="prow">
    <a href="product_edit.php?id=<?= (int) $p['id'] ?>"><?= adm_photo($p['image'] ?? '', $p['name'], 'ph small', $p['emoji'] ?: '🛍️') ?></a>
    <div class="pr-main"><b><?= admin_h($p['name']) ?></b><span class="muted"><?= admin_h($p['category']) ?> · Stock <?= (int) $p['stock'] ?><?php if (!empty($p['seller_name'])): ?> · <?= admin_h($p['seller_name']) ?><?php endif; ?></span><span><?= adm_pill((string) $st) ?><?php if (!empty($p['is_flash_deal'])): ?> <span class="spill bad">⚡ Flash</span><?php endif; ?></span></div>
    <div class="pr-side"><b><?= CURRENCY . number_format($p['price'], 2) ?></b><div class="pc-actions"><?= prod_actions($p) ?></div></div>
  </div>
<?php endforeach; ?>
</div>

<?php else: ?>
<div class="table-wrap"><table class="table"><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Seller</th><th>Status</th><th>Flash</th><th>Actions</th></tr>
<?php foreach ($rows as $p): $st = $p['status'] ?? 'approved'; ?>
<tr><td><?php if (!empty($p['image'])): ?><img class="thumb" src="<?= admin_h(adm_img($p['image'])) ?>" alt=""><?php else: ?><?= admin_h($p['emoji']) ?><?php endif; ?> <b><?= admin_h($p['name']) ?></b></td><td><?= admin_h($p['category']) ?></td><td><?= CURRENCY . number_format($p['price'], 2) ?></td><td><?= (int) $p['stock'] ?></td><td><?= admin_h($p['seller_name']) ?></td><td><?= adm_pill((string) $st) ?></td><td><?= !empty($p['is_flash_deal']) ? 'Yes' : 'No' ?></td><td><?= prod_actions($p) ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php require 'includes/footer.php';
