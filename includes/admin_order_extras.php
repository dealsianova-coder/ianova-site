<?php
/** Order page extras for the website admin: internal notes + print invoice. */
function admin_order_extras(PDO $pdo, array $o): void {
    require_once __DIR__ . '/order_ops.php';
    $ex = oo_extras($pdo, (int) $o['id']);
    if (!empty($_SESSION['oo_flash'])) { echo '<div class="notice' . (!empty($_SESSION['oo_flash_err']) ? ' error' : '') . '">' . admin_h($_SESSION['oo_flash']) . '</div>'; unset($_SESSION['oo_flash'], $_SESSION['oo_flash_err']); }
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
    $q = $pdo->prepare('SELECT admin_name, note, created_at FROM order_notes WHERE order_id = ? ORDER BY id DESC'); $q->execute([(int) $o['id']]);
    $notes = $q->fetchAll();
    ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid #17a2a2"><h3 style="margin:0 0 8px">🚚 Delivery tracking</h3>
  <form method="post" action="order_ops.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;align-items:end"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="kind" value="tracking">
    <input name="courier" value="<?= admin_h($ex['tracking']['courier']) ?>" placeholder="Courier (e.g. Sendy)"><input name="number" value="<?= admin_h($ex['tracking']['number']) ?>" placeholder="Tracking number"><input name="note" value="<?= admin_h($ex['tracking']['note']) ?>" placeholder="Note (optional)"><button class="btn">Save and email customer</button></form></div>
<div class="card" style="margin-bottom:16px;border-left:5px solid #b83b3b"><h3 style="margin:0 0 4px">↩️ Refunds <?php if ($ex['refunded'] > 0): ?><span class="muted">(<?= CURRENCY . number_format($ex['refunded'], 2) ?> refunded)</span><?php endif; ?></h3>
  <p class="muted" style="margin:0 0 8px">Records a refund you paid out yourself (M-Pesa, bank, cash) and emails the customer.</p>
  <?php foreach ($ex['refunds'] as $r): ?><div style="border-top:1px solid #e8eeea;padding:6px 0"><b><?= CURRENCY . number_format((float) $r['amount'], 2) ?></b> <?= admin_h($r['method']) ?> <span class="muted"><?= admin_h($r['reason']) ?> · <?= admin_h($r['admin_name']) ?> · <?= admin_h($r['created_at']) ?></span></div><?php endforeach; ?>
  <?php if ($o['status'] !== 'pending'): ?><form method="post" action="order_ops.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end;margin-top:8px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="kind" value="refund">
    <input name="amount" type="number" step="0.01" min="0.01" required placeholder="Amount"><input name="method" placeholder="Method (M-Pesa, bank)"><input name="reason" placeholder="Reason"><button class="btn red" onclick="return confirm('Record this refund and email the customer?')">Record refund</button></form><?php endif; ?></div>
<div class="card" style="margin-bottom:16px;border-left:5px solid #7c6fd0">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h3 style="margin:0">📝 Internal notes</h3><a class="btn gray" target="_blank" href="invoice.php?id=<?= (int) $o['id'] ?>">🖨️ Print invoice</a></div>
  <p class="muted" style="margin:6px 0 10px">Only admins see these. Customers and sellers do not.</p>
  <?php foreach ($notes as $n): ?><div style="border-top:1px solid #e8eeea;padding:8px 0"><?= nl2br(admin_h($n['note'])) ?><div class="muted"><?= admin_h($n['admin_name']) ?> · <?= admin_h($n['created_at']) ?></div></div><?php endforeach; ?>
  <form method="post" action="order_note.php" style="display:grid;gap:8px;margin-top:10px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
    <textarea name="note" rows="2" required placeholder="Add a note (e.g. called the customer)" style="width:100%;box-sizing:border-box"></textarea><div><button class="btn">Add note</button></div></form>
</div>
<?php
}
