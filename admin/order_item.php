<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';
$id = (int) ($_GET['id'] ?? 0);
$pid = (int) ($_GET['p'] ?? 0);
$col = (string) ($_GET['c'] ?? ''); $siz = (string) ($_GET['s'] ?? '');
$tab = ($_GET['tab'] ?? '') === 'sellers' ? 'sellers' : 'main';
$s = $pdo->prepare("SELECT o.*, COALESCE(o.guest_name, u.name, 'Guest') customer FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
$s->execute([$id]);
$o = $s->fetch();
$page_title = 'Order item';
require 'includes/header.php';
echo aou_css();
$line = null;
if ($o) foreach ((aou_items($pdo, [$id])[$id] ?? []) as $l) {
    if ((int) ($l['product_id'] ?? 0) === $pid && (string) ($l['color'] ?? '') === $col && (string) ($l['size'] ?? '') === $siz) { $line = $l; break; }
}
if (!$o || !$line) { echo '<div class="notice error">Order item not found.</div><a class="btn gray" href="order_view.php?id=' . $id . '">← Back to order</a>'; require 'includes/footer.php'; exit; }
$appr = aou_approvals($pdo, [$id])[$id] ?? [];
$sid = (int) ($line['seller_id'] ?? 0);
$opt = trim(($line['color'] ?? '') . ' ' . ($line['size'] ?? ''));
$qty = (int) $line['quantity']; $price = (float) $line['unit_price'];
?>
<style>
.oi{max-width:760px;display:grid;gap:14px}
.oi-hero{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.oi-sum{display:flex;justify-content:space-between;align-items:center;background:#e1f3e8;border-radius:14px;padding:14px 16px;font-weight:700;margin-top:12px}
.oi-sum b{font-size:22px;color:#1d6b52}
.oi dl{display:grid;grid-template-columns:110px 1fr;gap:8px 12px;margin:10px 0 0}.oi dt{color:#6b7973}.oi dd{margin:0;font-weight:700}
</style>
<div class="actions" style="margin-bottom:14px"><a class="btn gray" href="order_view.php?id=<?= (int) $id ?>&tab=<?= $tab ?>">← Back to order #<?= (int) $id ?></a></div>
<div class="oi">
  <div class="card"><div class="oi-hero"><?= aou_thumb($line['image'] ?? '', $line['emoji'] ?? '', $line['bg_color'] ?? '', 160) ?>
    <div><h2 style="margin:0 0 6px"><?= admin_h($line['name'] ?? 'Deleted product') ?></h2><?php if ($opt !== ''): ?><span class="tag na"><?= admin_h($opt) ?></span><?php endif; ?></div></div>
    <div class="oi-sum"><span><?= $qty ?> × <?= CURRENCY . number_format($price, 2) ?></span><b><?= CURRENCY . number_format($price * $qty, 2) ?></b></div></div>
  <div class="card" style="border-left:5px solid #2f6fde"><h3 style="margin:0">In order #<?= (int) $o['id'] ?> <?= aou_status_tag((string) $o['status']) ?></h3><div class="muted"><?= admin_h($o['created_at']) ?></div>
    <dl><dt>Customer</dt><dd><?= admin_h($o['customer']) ?></dd><dt>Phone</dt><dd><?= admin_h($o['guest_phone'] ?? '') ?></dd><dt>Address</dt><dd><?= admin_h($o['address'] ?? '') ?></dd></dl></div>
  <div class="card" style="border-left:5px solid <?= $sid === 0 ? '#1d6b52' : '#b58117' ?>"><h3 style="margin:0 0 8px">Sold by</h3>
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><b><?= $sid === 0 ? '🏠 IANOVA (main)' : '🏪 ' . admin_h($line['seller_name'] ?? '') ?></b>
    <?php if ($sid === 0): ?><span class="tag na">Managed by you</span><?php else: ?><?= aou_approval_tags(isset($appr[$sid]) ? [$sid => $appr[$sid]] : []) ?><?php endif; ?></div></div>
  <?php if (in_array($o['status'], ['pending', 'approved', 'paid'], true)): ?><form method="post" action="order_ops.php" class="card" style="border-left:5px solid #b83b3b"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="item_cancel"><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="product_id" value="<?= (int) $pid ?>">
    <h3 style="margin:0 0 6px">Cancel this item</h3><p class="muted" style="margin:0 0 10px">Removes only this item, puts its stock back, works out the new total and emails the customer.</p><button class="btn red" onclick="return confirm('Cancel this item?')">Cancel item</button></form><?php endif; ?>
</div>
<?php require 'includes/footer.php'; ?>
