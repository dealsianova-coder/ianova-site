<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/seller_ops.php';
if (empty($_SESSION['seller_id'])) { header('Location: login.php'); exit; }
ops_ensure($pdo);
$sellerId = (int) $_SESSION['seller_id'];
$oid = (int) ($_GET['id'] ?? 0); $pid = (int) ($_GET['p'] ?? 0);
$col = (string) ($_GET['c'] ?? ''); $siz = (string) ($_GET['s'] ?? '');
$q = $pdo->prepare("SELECT o.id, o.created_at, o.status, o.address, o.guest_phone, COALESCE(o.guest_name, u.name, 'Customer') AS customer FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
$q->execute([$oid]); $o = $q->fetch();
$iq = $pdo->prepare('SELECT oi.quantity, oi.unit_price, p.id pid, p.name, p.color, p.size FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.seller_id = ? AND p.id = ?');
$iq->execute([$oid, $sellerId, $pid]);
$it = null; foreach ($iq->fetchAll() as $r) { if ((string) ($r['color'] ?? '') === $col && (string) ($r['size'] ?? '') === $siz) { $it = $r; break; } }
$ap = $pdo->prepare('SELECT 1 FROM seller_order_approvals WHERE order_id = ? AND seller_id = ?'); $ap->execute([$oid, $sellerId]); $done = (bool) $ap->fetchColumn();
$h = fn($v) => ops_h($v);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Order item — IANOVA Seller</title><style>
*{box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;background:#FAFAFB;margin:0;color:#0F0F12}
.wrap{max-width:640px;margin:20px auto;padding:0 14px}.btn{display:inline-block;border-radius:14px;padding:10px 14px;background:#0F0F12;color:#fff;font-weight:700;text-decoration:none;font-size:14px}
.card{background:#fff;border:1px solid #E9EBEF;border-radius:16px;padding:18px;margin-bottom:14px}.muted{color:#6b7973;font-size:13px}
.sum{display:flex;justify-content:space-between;background:#E8F3EC;border-radius:12px;padding:14px;font-weight:700;margin-top:12px}.sum b{font-size:20px}
dl{display:grid;grid-template-columns:100px 1fr;gap:8px 12px;margin:10px 0 0}dt{color:#6b7973}dd{margin:0;font-weight:700}
.pill{display:inline-block;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:800;background:#EEF0F3;text-transform:capitalize}.ok{background:#E3F6E8;color:#17803d}.warn{background:#FFF1D6;color:#a05a00}
</style></head><body><div class="wrap">
<p><a class="btn" href="orders.php">← Back to orders</a></p>
<?php if (!$o || !$it): ?><div class="card">Order item not found.</div><?php else: $opt = trim(($it['color'] ?? '') . ' ' . ($it['size'] ?? '')); ?>
<div class="card"><h2 style="margin:0 0 6px"><?= $h($it['name']) ?></h2><?php if ($opt !== ''): ?><span class="muted"><?= $h($opt) ?></span><?php endif; ?>
  <div class="sum"><span><?= (int) $it['quantity'] ?> × <?= CURRENCY . number_format((float) $it['unit_price'], 2) ?></span><b><?= CURRENCY . number_format((float) $it['unit_price'] * (int) $it['quantity'], 2) ?></b></div></div>
<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><b>In order #<?= (int) $o['id'] ?></b>
  <span><span class="pill"><?= $h($o['status']) ?></span> <?php if ($o['status'] === 'cancelled'): ?><span class="pill">Cancelled</span><?php else: ?><span class="pill <?= $done ? 'ok' : 'warn' ?>"><?= $done ? 'You approved' : 'Waiting for you' ?></span><?php endif; ?></span></div>
  <div class="muted"><?= $h($o['created_at']) ?></div>
  <dl><dt>Customer</dt><dd><?= $h($o['customer']) ?></dd><dt>Phone</dt><dd><?= $h($o['guest_phone'] ?? '') ?></dd><dt>Deliver to</dt><dd><?= $h($o['address'] ?? '') ?></dd></dl></div>
<?php endif; ?></div></body></html>
