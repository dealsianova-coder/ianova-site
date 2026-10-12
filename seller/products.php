<?php
require_once __DIR__ . '/_layout.php';
$views = ['blocks' => 'Blocks', 'list' => 'List', 'table' => 'Table'];
$view = $_GET['view'] ?? ($_COOKIE['sel_view'] ?? 'blocks');
if (!isset($views[$view])) $view = 'blocks';
if (isset($_GET['view'])) @setcookie('sel_view', $view, time() + 86400 * 365, '/');

$q = trim($_GET['q'] ?? ''); $filter = $_GET['status'] ?? '';
$sql = 'SELECT p.*, c.name category FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.seller_id = ?'; $params = [$seller_id];
if ($q !== '') { $sql .= ' AND p.name LIKE ?'; $params[] = '%' . $q . '%'; }
if ($filter === 'locked') $sql .= " AND p.status IN ('hidden','rejected')";
elseif (in_array($filter, ['pending', 'approved', 'rejected', 'hidden'], true)) { $sql .= ' AND p.status = ?'; $params[] = $filter; }
$sql .= ' ORDER BY p.id DESC';
$s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll();
$qs = function (array $over) { return 'products.php?' . http_build_query(array_filter(array_merge($_GET, $over), function ($v) { return $v !== '' && $v !== null; })); };

function sel_prod_actions(array $p): string {
    global $seller;
    $id = (int) $p['id'];
    if (ops_product_locked($p)) {
        return '<div class="lockbox"><b class="lk">🔒 Locked by admin</b><form method="post" action="request_review.php"><input type="hidden" name="csrf" value="' . ops_h($_SESSION['ops_csrf']) . '"><input type="hidden" name="product_id" value="' . $id . '"><input class="search" name="note" maxlength="800" placeholder="What did you correct?"><button class="btn" type="submit">Request review</button></form></div>';
    }
    return '<a class="btn" href="product_edit.php?id=' . $id . '">Edit</a>';
}
seller_head('Products', 'products.php');
?>
<style>.lockbox{width:100%}.lockbox .lk{color:#B00F2F;font-size:13px}.lockbox form{display:flex;flex-direction:column;gap:6px;margin-top:6px}.lockbox .search{width:100%}</style>
<div class="toolbar">
  <div class="actions"><a class="btn" href="product_edit.php">+ Add product</a></div>
  <div class="seg"><?php foreach ($views as $k => $label): ?><a class="<?= $view === $k ? 'on' : '' ?>" href="<?= $qs(['view' => $k]) ?>"><?= $k === 'blocks' ? '▦' : ($k === 'list' ? '☰' : '▤') ?> <?= $label ?></a><?php endforeach; ?></div>
</div>
<form method="get" class="filters">
  <input type="hidden" name="view" value="<?= ops_h($view) ?>">
  <input class="search" name="q" placeholder="Search your products..." value="<?= ops_h($q) ?>">
  <select name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (['approved' => 'Approved', 'pending' => 'Waiting for review', 'locked' => 'Locked by admin'] as $k => $l): ?><option value="<?= $k ?>" <?= $filter === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button class="btn gray">Search</button>
</form>
<p class="muted"><?= count($rows) ?> product<?= count($rows) === 1 ? '' : 's' ?></p>
<?php if (!$rows): ?><div class="blk"><p class="muted" style="margin:0">No products here yet. <a href="product_edit.php" style="text-decoration:underline">Add your first product</a>.</p></div><?php endif; ?>

<?php if ($view === 'blocks'): ?>
<div class="pgrid">
<?php foreach ($rows as $p): $st = $p['status'] ?? 'approved'; ?>
  <div class="pcard">
    <?= sel_photo($p['image'] ?? '', $p['name'], 'ph', $p['emoji'] ?: '🛍️', true) ?>
    <div class="pc-body">
      <div class="pc-top"><?= sel_pill((string) $st) ?><?php if (!empty($p['is_flash_deal'])): ?><span class="spill bad">⚡ Flash</span><?php endif; ?><?php if ((int) $p['stock'] <= 3): ?><span class="spill warn">Low stock</span><?php endif; ?></div>
      <b class="pc-name"><?= ops_h($p['name']) ?></b>
      <div class="pc-price"><?= CURRENCY . number_format($p['price'], 2) ?></div>
      <div class="muted"><?= ops_h($p['category'] ?? '') ?> · Stock <?= (int) $p['stock'] ?></div>
      <div class="pc-actions"><?= sel_prod_actions($p) ?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php elseif ($view === 'list'): ?>
<div class="plist">
<?php foreach ($rows as $p): $st = $p['status'] ?? 'approved'; ?>
  <div class="prow">
    <?= sel_photo($p['image'] ?? '', $p['name'], 'ph small', $p['emoji'] ?: '🛍️', true) ?>
    <div class="pr-main"><b><?= ops_h($p['name']) ?></b><span class="muted"><?= ops_h($p['category'] ?? '') ?> · Stock <?= (int) $p['stock'] ?></span><span><?= sel_pill((string) $st) ?></span></div>
    <div class="pr-side"><b><?= CURRENCY . number_format($p['price'], 2) ?></b><div class="pc-actions"><?= sel_prod_actions($p) ?></div></div>
  </div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="table-wrap"><table class="table"><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Flash</th><th>Actions</th></tr>
<?php foreach ($rows as $p): $st = $p['status'] ?? 'approved'; ?>
<tr><td><?php if (!empty($p['image'])): ?><img class="thumb" src="<?= ops_h(sel_img($p['image'])) ?>" alt=""><?php else: ?><?= ops_h($p['emoji'] ?? '') ?><?php endif; ?> <b><?= ops_h($p['name']) ?></b></td><td><?= ops_h($p['category'] ?? '') ?></td><td><?= CURRENCY . number_format($p['price'], 2) ?></td><td><?= (int) $p['stock'] ?></td><td><?= sel_pill((string) $st) ?></td><td><?= !empty($p['is_flash_deal']) ? 'Yes' : 'No' ?></td><td><?= sel_prod_actions($p) ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php seller_foot();
