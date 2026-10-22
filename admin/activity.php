<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_team.php';
team_ensure($pdo);
$meId = (int) ($_SESSION['admin_id'] ?? 0);
if (!team_is_master($pdo, $meId)) { http_response_code(403); exit('Only the master admin can see the activity log.'); }
$who = (int) ($_GET['admin'] ?? 0);
$rows = team_log_list($pdo, 300, $who);
$admins = team_list($pdo);
$page_title = 'Activity';
require 'includes/header.php';
?>
<h2 style="margin:0 0 4px">🧾 Activity</h2><p class="muted" style="margin-top:0">Who did what in the admin and the Admin app (last 180 days).</p>
<div class="actions" style="margin-bottom:14px"><a class="btn <?= $who ? 'gray' : '' ?>" href="activity.php">Everyone</a>
<?php foreach ($admins as $a): ?><a class="btn <?= $who === (int) $a['id'] ? '' : 'gray' ?>" href="activity.php?admin=<?= (int) $a['id'] ?>"><?= admin_h($a['name']) ?></a><?php endforeach; ?></div>
<div class="table-wrap" style="overflow-x:auto"><table class="table" style="min-width:560px"><tr><th>When</th><th>Admin</th><th>Action</th><th>Details</th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= admin_h($r['created_at']) ?></td><td><?= admin_h($r['admin_name']) ?></td><td><b><?= admin_h($r['action']) ?></b></td><td class="muted"><?= admin_h($r['detail']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr><?php endif; ?></table></div>
<?php require 'includes/footer.php'; ?>
