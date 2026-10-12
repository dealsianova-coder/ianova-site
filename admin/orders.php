<?php
require_once 'includes/auth.php';
$statuses = ['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf(); $id = (int) $_POST['id']; $status = $_POST['status'] ?? '';
    if (in_array($status, $statuses, true)) $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status, $id]);
    header('Location: orders.php' . (!empty($_POST['back']) ? '?status=' . urlencode($_POST['back']) : '')); exit;
}
$page_title = 'Orders'; require 'includes/header.php';
$filter = $_GET['status'] ?? '';
$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) c FROM orders GROUP BY status')->fetchAll() as $r) $counts[$r['status']] = (int) $r['c'];
$sql = "SELECT o.*,COALESCE(o.guest_name,u.name,'Guest') customer,COALESCE(o.guest_email,u.email) email FROM orders o LEFT JOIN users u ON u.id=o.user_id";
$params = [];
if (in_array($filter, $statuses, true)) { $sql .= ' WHERE o.status=?'; $params[] = $filter; }
$s = $pdo->prepare($sql . ' ORDER BY (o.status=\'pending\') DESC, o.id DESC'); $s->execute($params); $orders = $s->fetchAll();
$items = [];
if ($orders) {
    $ids = implode(',', array_map('intval', array_column($orders, 'id')));
    foreach ($pdo->query("SELECT oi.order_id,oi.quantity,p.name,p.image,p.emoji FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id IN ($ids)")->fetchAll() as $t) $items[$t['order_id']][] = $t;
}
?>
<div class="chips">
  <a class="chip<?= $filter === '' ? ' on' : '' ?>" href="orders.php">All <span><?= array_sum($counts) ?></span></a>
  <?php foreach ($statuses as $st): ?><a class="chip<?= $filter === $st ? ' on' : '' ?>" href="orders.php?status=<?= $st ?>"><?= ucfirst($st) ?> <span><?= $counts[$st] ?? 0 ?></span></a><?php endforeach; ?>
</div>
<?php if (!$orders): ?><div class="blk"><p class="muted" style="margin:0">No orders here.</p></div><?php endif; ?>
<div class="ogrid">
<?php foreach ($orders as $o): $its = $items[$o['id']] ?? []; ?>
  <div class="ocard<?= $o['status'] === 'pending' ? ' is-new' : '' ?>">
    <a class="oc-head" href="order_view.php?id=<?= (int) $o['id'] ?>">
      <b>Order #<?= (int) $o['id'] ?></b><?= adm_pill((string) $o['status']) ?>
    </a>
    <a class="oc-photos" href="order_view.php?id=<?= (int) $o['id'] ?>">
      <?php foreach (array_slice($its, 0, 4) as $t) echo adm_photo($t['image'], $t['name'] ?? '', 'ph mini', $t['emoji'] ?: '🛍️'); ?>
      <?php if (count($its) > 4): ?><span class="ph mini more">+<?= count($its) - 4 ?></span><?php endif; ?>
    </a>
    <div class="oc-info">
      <b><?= admin_h($o['customer']) ?></b>
      <span class="muted"><?= admin_h($o['guest_phone'] ?? '') ?><?= !empty($o['address']) ? ' · ' . admin_h($o['address']) : '' ?></span>
      <span class="muted"><?= admin_h($o['created_at']) ?> · <?= count($its) ?> item<?= count($its) === 1 ? '' : 's' ?></span>
    </div>
    <div class="oc-total"><?= CURRENCY . number_format($o['total'], 2) ?></div>
    <form method="post" class="oc-form">
      <input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="back" value="<?= admin_h($filter) ?>">
      <select name="status"><?php foreach ($statuses as $st): ?><option <?= $o['status'] === $st ? 'selected' : '' ?> value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?></select>
      <button class="btn">Update</button><a class="btn gray" href="order_view.php?id=<?= (int) $o['id'] ?>">Open</a>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php require 'includes/footer.php';
