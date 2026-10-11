<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/seller_ops.php';

if (empty($_SESSION['seller_id'])) {
    header('Location: login.php');
    exit;
}

ops_ensure($pdo);
if (empty($_SESSION['ops_csrf'])) $_SESSION['ops_csrf'] = bin2hex(random_bytes(16));

$sellerId = (int) $_SESSION['seller_id'];
$sq = $pdo->prepare('SELECT * FROM sellers WHERE id = ?');
$sq->execute([$sellerId]);
$seller = $sq->fetch();
$canAct = ($seller['status'] ?? '') === 'approved';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $msg = '';
    if (!hash_equals($_SESSION['ops_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $msg = 'Session expired. Please try again.';
    } elseif (!$canAct) {
        $msg = 'Your seller account is restricted. You cannot approve orders until the admin approves your account.';
    } else {
        $iq = $pdo->prepare('SELECT p.id, p.status FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.seller_id = ?');
        $iq->execute([$orderId, $sellerId]);
        $mine = $iq->fetchAll();
        $oq = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
        $oq->execute([$orderId]);
        $ostatus = (string) $oq->fetchColumn();
        $lockedItem = false;
        foreach ($mine as $m) { if (ops_product_locked($m)) $lockedItem = true; }
        if (!$mine) {
            $msg = 'That order has none of your products.';
        } elseif ($ostatus === 'cancelled') {
            $msg = 'This order was cancelled.';
        } elseif ($lockedItem) {
            $msg = 'This order has a product restricted by the admin. Wait for the admin to approve it first.';
        } else {
            $ins = $pdo->prepare('INSERT IGNORE INTO seller_order_approvals (order_id, seller_id) VALUES (?, ?)');
            $ins->execute([$orderId, $sellerId]);
            if ($ins->rowCount() > 0) {
                ops_notify_admin($pdo, 'order_approved', ($seller['business_name'] ?? 'A seller') . ' approved order #' . $orderId,
                    'The seller confirmed they can fulfil their items in this order.', 'order_view.php?id=' . $orderId, $sellerId, null);
            }
            $msg = 'Order #' . $orderId . ' approved.';
        }
    }
    $_SESSION['ops_flash'] = $msg;
    header('Location: orders.php');
    exit;
}

$flash = $_SESSION['ops_flash'] ?? '';
unset($_SESSION['ops_flash']);

$q = $pdo->prepare(
    "SELECT o.id, o.created_at, o.status, o.address, o.guest_phone, COALESCE(o.guest_name, u.name, 'Customer') AS customer
     FROM orders o LEFT JOIN users u ON u.id = o.user_id
     WHERE EXISTS (SELECT 1 FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id AND p.seller_id = ?)
     ORDER BY o.id DESC LIMIT 100"
);
$q->execute([$sellerId]);
$orders = $q->fetchAll();

$aq = $pdo->prepare('SELECT order_id FROM seller_order_approvals WHERE seller_id = ?');
$aq->execute([$sellerId]);
$approved = array_flip(array_map('intval', $aq->fetchAll(PDO::FETCH_COLUMN)));

$iq = $pdo->prepare('SELECT oi.quantity, oi.unit_price, p.name, p.color, p.size, p.status FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.seller_id = ?');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Orders — IANOVA Seller</title><style>
*{box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;background:#FAFAFB;margin:0;color:#0F0F12}
.top{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#fff;border-bottom:1px solid #E9EBEF;flex-wrap:wrap;gap:10px}
.top h1{margin:0;font-size:20px}
.wrap{max-width:900px;margin:20px auto;padding:0 14px}
.btn{display:inline-block;border:0;border-radius:14px;padding:10px 14px;background:#0F0F12;color:#fff;cursor:pointer;font-weight:700;text-decoration:none;font-size:14px}
.btn[disabled]{opacity:.45;cursor:not-allowed}
.card{background:#fff;border:1px solid #E9EBEF;border-radius:16px;padding:16px;margin-bottom:14px}
.row{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center}
.muted{color:#6b7973;font-size:13px}
.pill{display:inline-block;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:800;background:#EEF0F3;text-transform:capitalize}
.ok{background:#E3F6E8;color:#17803d}.warn{background:#FFF1D6;color:#a05a00}
table{width:100%;border-collapse:collapse;margin:10px 0}
td,th{padding:8px 6px;border-bottom:1px solid #eef0f2;text-align:left;font-size:14px}
.notice{padding:12px 14px;border-radius:12px;background:#EEF4FF;margin-bottom:14px}
</style></head><body>
<div class="top"><div><h1>Orders</h1><div class="muted"><?= ops_h($seller['business_name'] ?? '') ?></div></div>
<div><a class="btn" href="index.php">My products</a> <a class="btn" href="logout.php">Sign out</a></div></div>
<div class="wrap">
<?php if ($flash !== ''): ?><div class="notice"><?= ops_h($flash) ?></div><?php endif; ?>
<?php if (!$canAct): ?><div class="notice" style="background:#FFEDEE">Your seller account is restricted by the admin, so you cannot approve orders until it is approved again.</div><?php endif; ?>
<?php if (!$orders): ?><p class="muted">No orders for your products yet.</p><?php endif; ?>
<?php foreach ($orders as $o):
    $iq->execute([$o['id'], $sellerId]);
    $items = $iq->fetchAll();
    $sum = 0; $locked = false;
    foreach ($items as $it) { $sum += $it['quantity'] * $it['unit_price']; if (ops_product_locked($it)) $locked = true; }
    $done = isset($approved[(int) $o['id']]);
?>
<div class="card">
  <div class="row"><b>Order #<?= (int) $o['id'] ?></b>
    <span><span class="pill"><?= ops_h($o['status']) ?></span> <span class="pill <?= $done ? 'ok' : 'warn' ?>"><?= $done ? 'You approved' : 'Waiting for you' ?></span></span></div>
  <div class="muted"><?= ops_h($o['created_at']) ?></div>
  <p style="margin:10px 0 0"><b><?= ops_h($o['customer']) ?></b><br><span class="muted"><?= ops_h($o['guest_phone'] ?? '') ?> · <?= ops_h($o['address'] ?? '') ?></span></p>
  <table><tr><th>Your items</th><th>Qty</th><th>Price</th></tr>
  <?php foreach ($items as $it): ?>
    <tr><td><?= ops_h($it['name']) ?><?php $opt = trim(($it['color'] ?? '') . ' ' . ($it['size'] ?? '')); if ($opt !== '') echo ' <span class="muted">(' . ops_h($opt) . ')</span>'; ?></td>
    <td><?= (int) $it['quantity'] ?></td><td><?= CURRENCY . number_format($it['unit_price'] * $it['quantity'], 2) ?></td></tr>
  <?php endforeach; ?></table>
  <div class="row"><b>Your total: <?= CURRENCY . number_format($sum, 2) ?></b>
  <?php if ($done): ?>
    <span class="muted">Approved</span>
  <?php elseif ($o['status'] === 'cancelled'): ?>
    <span class="muted">Order cancelled</span>
  <?php elseif ($locked): ?>
    <span class="muted">Has a product restricted by the admin</span>
  <?php else: ?>
    <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn" type="submit"<?= $canAct ? '' : ' disabled' ?>>Approve order</button></form>
  <?php endif; ?></div>
</div>
<?php endforeach; ?>
</div></body></html>
