<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/order_ops.php';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $to = (string) ($_POST['to'] ?? '');
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    if (!$ids) $msg = 'Tick at least one order first.';
    elseif (!in_array($to, ['approved', 'shipped', 'delivered'], true)) $msg = 'Choose what to do with them.';
    else { [$ok, $skip] = oo_bulk_status($pdo, (int) ($_SESSION['admin_id'] ?? 0), $ids, $to); $msg = $ok . ' order(s) marked ' . $to . ($skip ? ', ' . $skip . ' skipped (cancelled or already past this step)' : '') . '.'; }
}
$show = in_array($_GET['status'] ?? 'pending', ['pending', 'approved', 'paid', 'shipped'], true) ? ($_GET['status'] ?? 'pending') : 'pending';
$q = $pdo->prepare("SELECT o.id, o.total, o.status, o.created_at, COALESCE(o.guest_name, u.name, 'Guest') customer FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.status = ? ORDER BY o.id DESC LIMIT 100");
$q->execute([$show]);
$rows = $q->fetchAll();
$page_title = 'Bulk order update';
require 'includes/header.php';
?>
<?php if ($msg !== ''): ?><div class="notice"><?= admin_h($msg) ?></div><?php endif; ?>
<div class="card" style="margin-bottom:14px"><h3 style="margin:0 0 6px">✅ Update several orders at once</h3>
  <p class="muted" style="margin:0 0 10px">Tick orders, then choose a step. Orders only move forward; cancelled orders are never changed.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'shipped' => 'Shipped'] as $k => $lab): ?><a class="btn <?= $show === $k ? '' : 'gray' ?>" href="orders_bulk.php?status=<?= $k ?>"><?= $lab ?></a><?php endforeach; ?></div></div>
<form method="post" class="card"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
  <label style="display:flex;gap:8px;align-items:center;margin-bottom:8px"><input type="checkbox" onclick="document.querySelectorAll('.bk').forEach(function(c){c.checked=this.checked}.bind(this))"> <b>Select all (<?= count($rows) ?>)</b></label>
  <?php foreach ($rows as $r): ?><label style="display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid #eef1ef"><input class="bk" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>"><span style="flex:1"><b>#<?= (int) $r['id'] ?></b> · <?= admin_h($r['customer']) ?> <span class="muted"><?= admin_h($r['created_at']) ?></span></span><b><?= CURRENCY . number_format((float) $r['total'], 2) ?></b><a href="order_view.php?id=<?= (int) $r['id'] ?>">Open</a></label><?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">No <?= admin_h($show) ?> orders.</p><?php endif; ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><button class="btn" name="to" value="approved">Approve</button><button class="btn" name="to" value="shipped">Mark shipped</button><button class="btn green" name="to" value="delivered">Mark delivered</button><button type="button" class="btn gray" onclick="var v=[].map.call(document.querySelectorAll('.bk:checked'),function(c){return c.value}); if(!v.length){alert('Tick at least one order first.');return;} window.open('invoice.php?ids='+v.join(','),'_blank')">🖨️ Print invoices</button></div></form>
<?php require 'includes/footer.php'; ?>
