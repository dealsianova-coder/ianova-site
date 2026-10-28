<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';
require_once __DIR__ . '/../includes/order_ops.php';
$ids = !empty($_GET['ids']) ? array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['ids']))))), 0, 50) : [(int) ($_GET['id'] ?? 0)];
$m = fn($v) => CURRENCY . number_format((float) $v, 2);
$sheets = [];
foreach ($ids as $id) {
    $s = $pdo->prepare("SELECT o.*, COALESCE(o.guest_name, u.name, 'Guest') customer, COALESCE(o.guest_email, u.email) email FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
    $s->execute([$id]);
    $o = $s->fetch();
    if (!$o) continue;
    $lines = aou_items($pdo, [$id])[$id] ?? [];
    $sub = aou_lines_total($lines);
    $delivery = round((float) $o['total'] - $sub, 2);
    $ex = oo_extras($pdo, $id);
    $sheets[] = compact('o', 'lines', 'sub', 'delivery', 'ex');
}
if (!$sheets) { http_response_code(404); exit('Order not found.'); }
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= count($sheets) > 1 ? 'Invoices (' . count($sheets) . ')' : 'Invoice #' . (int) $sheets[0]['o']['id'] ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;color:#17231e;margin:0;background:#f3f6f4}
.sheet{max-width:780px;margin:24px auto;background:#fff;padding:36px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.head{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #e9b949;padding-bottom:16px;flex-wrap:wrap}
.brand{font-size:28px;font-weight:900;color:#17352c}.muted{color:#66746d;font-size:13px}
table{width:100%;border-collapse:collapse;margin-top:20px}th{background:#17352c;color:#fff;text-align:left;padding:10px;font-size:13px}td{padding:10px;border-bottom:1px solid #e6ece8;font-size:14px}
.r{text-align:right}.tot td{border:0;font-size:15px}.grand td{font-size:19px;font-weight:900;color:#1d6b52}
.bar{max-width:780px;margin:16px auto 0;text-align:right}.btn{background:#1d6b52;color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer;font-size:14px}
@media print{body{background:#fff}.sheet{box-shadow:none;margin:0;padding:0}.sheet + .sheet{break-before:page}.bar{display:none}}
</style></head><body>
<div class="bar"><button class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button></div>
<?php foreach ($sheets as $S_): extract($S_); ?>
<div class="sheet">
  <div class="head"><div><div class="brand"><?= admin_h(SITE_NAME) ?></div><div class="muted"><?= admin_h(SITE_SLOGAN) ?></div></div>
    <div style="text-align:right"><div style="font-size:22px;font-weight:900">INVOICE</div><div class="muted">Order #<?= (int) $o['id'] ?><br><?= admin_h($o['created_at']) ?><br>Status: <?= admin_h(ucfirst((string) $o['status'])) ?></div></div></div>
  <div style="display:flex;gap:40px;margin-top:18px;flex-wrap:wrap"><div><b>Billed to</b><br><?= admin_h($o['customer']) ?><br><span class="muted"><?= admin_h($o['email'] ?? '') ?></span></div>
    <div><b>Deliver to</b><br><?= admin_h($o['guest_phone'] ?? '') ?><br><span class="muted"><?= admin_h($o['address'] ?? '') ?></span></div></div>
  <table><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr>
  <?php foreach ($lines as $l): $opt = trim(($l['color'] ?? '') . ' ' . ($l['size'] ?? '')); ?>
    <tr><td><b><?= admin_h($l['name'] ?? 'Deleted product') ?></b><?= $opt !== '' ? '<br><span class="muted">' . admin_h($opt) . '</span>' : '' ?><br><span class="muted">Sold by <?= admin_h($l['seller_name'] ?? 'IANOVA') ?></span></td><td class="r"><?= (int) $l['quantity'] ?></td><td class="r"><?= $m($l['unit_price']) ?></td><td class="r"><?= $m((float) $l['unit_price'] * (int) $l['quantity']) ?></td></tr>
  <?php endforeach; ?></table>
  <table class="tot"><tr><td></td><td class="r" style="width:180px">Items</td><td class="r" style="width:140px"><?= $m($sub) ?></td></tr>
  <?php if (abs($delivery) > 0.004): ?><tr><td></td><td class="r">Delivery / other</td><td class="r"><?= $m($delivery) ?></td></tr><?php endif; ?>
  <tr class="grand"><td></td><td class="r">Total</td><td class="r"><?= $m($o['total']) ?></td></tr>
  <?php if ($ex['refunded'] > 0): ?><tr><td></td><td class="r">Refunded</td><td class="r">− <?= $m($ex['refunded']) ?></td></tr><tr class="grand"><td></td><td class="r">Net paid</td><td class="r"><?= $m((float) $o['total'] - $ex['refunded']) ?></td></tr><?php endif; ?></table>
  <?php if ($ex['tracking']['number'] !== '' || $ex['tracking']['courier'] !== ''): ?><p class="muted" style="margin-top:18px"><b>Delivery:</b> <?= admin_h($ex['tracking']['courier']) ?> <?= admin_h($ex['tracking']['number']) ?></p><?php endif; ?>
  <p class="muted" style="margin-top:28px;text-align:center">Thank you for shopping with <?= admin_h(SITE_NAME) ?>.</p>
</div>
<?php endforeach; ?></body></html>
