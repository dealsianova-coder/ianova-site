<?php
/** Balance box for the seller website dashboard (included by seller/index.php). */
function seller_balance_box(PDO $pdo, int $sellerId): void {
    try {
        require_once __DIR__ . '/../includes/payouts.php';
        $f = po_figures($pdo, $sellerId)[$sellerId];
        $hist = po_history($pdo, $sellerId, 5);
    } catch (Throwable $e) { return; }
    $m = fn($v) => (defined('CURRENCY') ? CURRENCY : 'KSh ') . number_format((float) $v, 2);
    $r = rtrim(rtrim(number_format($f['rate'], 2), '0'), '.');
    ?>
<div style="background:#fff;border:1px solid #E9EBEF;border-left:5px solid #1e8e5a;border-radius:14px;padding:16px;margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><div><div class="muted">Balance owed to you</div><div style="font-size:26px;font-weight:800;color:#1e8e5a"><?= $m($f['balance']) ?></div></div>
    <div class="muted" style="text-align:right">Paid so far <b><?= $m($f['paid']) ?></b><br>Coming after delivery <b><?= $m($f['coming']) ?></b></div></div>
  <div class="muted" style="margin-top:8px;font-size:13px">You earn the item price minus the <?= htmlspecialchars($r) ?>% store commission once an order is delivered. Sold and delivered: <?= $m($f['gross']) ?>, commission <?= $m($f['commission']) ?><?= $f['refunded'] > 0 ? ', refunds ' . $m($f['refunded']) : '' ?>.</div>
  <?php foreach ($hist as $h): ?><div style="border-top:1px solid #e8eeea;padding:6px 0;margin-top:6px;font-size:14px"><b><?= $m($h['amount']) ?></b> <?= htmlspecialchars($h['method']) ?> <span class="muted"><?= htmlspecialchars(substr($h['created_at'], 0, 10)) ?></span></div><?php endforeach; ?>
</div>
<?php
}
