<?php $page_title = 'Dashboard'; require 'includes/header.php';
$one = function (string $sql) use ($pdo) { try { return (float) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
$tiles = [
  ['📦', 'Products', (int) $one('SELECT COUNT(*) FROM products'), 'products.php'],
  ['🏷️', 'Categories', (int) $one('SELECT COUNT(*) FROM categories'), 'categories.php'],
  ['🛒', 'Orders', (int) $one('SELECT COUNT(*) FROM orders'), 'orders.php'],
  ['👥', 'Users', (int) $one('SELECT COUNT(*) FROM users'), 'users.php'],
  ['🏪', 'Sellers', (int) $one('SELECT COUNT(*) FROM sellers'), 'sellers.php'],
  ['💰', 'Confirmed revenue', CURRENCY . number_format($one("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','shipped','delivered')"), 2), 'orders.php'],
];
$attn = [
  ['🛒', 'New orders waiting', $__n['orders'], 'orders.php?status=pending'],
  ['📦', 'Products to approve', $__n['products'], 'products.php?status=pending'],
  ['🏪', 'Sellers to approve', $__n['sellers'], 'sellers.php'],
  ['💬', 'Unread seller messages', $__n['messages'], 'sellers.php'],
  ['🔔', 'Unread alerts', $__n['alerts'], 'notifications.php'],
  ['💡', 'New suggestions', $__n['suggestions'], 'suggestions.php'],
];
$attn_total = 0; foreach ($attn as $a) $attn_total += $a[2];
$recent = $pdo->query("SELECT o.id,COALESCE(o.guest_name,u.name,'Guest') customer,o.total,o.status,o.created_at FROM orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 8")->fetchAll();
$thumbs = [];
if ($recent) {
  $ids = implode(',', array_map('intval', array_column($recent, 'id')));
  foreach ($pdo->query("SELECT oi.order_id,p.image,p.emoji FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id IN ($ids)")->fetchAll() as $t) $thumbs[$t['order_id']][] = $t;
}
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
    <a class="tile" href="<?= $t[3] ?>"><span class="t-icon"><?= $t[0] ?></span><span class="t-label"><?= admin_h($t[1]) ?></span><span class="t-val"><?= is_int($t[2]) ? number_format($t[2]) : $t[2] ?></span></a>
  <?php endforeach; ?>
</div>

<div class="blk">
  <div class="blk-head"><h2>Recent orders</h2><a class="btn" href="orders.php">Manage orders</a></div>
  <?php if (!$recent): ?><p class="muted">No orders yet.</p><?php endif; ?>
  <div class="olist">
  <?php foreach ($recent as $o): ?>
    <a class="orow" href="order_view.php?id=<?= (int) $o['id'] ?>">
      <span class="o-thumbs"><?php foreach (array_slice($thumbs[$o['id']] ?? [], 0, 3) as $t) echo adm_photo($t['image'], '', 'ph mini', $t['emoji'] ?: '🛍️'); ?></span>
      <span class="o-main"><b>#<?= (int) $o['id'] ?> · <?= admin_h($o['customer']) ?></b><small><?= admin_h($o['created_at']) ?></small></span>
      <span class="o-side"><b><?= CURRENCY . number_format($o['total'], 2) ?></b><?= adm_pill((string) $o['status']) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php require 'includes/footer.php';
