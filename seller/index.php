<?php
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['seller_id'])) {
    header('Location: login.php');
    exit;
}

$seller_id = (int) $_SESSION['seller_id'];
require_once __DIR__ . '/../includes/seller_ops.php';
if (empty($_SESSION['ops_csrf'])) $_SESSION['ops_csrf'] = bin2hex(random_bytes(16));

$stmt = $pdo->prepare('SELECT * FROM sellers WHERE id = ?');
$stmt->execute([$seller_id]);
$seller = $stmt->fetch();

$products = $pdo->prepare('SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC');
$products->execute([$seller_id]);
$products = $products->fetchAll();
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Seller Dashboard — IANOVA</title><style>
*{box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;background:#FAFAFB;margin:0;color:#0F0F12}
.top{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;background:#fff;border-bottom:1px solid #E9EBEF}
.top h1{margin:0;font-size:20px}
.wrap{max-width:1000px;margin:24px auto;padding:0 16px}
.btn{display:inline-block;border:0;border-radius:16px;padding:10px 14px;background:#0F0F12;color:#fff;cursor:pointer;font-weight:700;text-decoration:none}
.btn.amber{background:#0F0F12;color:#0F0F12}
.table-wrap{overflow:auto;background:#fff;border:1px solid #E9EBEF;border-radius:14px}
table{width:100%;border-collapse:collapse;min-width:600px}
th,td{padding:12px 14px;border-bottom:1px solid #e8eeea;text-align:left}
th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;background:#f7f9f8}
.muted{color:#6b7973}
</style></head><body>
<div class="top">
<div><h1><?= h($seller['business_name']) ?></h1><div class="muted">Seller dashboard</div></div>
<div><a class="btn" href="orders.php">Orders</a> <a class="btn amber" href="product_edit.php">+ Add product</a> <a class="btn" href="logout.php">Sign out</a></div>
</div>
<div class="wrap">
<?php if (!empty($_SESSION['ops_flash'])): ?><div style="padding:12px 14px;border-radius:12px;background:#EEF4FF;margin-bottom:14px"><?= ops_h($_SESSION['ops_flash']) ?></div><?php unset($_SESSION['ops_flash']); endif; ?>
<?php if (in_array($seller['status'] ?? '', ['suspended', 'rejected'], true)): ?><div style="padding:14px;border-radius:12px;background:#FFEDEE;margin-bottom:14px"><b>Your seller account is restricted by the admin.</b> You cannot add or edit products or approve orders until the admin approves it again.<form method="post" action="request_review.php" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="product_id" value="0"><input name="note" maxlength="800" placeholder="What did you correct?" style="width:100%;padding:8px;border:1px solid #E9EBEF;border-radius:10px;margin-bottom:6px"><button class="btn" type="submit">Request review</button></form></div><?php endif; ?>
<?php if (!$products): ?>
<p class="muted">You haven't added any products yet.</p>
<?php else: ?>
<div class="table-wrap">
<table>
<tr><th>Product</th><th>Price</th><th>Stock</th><th>Status</th><th>Flash deal</th><th>Actions</th></tr>
<?php foreach ($products as $p): ?>
<tr>
<td><b><?= h($p['name']) ?></b></td>
<td><?= CURRENCY . number_format($p['price'], 2) ?></td>
<td><?= intval($p['stock']) ?></td>
<td><?php $st=$p['status']??'approved'; $color=$st==='approved'?'green':($st==='pending'?'orange':'red'); ?><span style="color:<?=$color?>;font-weight:700;text-transform:capitalize;"><?= h($st) ?></span></td>
<td><?= !empty($p['is_flash_deal']) ? 'Yes' : 'No' ?></td>
<td><?php if (ops_product_locked($p)): ?><div style="color:#b3261e;font-weight:700;font-size:13px">Locked by admin</div><form method="post" action="request_review.php" style="margin-top:6px"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="product_id" value="<?= intval($p['id']) ?>"><input name="note" maxlength="800" placeholder="What did you correct?" style="width:100%;padding:8px;border:1px solid #E9EBEF;border-radius:10px;margin-bottom:6px"><button class="btn" type="submit">Request review</button></form><?php else: ?><a class="btn" href="product_edit.php?id=<?= intval($p['id']) ?>">Edit</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>
</body></html>
