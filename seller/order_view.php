<?php
require_once __DIR__ . '/_layout.php';
$canAct = ($seller['status'] ?? '') === 'approved';
$id = (int) ($_GET['id'] ?? $_POST['order_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['ops_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['ops_flash'] = 'Session expired. Please try again.';
    } else {
        [$ok, $msg] = ops_approve_order($pdo, $seller, $id);
        $_SESSION['ops_flash'] = $msg;
    }
    header('Location: order_view.php?id=' . $id);
    exit;
}

$q = $pdo->prepare("SELECT o.id, o.created_at, o.status, o.address, o.guest_phone, COALESCE(o.guest_name, u.name, 'Customer') AS customer
    FROM orders o LEFT JOIN users u ON u.id = o.user_id
    WHERE o.id = ? AND EXISTS (SELECT 1 FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id AND p.seller_id = ?)");
$q->execute([$id, $seller_id]);
$o = $q->fetch();
if (!$o) { header('Location: orders.php'); exit; }

$iq = $pdo->prepare('SELECT oi.product_id, oi.quantity, oi.unit_price, p.name, p.image, p.emoji, p.color, p.size, p.status FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.seller_id = ?');
$iq->execute([$id, $seller_id]);
$items = $iq->fetchAll();
$aq = $pdo->prepare('SELECT approved_at FROM seller_order_approvals WHERE order_id = ? AND seller_id = ?');
$aq->execute([$id, $seller_id]);
$done = $aq->fetchColumn();
$sum = 0.0; $locked = false;
foreach ($items as $it) { $sum += $it['quantity'] * $it['unit_price']; if (ops_product_locked($it)) $locked = true; }
seller_head('Order #' . $id, 'orders.php');
?>
<div class="actions" style="margin-bottom:14px"><a class="btn gray" href="orders.php">← All orders</a></div>
<div class="blk" style="max-width:760px">
  <div class="oc-head"><b style="font-size:20px">Order #<?= (int) $id ?></b><?= $done ? '<span class="spill ok">Approved</span>' : ($o['status'] === 'cancelled' ? '<span class="spill bad">Cancelled</span>' : '<span class="spill warn">Waiting for you</span>') ?></div>
  <div class="oc-info" style="margin:10px 0 14px">
    <b><?= ops_h($o['customer']) ?></b>
    <span class="muted"><?= ops_h($o['guest_phone'] ?? '') ?><?= !empty($o['address']) ? ' · ' . ops_h($o['address']) : '' ?></span>
    <span class="muted"><?= ops_h($o['created_at']) ?></span>
  </div>
  <div class="olist">
  <?php foreach ($items as $it): $opt = trim(($it['color'] ?? '') . ' ' . ($it['size'] ?? '')); ?>
    <a class="orow" href="product_view.php?id=<?= (int) $it['product_id'] ?>" style="padding:8px 10px;text-decoration:none;color:inherit">
      <?= sel_photo($it['image'] ?? '', $it['name'], 'ph mini', $it['emoji'] ?: '🛍️') ?>
      <span class="o-main"><b><?= ops_h($it['name']) ?></b><small><?= (int) $it['quantity'] ?> × <?= CURRENCY . number_format($it['unit_price'], 2) ?><?= $opt !== '' ? ' · ' . ops_h($opt) : '' ?></small></span>
      <span class="o-side"><b><?= CURRENCY . number_format($it['quantity'] * $it['unit_price'], 2) ?></b></span>
    </a>
  <?php endforeach; ?>
  </div>
  <div class="oc-total">Your total <?= CURRENCY . number_format($sum, 2) ?></div>
  <p class="muted" style="margin:6px 0 12px">Only your own products are shown. Other sellers' items in this order are handled by them.</p>
  <?php if ($done): ?><span class="muted">You approved this order (<?= ops_h($done) ?>).</span>
  <?php elseif ($o['status'] === 'cancelled'): ?><span class="muted">This order was cancelled.</span>
  <?php elseif ($locked): ?><span class="muted">Has a product restricted by the admin. Wait for the admin to approve it.</span>
  <?php else: ?>
    <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $id ?>"><button class="btn" style="width:100%" type="submit"<?= $canAct ? '' : ' disabled' ?>>Approve order</button></form>
  <?php endif; ?>
</div>
<?php seller_foot();
