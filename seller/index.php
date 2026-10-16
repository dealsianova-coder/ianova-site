<?php
require_once __DIR__ . '/_layout.php';
$one = function (string $sql, array $p = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($p); return (float) $q->fetchColumn(); } catch (Throwable $e) { return 0; } };
$n = sel_counts($pdo, $seller_id);
$rev = $one("SELECT COALESCE(SUM(oi.quantity*oi.unit_price),0) FROM order_items oi JOIN products p ON p.id=oi.product_id JOIN orders o ON o.id=oi.order_id WHERE p.seller_id=? AND o.status IN ('paid','shipped','delivered')", [$seller_id]);
$tiles = [
  ['📦', 'Products', (int) $one('SELECT COUNT(*) FROM products WHERE seller_id=?', [$seller_id]), 'products.php'],
  ['🛒', 'Orders', (int) $one('SELECT COUNT(DISTINCT oi.order_id) FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE p.seller_id=?', [$seller_id]), 'orders.php'],
  ['⚠️', 'Low stock (3 or less)', (int) $one("SELECT COUNT(*) FROM products WHERE seller_id=? AND stock<=3 AND status='approved'", [$seller_id]), 'products.php'],
  ['💰', 'Confirmed sales', CURRENCY . number_format($rev, 2), 'orders.php'],
];
$attn = [
  ['🛒', 'Orders waiting for you', $n['orders'], 'orders.php'],
  ['💬', 'Unread admin messages', $n['messages'], 'messages.php'],
  ['🔒', 'Products locked by admin', $n['locked'], 'products.php?status=locked'],
  ['⏳', 'Products waiting for review', $n['pending'], 'products.php?status=pending'],
];
$attn_total = 0; foreach ($attn as $a) $attn_total += $a[2];

$rq = $pdo->prepare("SELECT o.id, COALESCE(o.guest_name,u.name,'Customer') customer, o.status, o.created_at,
    (SELECT SUM(oi.quantity*oi.unit_price) FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.seller_id=?) mine
    FROM orders o LEFT JOIN users u ON u.id=o.user_id
    WHERE EXISTS (SELECT 1 FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.seller_id=?)
    ORDER BY o.id DESC LIMIT 6");
$rq->execute([$seller_id, $seller_id]);
$recent = $rq->fetchAll();
$thumbs = [];
if ($recent) {
    $ids = implode(',', array_map('intval', array_column($recent, 'id')));
    $tq = $pdo->prepare("SELECT oi.order_id, p.image, p.emoji FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id IN ($ids) AND p.seller_id=?");
    $tq->execute([$seller_id]);
    foreach ($tq->fetchAll() as $t) $thumbs[$t['order_id']][] = $t;
}
$aq = $pdo->prepare('SELECT order_id FROM seller_order_approvals WHERE seller_id = ?');
$aq->execute([$seller_id]);
$approved = array_flip(array_map('intval', $aq->fetchAll(PDO::FETCH_COLUMN)));

$autoMaster = ops_setting($pdo, 'auto_approve', '1') === '1';
$autoMine = ops_seller_auto($pdo, $seller_id);
$autoOn = $autoMaster && $autoMine;
seller_head('Dashboard', 'index.php');
?>
<div class="blk attn-blk">
  <div class="blk-head"><h2>Needs your attention</h2><?php if ($attn_total > 0): ?><span class="nbadge big"><?= (int) $attn_total ?></span><?php endif; ?></div>
  <?php if ($attn_total === 0): ?>
    <p class="muted" style="margin:0">All caught up. Nothing is waiting for you.</p>
  <?php else: ?>
  <div class="attn-grid">
    <?php foreach ($attn as $a): if ($a[2] < 1) continue; ?>
      <a class="attn-item" href="<?= $a[3] ?>"><span class="ai-icon"><?= $a[0] ?></span><span class="ai-text"><b><?= (int) $a[2] ?></b><small><?= $a[1] ?></small></span><span class="ai-go">›</span></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="tiles">
  <?php foreach ($tiles as $t): ?>
    <a class="tile" href="<?= $t[3] ?>"><span class="t-icon"><?= $t[0] ?></span><span class="t-label"><?= ops_h($t[1]) ?></span><span class="t-val"><?= is_int($t[2]) ? number_format($t[2]) : $t[2] ?></span></a>
  <?php endforeach; ?>
</div>

<div class="blk">
  <div class="blk-head"><h2>Automatic order approval: <span style="color:<?= $autoOn ? '#0B6B37' : '#B00F2F' ?>"><?= $autoOn ? 'ON' : 'OFF' ?></span></h2>
    <form method="post" action="auto_approval.php" style="margin:0"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="on" value="<?= $autoMine ? '0' : '1' ?>"><button class="btn <?= $autoMine ? 'red' : 'ok' ?>" type="submit"><?= $autoMine ? 'Switch to manual' : 'Switch on automatic' ?></button></form></div>
  <p class="muted" style="margin:0"><?php if (!$autoMaster): ?>IANOVA has switched automatic approval off for everyone, so you approve orders by hand in Orders.<?php elseif ($autoMine): ?>When your products are in stock, new orders are approved for you.<?php else: ?>You approve every new order by hand in Orders.<?php endif; ?></p>
</div>

<div class="blk">
  <div class="blk-head"><h2>Recent orders</h2><a class="btn" href="orders.php">All orders</a></div>
  <?php if (!$recent): ?><p class="muted">No orders for your products yet.</p><?php endif; ?>
  <div class="olist">
  <?php foreach ($recent as $o): ?>
    <a class="orow" href="order_view.php?id=<?= (int) $o['id'] ?>">
      <span class="o-thumbs"><?php foreach (array_slice($thumbs[$o['id']] ?? [], 0, 3) as $t) echo sel_photo($t['image'], '', 'ph mini', $t['emoji'] ?: '🛍️'); ?></span>
      <span class="o-main"><b>#<?= (int) $o['id'] ?> · <?= ops_h($o['customer']) ?></b><small><?= ops_h($o['created_at']) ?></small></span>
      <span class="o-side"><b><?= CURRENCY . number_format((float) $o['mine'], 2) ?></b><?= isset($approved[(int) $o['id']]) ? '<span class="spill ok">Approved</span>' : sel_pill($o['status'] === 'cancelled' ? 'cancelled' : 'pending') ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php seller_foot();
