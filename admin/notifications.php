<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/seller_ops.php';
ops_ensure($pdo);

// Two inboxes: "main" = IANOVA itself (new orders, customers, store); "sellers" = anything about a seller.
$tab = (($_GET['tab'] ?? $_POST['tab'] ?? 'main') === 'sellers') ? 'sellers' : 'main';
$scope = $tab === 'sellers' ? 'seller_id IS NOT NULL' : 'seller_id IS NULL';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    if (($_POST['do'] ?? '') === 'auto') {
        ops_setting_set($pdo, 'auto_approve', ($_POST['on'] ?? '') === '1' ? '1' : '0');
    } elseif (($_POST['do'] ?? '') === 'read_all') {
        $pdo->exec("UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL AND $scope");
    } elseif (($_POST['do'] ?? '') === 'read') {
        $pdo->prepare('UPDATE admin_notifications SET read_at = NOW() WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    }
    header('Location: notifications.php?tab=' . $tab);
    exit;
}

$unread = [
    'main' => (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL AND seller_id IS NULL')->fetchColumn(),
    'sellers' => (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL AND seller_id IS NOT NULL')->fetchColumn(),
];
$rows = $pdo->query(
    "SELECT n.*, COALESCE(s.business_name, '') AS seller_name
     FROM admin_notifications n LEFT JOIN sellers s ON s.id = n.seller_id
     WHERE n.$scope ORDER BY n.id DESC LIMIT 100"
)->fetchAll();
$autoOn = ops_setting($pdo, 'auto_approve', '1') === '1';
$icons = ['new_order' => '🛒', 'auto_approved' => '✅', 'order_approved' => '✅', 'needs_approval' => '⏳', 'review_request' => '🔎'];
$page_title = 'Alerts';
require 'includes/header.php';
?>
<style>
.ntabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.ntab{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:999px;background:#fff;border:1px solid #dce4df;font-weight:700}
.ntab.on{background:#17352c;color:#fff;border-color:#17352c}
.ntab b{background:#d93636;color:#fff;border-radius:999px;padding:1px 8px;font-size:12px}
.nlist{display:grid;gap:10px}
.nitem{display:flex;gap:12px;align-items:flex-start;background:#fff;border:1px solid #dce4df;border-radius:14px;padding:14px}
.nitem.unread{background:#fff8e6;border-color:#f0d58a}
.nico{font-size:24px;line-height:1}
.nbody{flex:1;min-width:0}
.nbody .t{font-weight:700}
.nwho{display:inline-block;background:#e7f0eb;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:700;margin-bottom:4px}
</style>
<div class="ntabs">
<a class="ntab <?= $tab === 'main' ? 'on' : '' ?>" href="notifications.php?tab=main">🏬 IANOVA<?php if ($unread['main']): ?> <b><?= $unread['main'] ?></b><?php endif; ?></a>
<a class="ntab <?= $tab === 'sellers' ? 'on' : '' ?>" href="notifications.php?tab=sellers">🧑‍💼 Sellers<?php if ($unread['sellers']): ?> <b><?= $unread['sellers'] ?></b><?php endif; ?></a>
</div>
<div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
<div style="min-width:220px;flex:1"><b>Automatic order approval: <span style="color:<?= $autoOn ? '#1d6b52' : '#b83b3b' ?>"><?= $autoOn ? 'ON' : 'OFF' ?></span></b>
<div class="muted"><?= $autoOn ? 'When a seller\'s products are in stock, their part of a new order is approved for them.' : 'Every seller has to approve each new order by hand.' ?></div></div>
<form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="auto"><input type="hidden" name="tab" value="<?= $tab ?>"><input type="hidden" name="on" value="<?= $autoOn ? '0' : '1' ?>"><button class="btn <?= $autoOn ? 'red' : '' ?>" type="submit"><?= $autoOn ? 'Switch to manual' : 'Switch on automatic' ?></button></form>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
<div class="muted"><?= $tab === 'sellers' ? 'Seller orders approved automatically, orders waiting on a seller, and review requests.' : 'New orders and everything about the IANOVA store itself.' ?></div>
<form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="read_all"><input type="hidden" name="tab" value="<?= $tab ?>"><button class="btn gray" type="submit">Mark all read</button></form>
</div>
<?php if (!$rows): ?><div class="card"><p class="muted" style="margin:0">Nothing here yet.</p></div><?php else: ?>
<div class="nlist">
<?php foreach ($rows as $r): $isNew = $r['read_at'] === null; ?>
<div class="nitem <?= $isNew ? 'unread' : '' ?>">
<div class="nico"><?= $icons[$r['kind']] ?? '🔔' ?></div>
<div class="nbody">
<?php if ($r['seller_name'] !== ''): ?><span class="nwho"><?= admin_h($r['seller_name']) ?></span><br><?php endif; ?>
<span class="t"><?= admin_h($r['title']) ?></span>
<?php if (!empty($r['body'])): ?><br><span class="muted"><?= admin_h($r['body']) ?></span><?php endif; ?>
<br><span class="muted"><?= admin_h($r['created_at']) ?></span>
<?php if (!empty($r['link'])): ?> &nbsp;<a href="<?= admin_h($r['link']) ?>" style="text-decoration:underline;font-weight:700">Open</a><?php endif; ?>
</div>
<?php if ($isNew): ?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="read"><input type="hidden" name="tab" value="<?= $tab ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn gray" type="submit">Read</button></form><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require 'includes/footer.php';
