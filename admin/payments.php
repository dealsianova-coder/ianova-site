<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
pay_ensure($pdo);
$error = ''; $notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $m = pay_settings_save($pdo, $_POST['deposit_pct'] ?? '', (string) ($_POST['instructions'] ?? ''));
    if ($m === '') $notice = 'Saved. The app and the website use this right away.'; else $error = $m;
}
$set = pay_settings_get($pdo);
$filter = (string) ($_GET['f'] ?? 'part');
if (!in_array($filter, ['deposit', 'part', 'unpaid', 'all'], true)) $filter = 'part';
$rows = pay_balances($pdo, $filter, 100);
$due = 0.0; foreach ($rows as $r) $due += $r['balance'];
$page_title = 'Payments & deposits';
require 'includes/header.php';
$m = fn($v) => CURRENCY . number_format((float) $v, 2);
$tags = ['unpaid' => ['Not paid', '#8a8f98'], 'part' => ['Part paid', '#e0a100'], 'deposit' => ['Deposit paid', '#e0a100']];
?>
<style>.pg{display:grid;gap:10px}.pg label{font-size:12px;font-weight:700;color:#5b6670;display:block;margin-bottom:3px}.pg input,.pg textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #d9dee3;border-radius:10px;font:inherit}.chips a{display:inline-block;padding:6px 14px;border-radius:20px;background:#eef2f0;color:#25302b;text-decoration:none;font-weight:700;margin:0 6px 6px 0}.chips a.on{background:#1e8e5a;color:#fff}</style>
<h1>💳 Payments &amp; deposits</h1>
<?php if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid #1e8e5a">
  <form method="post" class="pg"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
    <div><label>Deposit % (0 = customers must pay in full)</label><input name="deposit_pct" type="number" step="0.01" min="0" max="100" value="<?= admin_h($set['deposit_pct']) ?>"></div>
    <div><label>How customers pay (shown after they order and on their order page)</label><textarea name="instructions" rows="3" maxlength="400" placeholder="e.g. Send M-Pesa to Till 123456, then we confirm your order."><?= admin_h($set['instructions']) ?></textarea></div>
    <div><button class="btn">Save</button></div></form>
  <p class="muted" style="margin:10px 0 0">With a deposit set, customers choose at checkout: pay in full, or pay the deposit now and the rest later. You record each payment you receive on the order; the customer gets an email with the balance.</p>
</div>
<div class="chips"><?php foreach (['part' => 'Part paid', 'deposit' => 'Deposit orders', 'unpaid' => 'Not paid yet', 'all' => 'All with a balance'] as $k => $l): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="payments.php?f=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="card" style="margin-bottom:10px"><span class="muted">Still to collect on these orders</span><div style="font-size:22px;font-weight:800;color:#b36b00"><?= $m($due) ?></div></div>
<?php if (!$rows): ?><div class="card muted">No orders to show here.</div><?php endif; ?>
<?php foreach ($rows as $r): $t = $tags[$r['state']] ?? $tags['unpaid']; ?>
<a href="order_view.php?id=<?= (int) $r['id'] ?>" style="text-decoration:none;color:inherit"><div class="card" style="margin-bottom:8px;border-left:5px solid <?= $t[1] ?>">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><b>Order #<?= (int) $r['id'] ?></b> · <?= admin_h($r['customer']) ?><div class="muted"><?= admin_h($r['created_at']) ?> · <?= admin_h($r['status']) ?><?= $r['plan'] === 'deposit' ? ' · deposit ' . rtrim(rtrim(number_format($r['deposit_pct'], 2), '0'), '.') . '%' : '' ?></div></div>
  <div style="text-align:right"><span style="background:<?= $t[1] ?>;color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700"><?= admin_h($t[0]) ?></span><div class="muted">Paid <?= $m($r['paid'] - $r['refunded']) ?> of <?= $m($r['total']) ?></div><b style="color:#b36b00">Balance <?= $m($r['balance']) ?></b></div></div></div></a>
<?php endforeach; ?>
<?php require 'includes/footer.php'; ?>
