<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_team.php';
require_once __DIR__ . '/../includes/revenue.php';
rv_ensure($pdo);
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$isM = team_is_master($pdo, $meId);
$error = ''; $notice = '';
if ($isM && $_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $m = team_verify($pdo, $meId, (string) ($_POST['security'] ?? ''));
    if ($m === '') {
        if ($do === 'restore') { $m = rv_restore($pdo, (int) ($_POST['id'] ?? 0)); $ok = 'Revenue restored.'; }
        elseif ($do === 'trash_restore') { $m = rv_trash_restore($pdo, (int) ($_POST['id'] ?? 0)); $ok = 'Orders restored.'; }
        else $m = 'Unknown action.';
    }
    if ($m === '') { team_log($pdo, $meId, 'web:trash.php', 'do=' . $do); $notice = $ok ?? 'Done.'; } else $error = $m;
}
$resets = $isM ? rv_resets($pdo) : []; $trash = $isM ? rv_trash_list($pdo) : [];
$page_title = 'Trash';
require 'includes/header.php';
$m = fn($v) => CURRENCY . number_format((float) $v, 2);
?>
<link rel="stylesheet" href="../assets/css/admin-dash-gold.css?v=4">
<style>.rvr{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid #e8eeea;align-items:center}.rvr:first-of-type{border-top:0}.rvr input{padding:7px 9px;border:1px solid #dce4df;border-radius:9px}</style>
<?php if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= admin_h($error) ?></div><?php endif; ?>
<div class="wel"><div><h1 style="margin:0">🗑️ Trash</h1><p>Deleted orders and cleared revenue stay here for <?= RV_HOLD_DAYS ?> days. After that they are permanently deleted.</p></div></div>
<?php if (!$isM): ?><div class="pan"><p class="muted">Only the master admin can open the trash.</p></div><?php else: ?>
<div class="pan"><h2>🛒 Deleted orders</h2>
  <?php foreach ($trash as $t): ?><div class="rvr"><span><b><?= (int) $t['n'] ?> orders · <?= $m($t['total']) ?></b><br><span class="muted">Deleted <?= admin_h(substr($t['created_at'], 0, 16)) ?> by <?= admin_h($t['admin']) ?> · <?= $t['can_restore'] ? (int) $t['days_left'] . ' days left to restore' : ($t['restored_at'] ? 'Restored' : 'Permanently deleted') ?></span></span>
    <?php if ($t['can_restore']): ?><form method="post" onsubmit="return confirm('Bring these orders back?')" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="trash_restore"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="password" name="security" placeholder="🔒 Security password" required><button class="btn gray" type="submit">Restore</button></form><?php endif; ?></div><?php endforeach; ?>
  <?php if (!$trash): ?><p class="muted">Nothing here.</p><?php endif; ?></div>
<div class="pan"><h2>💰 Cleared revenue</h2>
  <?php foreach ($resets as $r): ?><div class="rvr"><span><b><?= $r['confirmed'] === null ? 'Revenue archive' : $m($r['confirmed']) . ' from ' . (int) $r['orders'] . ' orders' ?></b><br><span class="muted">Cleared <?= admin_h(substr($r['reset_at'], 0, 16)) ?> by <?= admin_h($r['admin']) ?><?= $r['label'] ? ' · ' . admin_h($r['label']) : '' ?> · <?= $r['can_restore'] ? (int) $r['days_left'] . ' days left to restore' : ($r['restored_at'] ? 'Restored' : ($r['purged_at'] ? 'Permanently deleted' : 'Restore the newest clear first')) ?></span></span>
    <?php if ($r['can_restore']): ?><form method="post" onsubmit="return confirm('Restore the old revenue?')" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="password" name="security" placeholder="🔒 Security password" required><button class="btn gray" type="submit">Restore</button></form><?php endif; ?></div><?php endforeach; ?>
  <?php if (!$resets): ?><p class="muted">Nothing here.</p><?php endif; ?></div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
