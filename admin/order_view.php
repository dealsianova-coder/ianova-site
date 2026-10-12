<?php $page_title = 'Order details'; require 'includes/header.php';
$id = (int) ($_GET['id'] ?? 0);
$s = $pdo->prepare("SELECT o.*,COALESCE(o.guest_name,u.name,'Guest') customer,COALESCE(o.guest_email,u.email) email FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=?");
$s->execute([$id]); $o = $s->fetch();
if (!$o) { echo '<div class="notice error">Order not found.</div>'; require 'includes/footer.php'; exit; }
$s = $pdo->prepare('SELECT oi.*,p.name,p.image,p.emoji,p.color,p.size,p.seller_name FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
$s->execute([$id]); $items = $s->fetchAll();
?>
<div class="actions" style="margin-bottom:14px"><a class="btn gray" href="orders.php">← All orders</a><a class="btn gray" href="orders.php?status=<?= admin_h($o['status']) ?>">More <?= admin_h($o['status']) ?> orders</a></div>

<div class="blk">
  <div class="blk-head"><h2>Order #<?= (int) $o['id'] ?></h2><?= adm_pill((string) $o['status']) ?></div>
  <div class="infogrid">
    <div class="ib"><small>Customer</small><b><?= admin_h($o['customer']) ?></b><span class="muted"><?= admin_h($o['email'] ?? '') ?></span></div>
    <div class="ib"><small>Phone</small><b><?= admin_h($o['guest_phone'] ?? '—') ?></b></div>
    <div class="ib"><small>Address</small><b><?= admin_h($o['address'] ?? '—') ?></b></div>
    <div class="ib"><small>Placed</small><b><?= admin_h($o['created_at']) ?></b></div>
  </div>
  <form method="post" action="orders.php" class="oc-form" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
    <select name="status"><?php foreach (['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'] as $st): ?><option <?= $o['status'] === $st ? 'selected' : '' ?> value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?></select>
    <button class="btn">Update status</button>
  </form>
</div>

<div class="blk">
  <div class="blk-head"><h2>Products ordered</h2><span class="muted">Tap a photo to enlarge</span></div>
  <div class="pgrid">
  <?php foreach ($items as $i): $opt = trim(($i['color'] ?? '') . ' ' . ($i['size'] ?? '')); ?>
    <div class="pcard">
      <?= adm_photo($i['image'] ?? '', $i['name'] ?? '', 'ph', $i['emoji'] ?: '🛍️', true) ?>
      <div class="pc-body">
        <b class="pc-name"><?= admin_h($i['name'] ?? 'Deleted product') ?></b>
        <?php if ($opt !== ''): ?><div class="muted"><?= admin_h($opt) ?></div><?php endif; ?>
        <div class="muted">Qty <?= (int) $i['quantity'] ?> × <?= CURRENCY . number_format($i['unit_price'], 2) ?></div>
        <div class="pc-price"><?= CURRENCY . number_format($i['unit_price'] * $i['quantity'], 2) ?></div>
        <?php if (!empty($i['product_id']) && !empty($i['name'])): ?><a class="btn gray" href="product_edit.php?id=<?= (int) $i['product_id'] ?>">Open product</a><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php require_once __DIR__ . '/../includes/seller_ops.php'; ops_order_approvals_html($pdo, (int) $o['id']); ?>
  <div class="total-bar">Total <b><?= CURRENCY . number_format($o['total'], 2) ?></b></div>
</div>
<?php require 'includes/footer.php';
