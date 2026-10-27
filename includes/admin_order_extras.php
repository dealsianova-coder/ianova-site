<?php
/** Order page extras for the website admin: internal notes + print invoice. */
function admin_order_extras(PDO $pdo, array $o): void {
    require_once __DIR__ . '/order_ops.php';
    require_once __DIR__ . '/payments.php';
    $ex = oo_extras($pdo, (int) $o['id']);
    $pay = pay_summary($pdo, (int) $o['id']);
    $payRows = pay_list($pdo, (int) $o['id']);
    $payTag = ['unpaid' => ['Not paid', '#8a8f98'], 'part' => ['Part paid', '#e0a100'], 'deposit' => ['Deposit paid', '#e0a100'], 'paid' => ['Paid in full', '#1e8e5a'], 'cancelled' => ['Cancelled', '#b83b3b']];
    if (!empty($_SESSION['oo_flash'])) { echo '<div class="notice' . (!empty($_SESSION['oo_flash_err']) ? ' error' : '') . '">' . admin_h($_SESSION['oo_flash']) . '</div>'; unset($_SESSION['oo_flash'], $_SESSION['oo_flash_err']); }
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
    $q = $pdo->prepare('SELECT admin_name, note, created_at FROM order_notes WHERE order_id = ? ORDER BY id DESC'); $q->execute([(int) $o['id']]);
    $notes = $q->fetchAll();
    $cpn = null;
    try { $cq = $pdo->prepare("SELECT coupon_code, discount FROM orders WHERE id = ? AND coupon_code <> ''"); $cq->execute([(int) $o['id']]); $cpn = $cq->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { $cpn = null; }
    ?>
<?php if ($cpn): ?><div class="card" style="margin-bottom:16px;border-left:5px solid #e0a100"><b>🎟️ Coupon <?= admin_h($cpn['coupon_code']) ?></b> took <b><?= CURRENCY . number_format((float) $cpn['discount'], 2) ?></b> off this order (paid by the store, sellers still earn the full price).</div><?php endif; ?>
<?php if ($pay): $pt = $payTag[$pay['state']] ?? $payTag['unpaid']; $mm = fn($v) => CURRENCY . number_format((float) $v, 2); ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid <?= $pt[1] ?>"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h3 style="margin:0">💳 Payments</h3><span style="background:<?= $pt[1] ?>;color:#fff;border-radius:20px;padding:3px 12px;font-weight:700;font-size:13px"><?= admin_h($pt[0]) ?></span></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin:10px 0"><div style="background:#f6f8f7;border-radius:12px;padding:10px"><span class="muted">Order total</span><b style="display:block;font-size:17px"><?= $mm($pay['total']) ?></b></div>
    <?php if ($pay['plan'] === 'deposit'): ?><div style="background:#f6f8f7;border-radius:12px;padding:10px"><span class="muted">Deposit (<?= rtrim(rtrim(number_format($pay['deposit_pct'], 2), '0'), '.') ?>%)</span><b style="display:block;font-size:17px"><?= $mm($pay['deposit_due']) ?></b></div><?php endif; ?>
    <div style="background:#f6f8f7;border-radius:12px;padding:10px"><span class="muted">Paid</span><b style="display:block;font-size:17px"><?= $mm($pay['paid'] - $pay['refunded']) ?></b></div>
    <div style="background:#f6f8f7;border-radius:12px;padding:10px"><span class="muted">Balance</span><b style="display:block;font-size:17px;color:<?= $pay['balance'] > 0.009 ? '#b36b00' : '#1e8e5a' ?>"><?= $mm($pay['balance']) ?></b></div></div>
  <p class="muted" style="margin:0 0 8px"><?= $pay['plan'] === 'deposit' ? 'The customer chose to pay a deposit now and the rest later.' : 'The customer chose to pay in full.' ?> Record each payment you receive (M-Pesa, cash, bank); the customer gets an email.</p>
  <?php foreach ($payRows as $p): ?><div style="border-top:1px solid #e8eeea;padding:6px 0;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><span><b><?= $mm($p['amount']) ?></b> <?= admin_h($p['method']) ?> <?= $p['reference'] !== '' ? '· ' . admin_h($p['reference']) : '' ?> <span class="muted">· <?= admin_h($p['admin_name']) ?> · <?= admin_h($p['created_at']) ?></span></span>
    <form method="post" action="order_ops.php" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="kind" value="payment_delete"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button class="btn gray" onclick="return confirm('Remove this payment? Only do this if it was entered by mistake.')">Remove</button></form></div><?php endforeach; ?>
  <?php if ($pay['state'] !== 'cancelled' && $pay['balance'] > 0.009): ?><form method="post" action="order_ops.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end;margin-top:8px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="kind" value="payment">
    <input name="amount" type="number" step="0.01" min="0.01" max="<?= admin_h($pay['balance']) ?>" required placeholder="Amount received" value="<?= admin_h($pay['deposit_left'] > 0.009 ? $pay['deposit_left'] : $pay['balance']) ?>"><input name="method" maxlength="40" placeholder="Method (M-Pesa, cash)"><input name="reference" maxlength="80" placeholder="Transaction code"><button class="btn" onclick="return confirm('Record this payment and email the customer?')">Record payment</button></form><?php endif; ?></div>
<?php endif; ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid #17a2a2"><h3 style="margin:0 0 8px">🚚 Delivery tracking</h3>
  <form method="post" action="order_ops.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;align-items:end"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="kind" value="tracking">
    <input name="courier" value="<?= admin_h($ex['tracking']['courier']) ?>" placeholder="Courier (e.g. Sendy)"><input name="number" value="<?= admin_h($ex['tracking']['number']) ?>" placeholder="Tracking number"><input name="note" value="<?= admin_h($ex['tracking']['note']) ?>" placeholder="Note (optional)"><button class="btn">Save and email customer</button></form></div>
<div class="card" style="margin-bottom:16px;border-left:5px solid #b83b3b"><h3 style="margin:0 0 4px">↩️ Refunds <?php if ($ex['refunded'] > 0): ?><span class="muted">(<?= CURRENCY . number_format($ex['refunded'], 2) ?> refunded)</span><?php endif; ?></h3>
  <p class="muted" style="margin:0 0 8px">Records a refund you paid out yourself (M-Pesa, bank, cash) and emails the customer.</p>
  <?php foreach ($ex['refunds'] as $r): ?><div style="border-top:1px solid #e8eeea;padding:6px 0"><b><?= CURRENCY . number_format((float) $r['amount'], 2) ?></b> <?= admin_h($r['method']) ?> <span class="muted"><?= admin_h($r['reason']) ?> · <?= admin_h($r['admin_name']) ?> · <?= admin_h($r['created_at']) ?></span></div><?php endforeach; ?>
  <?php if ($o['status'] !== 'pending' || ($pay && $pay['paid'] > 0)): ?><form method="post" action="order_ops.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end;margin-top:8px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="kind" value="refund">
    <input name="amount" type="number" step="0.01" min="0.01" required placeholder="Amount"><input name="method" placeholder="Method (M-Pesa, bank)"><input name="reason" placeholder="Reason"><button class="btn red" onclick="return confirm('Record this refund and email the customer?')">Record refund</button></form><?php endif; ?></div>
<?php $tl = oo_timeline($pdo, (int) $o['id']); if ($tl): ?><div class="card" style="margin-bottom:16px;border-left:5px solid #2f6fde"><h3 style="margin:0 0 10px">🕒 Timeline</h3>
  <?php foreach ($tl as $e): ?><div style="display:flex;gap:10px;padding:7px 0;border-top:1px solid #eef1ef"><span style="font-size:18px"><?= $e['icon'] ?></span><div style="flex:1"><b><?= admin_h($e['title']) ?></b><?php if ($e['detail'] !== ''): ?> <span class="muted"><?= admin_h($e['detail']) ?></span><?php endif; ?><div class="muted" style="font-size:12.5px"><?= admin_h($e['at']) ?><?= $e['by'] !== '' ? ' · ' . admin_h($e['by']) : '' ?></div></div></div><?php endforeach; ?>
  <p class="muted" style="margin:8px 0 0;font-size:12.5px">Status changes are recorded from when this feature was installed.</p></div><?php endif; ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid #7c6fd0">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h3 style="margin:0">📝 Internal notes</h3><a class="btn gray" target="_blank" href="invoice.php?id=<?= (int) $o['id'] ?>">🖨️ Print invoice</a></div>
  <p class="muted" style="margin:6px 0 10px">Only admins see these. Customers and sellers do not.</p>
  <?php foreach ($notes as $n): ?><div style="border-top:1px solid #e8eeea;padding:8px 0"><?= nl2br(admin_h($n['note'])) ?><div class="muted"><?= admin_h($n['admin_name']) ?> · <?= admin_h($n['created_at']) ?></div></div><?php endforeach; ?>
  <form method="post" action="order_note.php" style="display:grid;gap:8px;margin-top:10px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
    <textarea name="note" rows="2" required placeholder="Add a note (e.g. called the customer)" style="width:100%;box-sizing:border-box"></textarea><div><button class="btn">Add note</button></div></form>
</div>
<?php
}
