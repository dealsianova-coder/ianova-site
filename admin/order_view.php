<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';
$statuses = ['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'];
$id = (int) ($_GET['id'] ?? 0);
$tab = ($_GET['tab'] ?? '') === 'sellers' ? 'sellers' : 'main';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $status = $_POST['status'] ?? '';
    if ($id > 0 && in_array($status, $statuses, true)) {
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    }
    header('Location: order_view.php?id=' . $id . '&tab=' . $tab);
    exit;
}

$s = $pdo->prepare("SELECT o.*, COALESCE(o.guest_name, u.name, 'Guest') customer, COALESCE(o.guest_email, u.email) email FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
$s->execute([$id]);
$o = $s->fetch();
$page_title = $o ? 'Order #' . (int) $o['id'] : 'Order details';
require 'includes/header.php';
if (!$o) { echo '<div class="notice error">Order not found.</div>'; require 'includes/footer.php'; exit; }
echo aou_css();
$lines = aou_items($pdo, [$id])[$id] ?? [];
$appr = aou_approvals($pdo, [$id])[$id] ?? [];
$sub = aou_lines_total($lines);
$delivery = round((float) $o['total'] - $sub, 2);
// Separate panels: IANOVA's own items first, then one panel per seller.
$groups = ['0' => ['name' => 'IANOVA (main)', 'lines' => []]];
foreach ($lines as $l) {
    $sid = (int) ($l['seller_id'] ?? 0);
    if (!isset($groups[(string) $sid])) $groups[(string) $sid] = ['name' => $l['seller_name'], 'lines' => []];
    $groups[(string) $sid]['lines'][] = $l;
}
if (!$groups['0']['lines']) unset($groups['0']);
?>
<div class="actions" style="margin-bottom:14px"><a class="btn gray" href="orders.php?tab=<?= $tab ?>">← <?= $tab === 'sellers' ? 'Seller orders' : 'IANOVA orders' ?></a></div>
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div>
      <h2 style="margin:0 0 6px">Order #<?= (int) $o['id'] ?> <?= aou_status_tag((string) $o['status']) ?></h2>
      <div class="muted"><?= admin_h($o['created_at']) ?></div>
    </div>
    <form method="post" class="stbar"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
      <select name="status"><?php foreach ($statuses as $st): ?><option value="<?= $st ?>" <?= $o['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select><button class="btn">Update status</button></form>
  </div>
  <div class="row" style="margin-top:14px">
    <div><b>Customer</b><br><?= admin_h($o['customer']) ?><br><span class="muted"><?= admin_h($o['email'] ?? '') ?></span></div>
    <div><b>Delivery</b><br><?= admin_h($o['guest_phone'] ?? '') ?><br><span class="muted"><?= admin_h($o['address'] ?? '') ?></span></div>
  </div>
</div>
<?php foreach ($groups as $sid => $g): $isMain = (string) $sid === '0'; ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid <?= $isMain ? '#1d6b52' : '#b58117' ?>">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
    <h3 style="margin:0"><?= $isMain ? '🏠' : '🏪' ?> <?= admin_h($g['name']) ?></h3>
    <span><?php if ($isMain): ?><span class="tag na">Managed by you</span><?php else: ?><?= aou_approval_tags(isset($appr[(int) $sid]) ? [(int) $sid => $appr[(int) $sid]] : []) ?><?php endif; ?> <b><?= CURRENCY . number_format(aou_lines_total($g['lines']), 2) ?></b></span>
  </div>
  <div class="ol" style="min-width:0">
  <?php foreach ($g['lines'] as $l): $opt = trim($l['color'] . ' ' . $l['size']); $pid = (int) ($l['product_id'] ?? 0); ?>
    <a href="<?= $pid ? 'order_item.php?id=' . (int) $o['id'] . '&p=' . $pid . '&tab=' . $tab . '&c=' . urlencode((string) ($l['color'] ?? '')) . '&s=' . urlencode((string) ($l['size'] ?? '')) : '#' ?>" style="justify-content:space-between;border-bottom:1px solid #e8eeea;padding-bottom:8px">
      <span style="display:flex;align-items:center;gap:10px"><?= aou_thumb($l['image'] ?? '', $l['emoji'] ?? '', $l['bg_color'] ?? '', 56) ?>
        <span><b><?= admin_h($l['name'] ?? 'Deleted product') ?></b><small><?= (int) $l['quantity'] ?> × <?= CURRENCY . number_format((float) $l['unit_price'], 2) ?><?= $opt !== '' ? ' · ' . admin_h($opt) : '' ?></small></span></span>
      <b><?= CURRENCY . number_format((float) $l['unit_price'] * (int) $l['quantity'], 2) ?></b>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$groups): ?><div class="card muted">This order has no items.</div><?php endif; ?>
<div class="card" style="margin-top:16px;max-width:360px;margin-left:auto">
  <div style="display:flex;justify-content:space-between"><span class="muted">Items</span><span><?= CURRENCY . number_format($sub, 2) ?></span></div>
  <?php if (abs($delivery) > 0.004): ?><div style="display:flex;justify-content:space-between;margin-top:6px"><span class="muted">Delivery / other</span><span><?= CURRENCY . number_format($delivery, 2) ?></span></div><?php endif; ?>
  <div style="display:flex;justify-content:space-between;margin-top:10px;font-size:20px;font-weight:800"><span>Total</span><span><?= CURRENCY . number_format((float) $o['total'], 2) ?></span></div>
</div>
<?php require 'includes/footer.php';
