<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/payouts.php';
po_ensure($pdo);
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$error = ''; $notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $sid = (int) ($_POST['seller_id'] ?? 0);
    if ($do === 'pay') {
        $m = po_add($pdo, $meId, $sid, (float) ($_POST['amount'] ?? 0), (string) ($_POST['method'] ?? ''), (string) ($_POST['reference'] ?? ''), (string) ($_POST['note'] ?? ''));
        if ($m === '') $notice = 'Payout recorded and the seller was emailed.'; else $error = $m;
    } elseif ($do === 'rate') {
        $raw = trim((string) ($_POST['rate'] ?? ''));
        $m = po_set_rate($pdo, $sid, $raw === '' ? null : $raw);
        if ($m === '') $notice = 'Commission saved.'; else $error = $m;
    }
}
$rows = po_overview($pdo);
$defRate = po_default_rate($pdo);
$owed = 0.0; $paidAll = 0.0; foreach ($rows as $r) { $owed += max(0, $r['balance']); $paidAll += $r['paid']; }
$open = (int) ($_GET['seller'] ?? 0);
$page_title = 'Seller payouts';
require 'includes/header.php';
$m = fn($v) => CURRENCY . number_format((float) $v, 2);
?>
<style>.pg{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end}.pg label{font-size:12px;font-weight:700;color:#5b6670;display:block;margin-bottom:3px}.pg input{width:100%;box-sizing:border-box;padding:9px;border:1px solid #d9dee3;border-radius:10px}.pk{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin:8px 0}.pk div{background:#f6f8f7;border-radius:12px;padding:10px}.pk b{display:block;font-size:17px}</style>
<h1>💸 Seller payouts</h1>
<?php if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid #1e8e5a">
  <div class="pk"><div><span class="muted">Owed to sellers now</span><b><?= $m($owed) ?></b></div><div><span class="muted">Paid out so far</span><b><?= $m($paidAll) ?></b></div><div><span class="muted">Store commission</span><b><?= rtrim(rtrim(number_format($defRate, 2), '0'), '.') ?>%</b></div></div>
  <form method="post" class="pg" style="margin-top:6px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="rate"><input type="hidden" name="seller_id" value="0">
    <div><label>Default commission %</label><input name="rate" type="number" step="0.01" min="0" max="100" value="<?= admin_h($defRate) ?>"></div><div><button class="btn">Save default</button></div></form>
  <p class="muted" style="margin:10px 0 0">A seller earns the item price minus commission once the order is <b>delivered</b>. Refunds on a delivered order reduce it. Coupons are paid by the store. A new commission applies to all of a seller's sales, so change it rarely.</p>
</div>
<?php if (!$rows): ?><div class="card muted">No sellers yet.</div><?php endif; ?>
<?php foreach ($rows as $r): $hist = ($open === $r['id']) ? po_history($pdo, $r['id'], 30) : []; ?>
<div class="card" style="margin-bottom:10px;border-left:5px solid <?= $r['balance'] > 0.009 ? '#e0a100' : '#8a8f98' ?>">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><b style="font-size:17px"><?= admin_h($r['name']) ?></b><div class="muted"><?= admin_h($r['email']) ?> · commission <?= rtrim(rtrim(number_format($r['rate'], 2), '0'), '.') ?>%<?= $r['custom_rate'] ? ' (custom)' : '' ?></div></div>
    <div style="text-align:right"><span class="muted">Balance owed</span><div style="font-size:22px;font-weight:800;color:<?= $r['balance'] > 0.009 ? '#b36b00' : '#1e8e5a' ?>"><?= $m($r['balance']) ?></div></div></div>
  <div class="pk"><div><span class="muted">Sold (delivered)</span><b><?= $m($r['gross']) ?></b></div><div><span class="muted">Refunds</span><b><?= $m($r['refunded']) ?></b></div><div><span class="muted">Commission</span><b><?= $m($r['commission']) ?></b></div><div><span class="muted">Earned</span><b><?= $m($r['earned']) ?></b></div><div><span class="muted">Paid</span><b><?= $m($r['paid']) ?></b></div><div><span class="muted">Coming (not delivered)</span><b><?= $m($r['coming']) ?></b></div></div>
  <?php if ($r['balance'] > 0.009): ?>
  <form method="post" class="pg"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="pay"><input type="hidden" name="seller_id" value="<?= $r['id'] ?>">
    <div><label>Amount paid</label><input name="amount" type="number" step="0.01" min="0.01" max="<?= admin_h($r['balance']) ?>" value="<?= admin_h($r['balance']) ?>" required></div>
    <div><label>Method</label><input name="method" maxlength="40" placeholder="M-Pesa, bank"></div><div><label>Reference</label><input name="reference" maxlength="80" placeholder="Transaction code"></div>
    <div><button class="btn" onclick="return confirm('Record this payout? The seller gets an email.')">Record payout</button></div></form>
  <?php endif; ?>
  <form method="post" class="pg" style="margin-top:8px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="rate"><input type="hidden" name="seller_id" value="<?= $r['id'] ?>">
    <div><label>Custom commission % (empty = default)</label><input name="rate" type="number" step="0.01" min="0" max="100" value="<?= $r['custom_rate'] ? admin_h($r['rate']) : '' ?>"></div><div><button class="btn gray">Save commission</button> <a class="btn gray" href="payouts.php?seller=<?= $open === $r['id'] ? 0 : $r['id'] ?>"><?= $open === $r['id'] ? 'Hide history' : 'Payout history' ?></a></div></form>
  <?php foreach ($hist as $h): ?><div style="border-top:1px solid #e8eeea;padding:6px 0;margin-top:6px"><b><?= $m($h['amount']) ?></b> <?= admin_h($h['method']) ?> <?= $h['reference'] !== '' ? '· ' . admin_h($h['reference']) : '' ?> <span class="muted">· <?= admin_h($h['admin_name']) ?> · <?= admin_h($h['created_at']) ?></span></div><?php endforeach; ?>
  <?php if ($open === $r['id'] && !$hist): ?><p class="muted">No payouts recorded yet.</p><?php endif; ?>
</div>
<?php endforeach; ?>
<?php require 'includes/footer.php'; ?>
