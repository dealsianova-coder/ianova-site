<?php
require_once __DIR__ . '/_layout.php';
$canAct = ($seller['status'] ?? '') === 'approved';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    if (!hash_equals($_SESSION['ops_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['ops_flash'] = 'Session expired. Please try again.';
    } else {
        [$ok, $msg] = ops_approve_order($pdo, $seller, $orderId);
        $_SESSION['ops_flash'] = $msg;
    }
    header('Location: orders.php');
    exit;
}

$filter = $_GET['show'] ?? '';
$q = $pdo->prepare(
    "SELECT o.id, o.created_at, o.status, o.address, o.guest_phone, COALESCE(o.guest_name, u.name, 'Customer') AS customer
     FROM orders o LEFT JOIN users u ON u.id = o.user_id
     WHERE EXISTS (SELECT 1 FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id AND p.seller_id = ?)
     ORDER BY o.id DESC LIMIT 100"
);
$q->execute([$seller_id]);
$orders = $q->fetchAll();
$aq = $pdo->prepare('SELECT order_id FROM seller_order_approvals WHERE seller_id = ?');
$aq->execute([$seller_id]);
$approved = array_flip(array_map('intval', $aq->fetchAll(PDO::FETCH_COLUMN)));
$iq = $pdo->prepare('SELECT oi.quantity, oi.unit_price, oi.product_id, p.name, p.image, p.emoji, p.color, p.size, p.status FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.seller_id = ?');

$waitingN = 0; $doneN = 0; $rows = [];
foreach ($orders as $o) {
    $iq->execute([$o['id'], $seller_id]);
    $o['items'] = $iq->fetchAll();
    $o['done'] = isset($approved[(int) $o['id']]);
    $o['waiting'] = !$o['done'] && $o['status'] !== 'cancelled';
    if ($o['done']) $doneN++; elseif ($o['waiting']) $waitingN++;
    if ($filter === 'waiting' && !$o['waiting']) continue;
    if ($filter === 'approved' && !$o['done']) continue;
    $rows[] = $o;
}
seller_head('Orders', 'orders.php');
?>
<div class="chips">
  <a class="chip<?= $filter === '' ? ' on' : '' ?>" href="orders.php">All <span><?= count($orders) ?></span></a>
  <a class="chip<?= $filter === 'waiting' ? ' on' : '' ?>" href="orders.php?show=waiting">Waiting for you <span><?= $waitingN ?></span></a>
  <a class="chip<?= $filter === 'approved' ? ' on' : '' ?>" href="orders.php?show=approved">Approved <span><?= $doneN ?></span></a>
</div>
<?php if (!$rows): ?><div class="blk"><p class="muted" style="margin:0">No orders here.</p></div><?php endif; ?>
<div class="ogrid">
<?php foreach ($rows as $o):
    $sum = 0; $locked = false;
    foreach ($o['items'] as $it) { $sum += $it['quantity'] * $it['unit_price']; if (ops_product_locked($it)) $locked = true; }
?>
  <div class="ocard<?= $o['waiting'] ? ' is-new' : '' ?>" id="o<?= (int) $o['id'] ?>">
    <div class="oc-head"><a href="order_view.php?id=<?= (int) $o['id'] ?>" style="text-decoration:underline"><b>Order #<?= (int) $o['id'] ?></b></a><?= $o['done'] ? '<span class="spill ok">Approved</span>' : ($o['status'] === 'cancelled' ? '<span class="spill bad">Cancelled</span>' : '<span class="spill warn">Waiting for you</span>') ?></div>
    <div class="oc-info">
      <b><?= ops_h($o['customer']) ?></b>
      <span class="muted"><?= ops_h($o['guest_phone'] ?? '') ?><?= !empty($o['address']) ? ' · ' . ops_h($o['address']) : '' ?></span>
      <span class="muted"><?= ops_h($o['created_at']) ?></span>
    </div>
    <div class="olist">
    <?php foreach ($o['items'] as $it): $opt = trim(($it['color'] ?? '') . ' ' . ($it['size'] ?? '')); ?>
      <a class="orow" href="order_item.php?id=<?= (int) $o['id'] ?>&p=<?= (int) $it['product_id'] ?>&c=<?= urlencode((string) ($it['color'] ?? '')) ?>&s=<?= urlencode((string) ($it['size'] ?? '')) ?>" style="padding:8px 10px;text-decoration:none;color:inherit">
        <?= sel_photo($it['image'] ?? '', $it['name'], 'ph mini', $it['emoji'] ?: '🛍️', true) ?>
        <span class="o-main"><b><?= ops_h($it['name']) ?></b><small><?= (int) $it['quantity'] ?> × <?= CURRENCY . number_format($it['unit_price'], 2) ?><?= $opt !== '' ? ' · ' . ops_h($opt) : '' ?></small></span>
        <span class="o-side"><b><?= CURRENCY . number_format($it['quantity'] * $it['unit_price'], 2) ?></b></span>
      </a>
    <?php endforeach; ?>
    </div>
    <div class="oc-total">Your total <?= CURRENCY . number_format($sum, 2) ?></div>
    <?php if ($o['done']): ?><span class="muted">You approved this order.</span>
    <?php elseif ($o['status'] === 'cancelled'): ?><span class="muted">This order was cancelled.</span>
    <?php elseif ($locked): ?><span class="muted">Has a product restricted by the admin. Wait for the admin to approve it.</span>
    <?php else: ?>
      <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn" style="width:100%" type="submit"<?= $canAct ? '' : ' disabled' ?>>Approve order</button></form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php seller_foot();
