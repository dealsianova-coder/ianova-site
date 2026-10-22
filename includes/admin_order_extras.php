<?php
/** Order page extras for the website admin: internal notes + print invoice. */
function admin_order_extras(PDO $pdo, array $o): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
    $q = $pdo->prepare('SELECT admin_name, note, created_at FROM order_notes WHERE order_id = ? ORDER BY id DESC'); $q->execute([(int) $o['id']]);
    $notes = $q->fetchAll();
    ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid #7c6fd0">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h3 style="margin:0">📝 Internal notes</h3><a class="btn gray" target="_blank" href="invoice.php?id=<?= (int) $o['id'] ?>">🖨️ Print invoice</a></div>
  <p class="muted" style="margin:6px 0 10px">Only admins see these. Customers and sellers do not.</p>
  <?php foreach ($notes as $n): ?><div style="border-top:1px solid #e8eeea;padding:8px 0"><?= nl2br(admin_h($n['note'])) ?><div class="muted"><?= admin_h($n['admin_name']) ?> · <?= admin_h($n['created_at']) ?></div></div><?php endforeach; ?>
  <form method="post" action="order_note.php" style="display:grid;gap:8px;margin-top:10px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
    <textarea name="note" rows="2" required placeholder="Add a note (e.g. called the customer)" style="width:100%;box-sizing:border-box"></textarea><div><button class="btn">Add note</button></div></form>
</div>
<?php
}
