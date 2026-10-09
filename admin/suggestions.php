<?php
require_once 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $id = (int) $_POST['id'];
    $pdo->prepare("UPDATE suggestions SET status = 'reviewed' WHERE id = ?")->execute([$id]);
    header('Location: suggestions.php');
    exit;
}

$rows = $pdo->query(
    'SELECT s.*, u.name, u.email FROM suggestions s
     JOIN users u ON u.id = s.user_id
     ORDER BY FIELD(s.status, "new", "reviewed"), s.created_at DESC'
)->fetchAll();

$page_title = 'Suggestions';
require 'includes/header.php';
?>
<div class="table-wrap">
<table class="table">
<tr><th>From</th><th>Message</th><th>Status</th><th>Date</th><th>Action</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<td><b><?= admin_h($r['name']) ?></b><br><small style="color:#6b7973;"><?= admin_h($r['email']) ?></small></td>
<td style="max-width:360px;white-space:pre-wrap;"><?= admin_h($r['message']) ?></td>
<td><?php if ($r['status'] === 'new'): ?><span class="pill" style="background:#fdeecb;color:#8a5a00;">New</span><?php else: ?><span class="pill">Reviewed</span><?php endif; ?></td>
<td><?= admin_h($r['created_at']) ?></td>
<td>
<?php if ($r['status'] === 'new'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<input type="hidden" name="id" value="<?= intval($r['id']) ?>">
<button class="btn">Mark reviewed</button>
</form>
<?php else: ?>
<span class="muted">—</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php require 'includes/footer.php';
