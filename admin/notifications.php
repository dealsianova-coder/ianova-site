<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/seller_ops.php';
ops_ensure($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    if (($_POST['do'] ?? '') === 'read_all') {
        $pdo->exec('UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL');
    } elseif (($_POST['do'] ?? '') === 'read') {
        $pdo->prepare('UPDATE admin_notifications SET read_at = NOW() WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    }
    header('Location: notifications.php');
    exit;
}

$rows = $pdo->query('SELECT * FROM admin_notifications ORDER BY id DESC LIMIT 100')->fetchAll();
$page_title = 'Alerts';
require 'includes/header.php';
?>
<div class="card">
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
<h2 style="margin:0">Alerts</h2>
<form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="read_all"><button class="btn gray" type="submit">Mark all read</button></form>
</div>
<?php if (!$rows): ?><p class="muted">Nothing yet. You will see seller review requests and order approvals here.</p><?php else: ?>
<div class="table-wrap"><table class="table">
<tr><th>When</th><th>Alert</th><th></th></tr>
<?php foreach ($rows as $r): $unread = $r['read_at'] === null; ?>
<tr style="<?= $unread ? 'background:#fff8e6' : '' ?>">
<td style="white-space:nowrap"><?= admin_h($r['created_at']) ?></td>
<td><b><?= admin_h($r['title']) ?></b><?php if (!empty($r['body'])): ?><br><span class="muted"><?= admin_h($r['body']) ?></span><?php endif; ?>
<?php if (!empty($r['link'])): ?><br><a href="<?= admin_h($r['link']) ?>" style="text-decoration:underline">Open</a><?php endif; ?></td>
<td><?php if ($unread): ?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="read"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn gray" type="submit">Mark read</button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
</div>
<?php require 'includes/footer.php';
