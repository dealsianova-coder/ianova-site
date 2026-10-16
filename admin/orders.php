<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';
$statuses = ['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'];
$tab = ($_GET['tab'] ?? 'main') === 'sellers' ? 'sellers' : 'main';
$sellerId = $tab === 'sellers' ? (int) ($_GET['seller'] ?? 0) : 0;
$filter = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$q = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id > 0 && in_array($status, $statuses, true)) {
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    }
    header('Location: orders.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

[$scopeSql, $scopePar] = aou_scope_sql($tab, $sellerId);
$cnt = $pdo->prepare('SELECT o.status, COUNT(*) c FROM orders o WHERE 1=1' . $scopeSql . ' GROUP BY o.status');
$cnt->execute($scopePar);
$counts = [];
foreach ($cnt->fetchAll() as $r) $counts[$r['status']] = (int) $r['c'];

$sql = "SELECT o.*, COALESCE(o.guest_name, u.name, 'Guest') customer, COALESCE(o.guest_email, u.email) email
        FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE 1=1" . $scopeSql;
$par = $scopePar;
if ($filter !== '') { $sql .= ' AND o.status = ?'; $par[] = $filter; }
if ($q !== '') {
    $sql .= " AND (o.id = ? OR COALESCE(o.guest_name, u.name, '') LIKE ? OR COALESCE(o.guest_phone, '') LIKE ? OR COALESCE(o.guest_email, u.email, '') LIKE ?)";
    $like = '%' . $q . '%';
    array_push($par, (int) ltrim($q, '#'), $like, $like, $like);
}
$sql .= ' ORDER BY o.id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($par);
$orders = $st->fetchAll();
$ids = array_column($orders, 'id');
$items = aou_items($pdo, $ids);
$appr = aou_approvals($pdo, $ids);
$sellers = [];
try { $sellers = $pdo->query("SELECT id, business_name FROM sellers ORDER BY business_name")->fetchAll(); } catch (Throwable $e) {}
$nMain = aou_scope_count($pdo, 'main');
$nSellers = aou_scope_count($pdo, 'sellers');
$qs = function (array $o) { return 'orders.php?' . http_build_query(array_filter(array_merge($_GET, $o), function ($v) { return $v !== '' && $v !== null; })); };

$page_title = 'Orders';
require 'includes/header.php';
echo aou_css();
?>
<div class="chips" style="margin-bottom:10px">
  <a class="<?= $tab === 'main' ? 'on' : '' ?>" href="orders.php?tab=main">🏠 IANOVA orders<b><?= $nMain ?></b></a>
  <a class="<?= $tab === 'sellers' ? 'on' : '' ?>" href="orders.php?tab=sellers">🏪 Seller orders<b><?= $nSellers ?></b></a>
</div>
<p class="muted" style="margin:0 0 12px"><?= $tab === 'main' ? "Orders with products sold directly by IANOVA. You manage these yourself." : "Orders with products sold by sellers. Each seller approves their own items; you see their status here." ?></p>
<?php if ($tab === 'sellers'): $sc = aou_seller_counts($pdo); ?>
<div class="chips"><a class="<?= $sellerId === 0 ? 'on' : '' ?>" href="<?= $qs(['seller' => '']) ?>">All sellers<b><?= count($sc) ?></b></a>
<?php foreach ($sc as $sid => $s0): ?><a class="<?= $sellerId === $sid ? 'on' : '' ?>" href="<?= $qs(['seller' => $sid]) ?>">🏪 <?= admin_h($s0['name']) ?><b><?= $s0['orders'] ?></b><?php if ($s0['waiting']): ?> <span class="tag wait" style="margin:0 0 0 6px"><?= $s0['waiting'] ?> waiting</span><?php endif; ?></a><?php endforeach; ?></div>
<?php endif; ?>
<div class="chips">
  <a class="<?= $filter === '' ? 'on' : '' ?>" href="<?= $qs(['status' => '']) ?>">All<b><?= array_sum($counts) ?></b></a>
  <?php foreach ($statuses as $s): ?><a class="<?= $filter === $s ? 'on' : '' ?>" href="<?= $qs(['status' => $s]) ?>"><?= ucfirst($s) ?><b><?= (int) ($counts[$s] ?? 0) ?></b></a><?php endforeach; ?>
</div>
<form method="get" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">
  <input type="hidden" name="tab" value="<?= $tab ?>">
  <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= admin_h($filter) ?>"><?php endif; ?>
  <?php if ($tab === 'sellers' && $sellerId > 0): ?><input type="hidden" name="seller" value="<?= $sellerId ?>"><?php endif; ?>
  <input class="search" name="q" placeholder="Search order #, customer, phone, email..." value="<?= admin_h($q) ?>"><button class="btn" type="submit">Search</button>
</form>
<?php if ($tab === 'sellers'): $groups = aou_group_by_seller($orders, $items); ?>
<?php if (!$groups): ?><div class="card muted">No seller orders in this section.</div><?php endif; ?>
<?php foreach ($groups as $sid => $g): $ap0 = 0; foreach ($g['rows'] as $rw) { $a = $appr[(int) $rw[0]['id']][$sid] ?? null; if ($a && !$a['at']) $ap0++; } ?>
<div class="card" style="margin-bottom:18px;border-left:5px solid #b58117">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
    <h3 style="margin:0">🏪 <?= admin_h($g['name']) ?> <span class="muted">(<?= count($g['rows']) ?> order<?= count($g['rows']) === 1 ? '' : 's' ?>)</span></h3>
    <span><?php if ($ap0): ?><span class="tag wait"><?= $ap0 ?> waiting for this seller</span><?php else: ?><span class="tag ok">All approved</span><?php endif; ?> <a class="btn gray" href="<?= $qs(['seller' => $sid]) ?>">Only this seller</a></span>
  </div>
  <div class="table-wrap" style="border:0"><table class="table otbl" style="min-width:640px">
  <tr><th>Order</th><th>Products</th><th>Customer</th><th>Their items</th><th>Approval</th><th>Status</th><th></th></tr>
  <?php foreach ($g['rows'] as [$o, $lines]): ?>
  <tr>
    <td><a href="order_view.php?id=<?= (int) $o['id'] ?>&tab=sellers"><b>#<?= (int) $o['id'] ?></b></a><br><span class="muted"><?= admin_h($o['created_at']) ?></span></td>
    <td><div class="ol">
      <?php foreach (array_slice($lines, 0, 3) as $l): $opt = trim($l['color'] . ' ' . $l['size']); ?>
        <a href="product_view.php?id=<?= (int) $l['product_id'] ?>"><?= aou_thumb($l['image'] ?? '', $l['emoji'] ?? '', $l['bg_color'] ?? '', 44) ?>
          <span><b><?= admin_h($l['name'] ?? 'Deleted product') ?></b><small><?= (int) $l['quantity'] ?> × <?= CURRENCY . number_format((float) $l['unit_price'], 2) ?><?= $opt !== '' ? ' · ' . admin_h($opt) : '' ?></small></span></a>
      <?php endforeach; ?>
      <?php if (count($lines) > 3): ?><a class="more" href="order_view.php?id=<?= (int) $o['id'] ?>&tab=sellers">+<?= count($lines) - 3 ?> more items</a><?php endif; ?>
    </div></td>
    <td><b><?= admin_h($o['customer']) ?></b><br><span class="muted"><?= admin_h($o['guest_phone'] ?? '') ?><?= !empty($o['address']) ? '<br>' . admin_h($o['address']) : '' ?></span></td>
    <td><b><?= CURRENCY . number_format(aou_lines_total($lines), 2) ?></b></td>
    <td><?= aou_approval_tags(isset($appr[(int) $o['id']][$sid]) ? [$sid => $appr[(int) $o['id']][$sid]] : []) ?></td>
    <td><form method="post" class="stbar"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
      <select name="status"><?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><button class="btn">Save</button></form></td>
    <td><a class="btn gray" href="order_view.php?id=<?= (int) $o['id'] ?>&tab=sellers">Open</a></td>
  </tr>
  <?php endforeach; ?>
  </table></div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="table-wrap"><table class="table otbl">
<tr><th>Order</th><th>Products</th><th>Customer</th><th><?= $tab === 'main' ? 'IANOVA items' : 'Seller items' ?></th><?php if ($tab === 'sellers'): ?><th>Seller approval</th><?php endif; ?><th>Status</th><th></th></tr>
<?php if (!$orders): ?><tr><td colspan="7" class="muted" style="padding:24px">No orders in this section.</td></tr><?php endif; ?>
<?php foreach ($orders as $o): $lines = aou_scope_lines($items[(int) $o['id']] ?? [], $tab, $sellerId); ?>
<tr>
  <td><a href="order_view.php?id=<?= (int) $o['id'] ?>&tab=<?= $tab ?>"><b>#<?= (int) $o['id'] ?></b></a><br><span class="muted"><?= admin_h($o['created_at']) ?></span></td>
  <td><div class="ol">
    <?php foreach (array_slice($lines, 0, 3) as $l): $opt = trim($l['color'] . ' ' . $l['size']); ?>
      <a href="<?= !empty($l['product_id']) ? 'product_view.php?id=' . (int) $l['product_id'] : 'order_view.php?id=' . (int) $o['id'] ?>"><?= aou_thumb($l['image'] ?? '', $l['emoji'] ?? '', $l['bg_color'] ?? '', 44) ?>
        <span><b><?= admin_h($l['name'] ?? 'Deleted product') ?></b><small><?= (int) $l['quantity'] ?> × <?= CURRENCY . number_format((float) $l['unit_price'], 2) ?><?= $opt !== '' ? ' · ' . admin_h($opt) : '' ?><?= $tab === 'sellers' ? ' · ' . admin_h($l['seller_name']) : '' ?></small></span></a>
    <?php endforeach; ?>
    <?php if (count($lines) > 3): ?><a class="more" href="order_view.php?id=<?= (int) $o['id'] ?>&tab=<?= $tab ?>">+<?= count($lines) - 3 ?> more items</a><?php endif; ?>
  </div></td>
  <td><b><?= admin_h($o['customer']) ?></b><br><span class="muted"><?= admin_h($o['guest_phone'] ?? '') ?><?= !empty($o['address']) ? '<br>' . admin_h($o['address']) : '' ?></span></td>
  <td><b><?= CURRENCY . number_format(aou_lines_total($lines), 2) ?></b><br><span class="muted">Order total <?= CURRENCY . number_format((float) $o['total'], 2) ?></span></td>
  <?php if ($tab === 'sellers'): ?><td><?php $ap = $appr[(int) $o['id']] ?? []; if ($sellerId > 0) $ap = array_intersect_key($ap, [$sellerId => 1]); echo aou_approval_tags($ap); ?></td><?php endif; ?>
  <td><form method="post" class="stbar"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
    <select name="status"><?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><button class="btn">Save</button></form></td>
  <td><a class="btn gray" href="order_view.php?id=<?= (int) $o['id'] ?>&tab=<?= $tab ?>">Open</a></td>
</tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php require 'includes/footer.php';
