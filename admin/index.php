<?php
$page_title = 'Dashboard';
require 'includes/header.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';

/** Safe query helpers: the dashboard must never break if an optional table is missing. */
function dsh_rows(PDO $pdo, string $sql, array $par = []): array { try { $q = $pdo->prepare($sql); $q->execute($par); return $q->fetchAll(); } catch (Throwable $e) { return []; } }
function dsh_val(PDO $pdo, string $sql, array $par = []) { try { $q = $pdo->prepare($sql); $q->execute($par); $v = $q->fetchColumn(); return $v === false ? 0 : $v; } catch (Throwable $e) { return 0; } }

$nProducts = (int) dsh_val($pdo, 'SELECT COUNT(*) FROM products');
$nCats = (int) dsh_val($pdo, 'SELECT COUNT(*) FROM categories');
$nOrders = (int) dsh_val($pdo, 'SELECT COUNT(*) FROM orders');
$nUsers = (int) dsh_val($pdo, 'SELECT COUNT(*) FROM users');
$nSellers = (int) dsh_val($pdo, 'SELECT COUNT(*) FROM sellers');
require_once __DIR__ . '/../includes/revenue.php'; $revenue = rv_total($pdo);
$pendingOrders = (int) dsh_val($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'");
$reviewProducts = (int) dsh_val($pdo, "SELECT COUNT(*) FROM products WHERE status = 'pending'");
$lowStock = (int) dsh_val($pdo, "SELECT COUNT(*) FROM products WHERE status = 'approved' AND stock <= 3");
$pendingSellers = (int) dsh_val($pdo, "SELECT COUNT(*) FROM sellers WHERE status = 'pending'");
$unreadMsgs = (int) dsh_val($pdo, "SELECT COUNT(*) FROM seller_messages WHERE sender = 'seller' AND read_at IS NULL");
$alerts = (int) dsh_val($pdo, 'SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL');

$panels = [];
foreach (['main' => ['🏠 IANOVA orders', 'Sold directly by IANOVA'], 'sellers' => ['🏪 Seller orders', 'Sold by sellers']] as $scope => $meta) {
    [$sql, $par] = aou_scope_sql($scope);
    $q = $pdo->prepare("SELECT o.id, COALESCE(o.guest_name, u.name, 'Guest') customer, o.total, o.status, o.created_at FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE 1=1" . $sql . ' ORDER BY o.id DESC LIMIT ' . ($scope === 'sellers' ? 40 : 5));
    $q->execute($par);
    $rows = $q->fetchAll();
    $items = aou_items($pdo, array_column($rows, 'id'));
    $appr = aou_approvals($pdo, array_column($rows, 'id'));
    $waiting = 0;
    foreach ($rows as $r) { foreach ($appr[(int) $r['id']] ?? [] as $a) if (!$a['at']) { $waiting++; break; } }
    $panels[$scope] = ['title' => $meta[0], 'sub' => $meta[1], 'rows' => $rows, 'items' => $items, 'appr' => $appr, 'count' => aou_scope_count($pdo, $scope), 'waiting' => $waiting];
}
$sellerGroups = aou_group_by_seller($panels['sellers']['rows'], $panels['sellers']['items']);
$sellerCounts = aou_seller_counts($pdo);

$recentProducts = dsh_rows($pdo, "SELECT p.id, p.name, p.price, p.stock, p.status, p.image, p.emoji, p.bg_color, COALESCE(s.business_name, p.seller_name, 'IANOVA') seller FROM products p LEFT JOIN sellers s ON s.id = p.seller_id ORDER BY p.id DESC LIMIT 6");
$lowItems = dsh_rows($pdo, "SELECT id, name, stock, image, emoji, bg_color FROM products WHERE status = 'approved' AND stock <= 3 ORDER BY stock, id LIMIT 5");
$cats = dsh_rows($pdo, "SELECT c.id, c.name, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) n FROM categories c ORDER BY n DESC, c.name LIMIT 12");
$maxCat = 1; foreach ($cats as $c0) $maxCat = max($maxCat, (int) $c0['n']);
$sellerRows = dsh_rows($pdo, "SELECT s.id, s.business_name, s.status, (SELECT COUNT(*) FROM products p WHERE p.seller_id = s.id) products FROM sellers s ORDER BY (s.status = 'pending') DESC, s.id DESC LIMIT 6");
$sellerOrderCounts = aou_seller_counts($pdo);
$userRows = dsh_rows($pdo, 'SELECT id, name, email FROM users ORDER BY id DESC LIMIT 5');
$sellerGroups = aou_group_by_seller($panels['sellers']['rows'], $panels['sellers']['items']);
$sellerCounts = $sellerOrderCounts;

$tiles = [
    ['🛒', 'Orders', $nOrders, 'orders.php', $pendingOrders ? $pendingOrders . ' pending' : ''],
    ['📦', 'Products', $nProducts, 'products.php', $reviewProducts ? $reviewProducts . ' to review' : ''],
    ['🏷️', 'Categories', $nCats, 'categories.php', ''],
    ['🏪', 'Sellers', $nSellers, 'sellers.php', $pendingSellers ? $pendingSellers . ' to approve' : ''],
    ['👥', 'Users', $nUsers, 'users.php', ''],
    ['💰', 'Confirmed revenue', CURRENCY . number_format($revenue, 2), 'orders.php', ''],
];
$attn = [
    ['🛒', 'Pending orders', $pendingOrders, 'orders.php?status=pending'],
    ['⏳', 'Products to review', $reviewProducts, 'products.php?status=pending'],
    ['🏪', 'Sellers to approve', $pendingSellers, 'sellers.php'],
    ['💬', 'Unread seller messages', $unreadMsgs, 'seller_messages.php'],
    ['🔔', 'Unread alerts', $alerts, 'notifications.php'],
    ['⚠️', 'Low stock (3 or less)', $lowStock, 'products.php'],
    ['⏰', 'Orders waiting over 24 hours', (int) ((require_once __DIR__ . '/../includes/attention.php') ? ($at_ = attn_counts($pdo))['old_pending'] : 0), 'orders.php?status=pending'],
    ['🚚', 'Shipped over 7 days, not delivered', (int) $at_['stuck_shipped'], 'orders.php?status=shipped'],
    ['💳', 'Orders with a balance to collect', (int) $at_['to_collect'], 'payments.php'],
    ['💸', 'Sellers waiting for a payout', (int) $at_['payouts_due'], 'payouts.php'],
    ['💾', 'No backup in the last 3 days', (int) $at_['backup_old'], 'index.php'],
];
$attnTotal = 0; foreach ($attn as $a0) $attnTotal += (int) $a0[2];
$tiles[5] = ['🔔', 'Needs attention', (int) $attnTotal, 'orders.php?status=pending', $attnTotal ? 'open orders' : 'all clear'];
$stLabel = ['approved' => 'Approved', 'pending' => 'Review', 'rejected' => 'Rejected', 'hidden' => 'Hidden'];
echo aou_css();
?>
<link rel="stylesheet" href="../assets/css/admin-dash-gold.css?v=4">
<style>
html{scroll-behavior:smooth}
.dnav{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:16px}.dnav a{flex:none;padding:8px 14px;border-radius:999px;background:#fff;border:1px solid #dce4df;font-weight:700;font-size:13px}.dnav a:hover{background:#1d6b52;color:#fff;border-color:#1d6b52}
.tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.tile{display:block;background:#fff;border:1px solid #dce4df;border-radius:16px;padding:16px;color:inherit;box-shadow:0 3px 14px rgba(20,45,35,.04)}.tile:hover{border-color:#1d6b52}.tile .ic{font-size:22px}.tile .lb{color:#6b7973;font-size:13px;margin-top:6px}.tile .vl{font-size:26px;font-weight:800;margin-top:2px;word-break:break-word}.tile .sb{display:inline-block;margin-top:6px;font-size:12px;font-weight:700;color:#92600a;background:#fff3d6;border-radius:999px;padding:3px 8px}
.attn{background:#fff;border:1px solid #dce4df;border-radius:16px;padding:16px;margin-bottom:18px}.attn h2{margin:0 0 10px;font-size:18px}.agrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.ai{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;background:#f7f9f8;color:inherit}.ai.hot{background:#fff3d6}.ai b{margin-left:auto;font-size:18px}
.sec{margin-top:26px}.sec>h2{margin:0 0 12px;font-size:20px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.sec>h2 a{font-size:13px}
.plist{display:flex;flex-direction:column;gap:8px}.pr{display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #e8eeea;border-radius:12px;color:inherit}.pr:hover{border-color:#1d6b52}.pr .m{flex:1;min-width:0}.pr .m b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pr .m small{color:#6b7973}.pr .r{text-align:right;font-size:13px}
.bar{height:8px;border-radius:999px;background:#e8eeea;overflow:hidden;margin-top:6px}.bar i{display:block;height:100%;background:#1d6b52}.cg{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.cc{display:block;padding:12px;border:1px solid #e8eeea;border-radius:12px;color:inherit}.cc:hover{border-color:#1d6b52}
.qa{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px}.three{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
@media(max-width:900px){.tiles,.agrid,.cg{grid-template-columns:repeat(2,minmax(0,1fr))}.three{grid-template-columns:1fr}}
@media(max-width:460px){.tiles,.agrid,.cg{grid-template-columns:1fr 1fr}.tile .vl{font-size:21px}}
.rcent{display:flex;flex-direction:column;gap:10px}.rc{display:block;border:1px solid #dce4df;border-radius:14px;padding:12px;background:#fff;color:inherit}.rc:hover{border-color:#1d6b52}.rc-h{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px}.rc-t{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.rc-f{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap}.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:900px){.two{grid-template-columns:1fr}}
</style>

<div class="dnav"><a href="#overview">Overview</a><a href="#orders">Orders</a><a href="#products">Products</a><a href="#categories">Categories</a><a href="#sellers">Sellers</a><a href="#users">Users</a></div>

<?php /* iad-v2: dashboard example layout (customisable, hide revenue, grouped attention) */
if (is_file(__DIR__ . '/../includes/dash_prefs.php')) require_once __DIR__ . '/../includes/dash_prefs.php';
$iad_p = function_exists('dpf_get') ? dpf_get($pdo, (int) ($_SESSION['admin_id'] ?? 0)) : ['hide' => [], 'quick' => ['add_product', 'orders', 'sellers', 'settings']];
$iad_show = fn(string $id) => !in_array($id, $iad_p['hide'], true);
$iad_since = function_exists('rv_since_and') ? rv_since_and($pdo) : '';
$iad_n = (int) dsh_val($pdo, 'SELECT COUNT(*) FROM orders WHERE 1=1' . $iad_since);
$iad_rev = "status IN ('paid','shipped','delivered')" . (function_exists('rv_since') && rv_since($pdo) !== '' ? ' AND created_at >= ' . $pdo->quote(rv_since($pdo)) : '');
$iad_by = []; foreach (dsh_rows($pdo, "SELECT DATE(created_at) d, COALESCE(SUM(total),0) t FROM orders WHERE $iad_rev AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)") as $r0) $iad_by[$r0['d']] = (float) $r0['t'];
$iad_s = ['7' => [], '30' => []];
for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $pt = [date('d/m', strtotime($d)), $iad_by[$d] ?? 0.0]; $iad_s['30'][] = $pt; if ($i < 7) $iad_s['7'][] = $pt; }
$iad_tr = function (string $tbl, string $sum = 'COUNT(*)', string $w = '1=1') use ($pdo) {
    $a = (float) dsh_val($pdo, "SELECT COALESCE($sum,0) FROM $tbl WHERE $w AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $b = (float) dsh_val($pdo, "SELECT COALESCE($sum,0) FROM $tbl WHERE $w AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($b <= 0) return $a > 0 ? 100.0 : null;
    return round(($a - $b) / $b * 100, 1);
};
$iad_k = [
    ['k1', '💰', 'Total Revenue', CURRENCY . number_format($revenue, 2), $iad_tr('orders', 'SUM(total)', $iad_rev), 'revenue.php'],
    ['k2', '🛒', 'Total Orders', number_format($iad_n), $iad_tr('orders', 'COUNT(*)', '1=1' . $iad_since), 'orders.php'],
    ['k3', '📦', 'Total Products', number_format((int) $nProducts), $iad_tr('products'), 'products.php'],
    ['k4', '👥', 'Total Customers', number_format((int) $nUsers), $iad_tr('users'), 'users.php'],
];
$iad_latest = dsh_rows($pdo, "SELECT o.id, COALESCE(o.guest_name, u.name, 'Guest') customer, o.total, o.status, o.created_at FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE 1=1" . ($iad_since !== '' ? str_replace('created_at', 'o.created_at', $iad_since) : '') . " ORDER BY o.id DESC LIMIT 6");
$iad_groups = ['🛒 Orders' => [0, 6, 7], '📦 Products & stock' => [1, 5], '🏪 Sellers' => [2, 3, 9], '💰 Money' => [8], '⚙️ System' => [4, 10]];
$iad_clear = [];
$iad_hidden_css = '';
foreach (['orders', 'products', 'categories', 'sellers', 'users'] as $iad_k2) if (!$iad_show('more_' . $iad_k2)) $iad_hidden_css .= '#' . $iad_k2 . '.sec,.dnav a[href="#' . $iad_k2 . '"]{display:none}';
function iad_svg(array $pts): string {
    $W = 800; $H = 220; $L = 46; $B = 26; $T = 10; $R = 12; $w = $W - $L - $R; $h = $H - $B - $T;
    $max = 0.0; foreach ($pts as $p) $max = max($max, $p[1]); if ($max <= 0) $max = 1;
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none">';
    for ($g = 0; $g <= 4; $g++) { $y = $T + $h - $h * $g / 4; $v = $max * $g / 4; $t = $v >= 1000 ? round($v / 1000, $v >= 10000 ? 0 : 1) . 'K' : round($v);
        $o .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y . '" y2="' . $y . '" stroke="#e6e9e7"/><text x="' . ($L - 6) . '" y="' . ($y + 4) . '" font-size="11" fill="#6b7973" text-anchor="end">' . $t . '</text>'; }
    $n = count($pts); $xy = []; $line = '';
    foreach ($pts as $i => $p) { $x = $L + ($n > 1 ? $w * $i / ($n - 1) : $w / 2); $y = $T + $h - $h * ($p[1] / $max); $xy[] = [$x, $y]; $line .= ($i ? ' L' : 'M') . round($x, 1) . ' ' . round($y, 1); }
    $o .= '<defs><linearGradient id="gf" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e4b43c" stop-opacity=".35"/><stop offset="1" stop-color="#e4b43c" stop-opacity=".02"/></linearGradient></defs>';
    $o .= '<path d="' . $line . ' L' . end($xy)[0] . ' ' . ($T + $h) . ' L' . $xy[0][0] . ' ' . ($T + $h) . ' Z" fill="url(#gf)"/><path d="' . $line . '" fill="none" stroke="#e4b43c" stroke-width="3" stroke-linejoin="round"/>';
    $step = $n > 10 ? 5 : 1;
    foreach ($pts as $i => $p) { if ($n <= 10 || $i == $n - 1) $o .= '<circle cx="' . round($xy[$i][0], 1) . '" cy="' . round($xy[$i][1], 1) . '" r="4" fill="#e4b43c"/>';
        if ($i % $step == 0 || $i == $n - 1) $o .= '<text x="' . round($xy[$i][0], 1) . '" y="' . ($H - 6) . '" font-size="11" fill="#6b7973" text-anchor="middle">' . htmlspecialchars($p[0]) . '</text>'; }
    return $o . '</svg>';
}
?>
<?php if ($iad_hidden_css !== ''): ?><style><?= $iad_hidden_css ?></style><?php endif; ?>
<div class="wel"><div><?php if ($iad_show('welcome')): ?><h1>Welcome back, <?= admin_h(trim((string) ($_SESSION['admin_name'] ?? '')) ?: 'Admin') ?>!</h1><p>Here's what's happening with your store today.</p><?php else: ?><h1>Dashboard</h1><?php endif; ?></div><div class="dt"><?php if ($iad_show('welcome')): ?>📅 <?= date('D, M j, Y') ?><small><?= date('h:i A') ?></small><?php endif; ?></div></div>
<div class="kpis"><?php foreach ($iad_k as $k): $t = $k[4]; $id = ['k1' => 'kpi_revenue', 'k2' => 'kpi_orders', 'k3' => 'kpi_products', 'k4' => 'kpi_customers'][$k[0]]; if (!$iad_show($id)) continue; $isRev = $k[0] === 'k1'; ?>
  <a class="kpi <?= $k[0] ?>" href="<?= $k[5] ?>"><span class="ki"><?= $k[1] ?></span><span><span class="kl"><?= $k[2] ?><?php if ($isRev): ?> <button type="button" class="eye" title="Hide or show revenue">👁️</button><?php endif; ?></span><span class="kv<?= $isRev ? ' rvv' : '' ?>" style="display:block" data-v="<?= admin_h($k[3]) ?>"><?= $k[3] ?></span><span class="kt <?= $t === null ? '' : ($t >= 0 ? 'up' : 'dn') ?>"><?= $t === null ? 'no change data' : (($t >= 0 ? '↑ ' : '↓ ') . abs($t) . '%') ?><?php if ($t !== null): ?><small>vs last 7 days</small><?php endif; ?></span></span></a>
<?php endforeach; ?></div>
<?php if ($iad_show('sales')): ?>
<div class="pan"><div class="ph"><div><h2>Sales Overview</h2><div class="sub">Revenue trend (<span id="iadr">last 7 days</span>)</div></div><div class="rng"><a class="on" data-r="7">7 Days</a><a data-r="30">30 Days</a></div></div>
  <div class="chart" id="iadc7"><?= iad_svg($iad_s['7']) ?></div><div class="chart" id="iadc30" style="display:none"><?= iad_svg($iad_s['30']) ?></div></div>
<script>document.querySelectorAll('.rng a').forEach(function(a){a.onclick=function(){document.querySelectorAll('.rng a').forEach(function(x){x.classList.remove('on')});a.classList.add('on');var r=a.dataset.r;document.getElementById('iadc7').style.display=r==='7'?'':'none';document.getElementById('iadc30').style.display=r==='30'?'':'none';document.getElementById('iadr').textContent='last '+r+' days'}});</script>
<?php endif; ?>
<script>(function(){var k='iad_hide_rev',h=false;try{h=localStorage.getItem(k)==='1'}catch(e){}function ap(){document.querySelectorAll('.rvv').forEach(function(e){e.textContent=h?'KSh ••••••':e.getAttribute('data-v')});document.querySelectorAll('.eye').forEach(function(b){b.textContent=h?'🙈':'👁️'})}document.querySelectorAll('.eye').forEach(function(b){b.onclick=function(ev){ev.preventDefault();ev.stopPropagation();h=!h;try{localStorage.setItem(k,h?'1':'0')}catch(e){}ap()}});ap()})();</script>
<?php if ($iad_show('quick')): ?>
<div class="pan"><h2>Quick Actions</h2><div class="sub">Manage your store with ease.</div><div class="qg">
<?php foreach ($iad_p['quick'] as $qi => $qid): if (!isset(DPF_QUICK[$qid])) continue; $qd = DPF_QUICK[$qid]; ?>
  <a class="qc q<?= ($qi % 4) + 1 ?>" href="<?= admin_h($qd[4]) ?>"><span class="qi"><?= $qd[2] ?></span><span><b><?= admin_h($qd[0]) ?></b><small><?= admin_h($qd[1]) ?></small></span></a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>
<?php if ($iad_show('recent')): ?>
<div class="pan"><div class="ph"><div><h2>Recent Orders</h2><div class="sub">Latest orders from your customers</div></div><a href="orders.php" style="font-weight:800">View all ›</a></div>
  <div class="rwrap"><table class="rot"><tr><th>#</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr>
  <?php foreach ($iad_latest as $o): ?><tr><td>#IAN-<?= str_pad((string) (int) $o['id'], 4, '0', STR_PAD_LEFT) ?></td><td><?= admin_h($o['customer']) ?></td><td><?= CURRENCY . number_format((float) $o['total'], 2) ?></td><td><span class="st <?= admin_h($o['status']) ?>"><?= admin_h(ucfirst((string) $o['status'])) ?></span></td><td><?= admin_h(substr((string) $o['created_at'], 0, 10)) ?></td><td><a href="order_view.php?id=<?= (int) $o['id'] ?>" style="font-weight:800">View ›</a></td></tr><?php endforeach; ?>
  <?php if (!$iad_latest): ?><tr><td colspan="6" class="muted">No orders yet.</td></tr><?php endif; ?></table></div></div>
<?php endif; ?>
<?php if ($iad_show('attention')): ?>
<div class="atg"><div class="atgh"><span><?= $attnTotal ? '👀 Needs your attention' : '✅ All caught up' ?></span><?php if ($attnTotal): ?><em><?= (int) $attnTotal ?></em><?php endif; ?></div><div class="atgb">
<?php foreach ($iad_groups as $gname => $gidx): $hot = []; foreach ($gidx as $gi) { if (!isset($attn[$gi])) continue; if ((int) $attn[$gi][2] > 0) $hot[] = $attn[$gi]; else $iad_clear[] = $attn[$gi]; } if (!$hot) continue; ?>
  <div class="agt"><?= admin_h($gname) ?></div><div class="agrid"><?php foreach ($hot as $a0): ?><a class="ai hot" href="<?= $a0[3] ?>"><span><?= $a0[0] ?></span><span><?= admin_h($a0[1]) ?></span><b><?= (int) $a0[2] ?></b></a><?php endforeach; ?></div>
<?php endforeach; ?>
<?php if (!$attnTotal): ?><p class="muted" style="margin:6px 0">Nothing needs your action right now. 🎉</p><?php endif; ?>
<?php if ($iad_clear): ?><details style="margin-top:10px"><summary style="cursor:pointer;font-weight:800;color:#6b7973"><?= count($iad_clear) ?> clear items</summary><div class="agrid" style="margin-top:8px"><?php foreach ($iad_clear as $a0): ?><a class="ai" href="<?= $a0[3] ?>"><span><?= $a0[0] ?></span><span><?= admin_h($a0[1]) ?></span><b><?= (int) $a0[2] ?></b></a><?php endforeach; ?></div></details><?php endif; ?>
</div></div>
<?php endif; ?>
<?php /* iad-v2-end */ ?>
<div id="overview" class="tiles">
<?php foreach ($tiles as $t): ?>
  <a class="tile" href="<?= $t[3] ?>"><div class="ic"><?= $t[0] ?></div><div class="lb"><?= admin_h($t[1]) ?></div><div class="vl"><?= is_int($t[2]) ? number_format($t[2]) : $t[2] ?></div><?php if ($t[4] !== ''): ?><span class="sb"><?= admin_h($t[4]) ?></span><?php endif; ?></a>
<?php endforeach; ?>
</div>

<div class="attn">
  <h2><?= $attnTotal ? '👀 Needs your attention' : '✅ All caught up' ?><?php if ($attnTotal): ?><em><?= (int) $attnTotal ?></em><?php endif; ?></h2>
  <div class="agrid"><?php foreach ($attn as $a0): ?><a class="ai<?= $a0[2] ? ' hot' : '' ?>" href="<?= $a0[3] ?>"><span><?= $a0[0] ?></span><span><?= admin_h($a0[1]) ?></span><b><?= (int) $a0[2] ?></b></a><?php endforeach; ?></div>
</div>

<div class="qa"><a class="btn" href="product_edit.php">+ Add product</a><a class="btn gray" href="categories.php">+ Category</a><a class="btn gray" href="flash.php">⚡ Flash deals</a><a class="btn gray" href="banners.php">🖼️ Banners</a><a class="btn gray" href="settings.php">⚙️ Settings</a></div>

<div class="sec" id="orders">
  <h2>🛒 Orders <a href="orders.php" style="text-decoration:underline">All orders</a></h2>
  <div class="two">
    <div class="card">
    <div class="top" style="margin-bottom:6px"><h2 style="margin:0">🏠 IANOVA orders <span class="muted">(<?= (int) $panels['main']['count'] ?>)</span></h2><a class="btn" href="orders.php?tab=main">Manage</a></div>
    <p class="muted" style="margin:0 0 10px">Sold directly by IANOVA</p>
    <?php if (!$panels['main']['rows']): ?><p class="muted">No orders yet.</p><?php endif; ?>
    <div class="rcent">
    <?php foreach ($panels['main']['rows'] as $o): $lines = aou_scope_lines($panels['main']['items'][(int) $o['id']] ?? [], 'main'); ?>
      <a class="rc" href="order_view.php?id=<?= (int) $o['id'] ?>&tab=main">
        <div class="rc-h"><b>#<?= (int) $o['id'] ?> · <?= admin_h($o['customer']) ?></b><?= aou_status_tag((string) $o['status']) ?></div>
        <div class="rc-t"><?php foreach (array_slice($lines, 0, 4) as $l) echo aou_thumb($l['image'] ?? '', $l['emoji'] ?? '', $l['bg_color'] ?? '', 44); ?></div>
        <div class="rc-f"><span></span><span><b><?= CURRENCY . number_format(aou_lines_total($lines), 2) ?></b> <span class="muted">· <?= admin_h($o['created_at']) ?></span></span></div>
      </a>
    <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="top" style="margin-bottom:6px"><h2 style="margin:0">🏪 Seller orders <span class="muted">(<?= (int) $panels['sellers']['count'] ?>)</span></h2><a class="btn" href="orders.php?tab=sellers">Manage</a></div>
    <p class="muted" style="margin:0 0 10px">One section per seller</p>
    <?php if (!$sellerGroups): ?><p class="muted">No seller orders yet.</p><?php endif; ?>
    <?php foreach ($sellerGroups as $sid => $g): $w = $sellerCounts[$sid]['waiting'] ?? 0; ?>
      <div style="margin-bottom:16px">
        <div class="rc-h" style="margin-bottom:6px"><b>🏪 <?= admin_h($g['name']) ?> <span class="muted">(<?= (int) ($sellerCounts[$sid]['orders'] ?? count($g['rows'])) ?>)</span></b><span><?= $w ? '<span class="tag wait">' . $w . ' waiting</span>' : '<span class="tag ok">all approved</span>' ?> <a href="orders.php?tab=sellers&seller=<?= (int) $sid ?>" style="text-decoration:underline;font-size:13px">open</a></span></div>
        <div class="rcent">
        <?php foreach (array_slice($g['rows'], 0, 3) as [$o, $lines]): ?>
          <a class="rc" href="order_view.php?id=<?= (int) $o['id'] ?>&tab=sellers">
            <div class="rc-h"><b>#<?= (int) $o['id'] ?> · <?= admin_h($o['customer']) ?></b><?= aou_status_tag((string) $o['status']) ?></div>
            <div class="rc-t"><?php foreach (array_slice($lines, 0, 4) as $l) echo aou_thumb($l['image'] ?? '', $l['emoji'] ?? '', $l['bg_color'] ?? '', 44); ?></div>
            <div class="rc-f"><span><?= aou_approval_tags(isset($panels['sellers']['appr'][(int) $o['id']][$sid]) ? [$sid => $panels['sellers']['appr'][(int) $o['id']][$sid]] : []) ?></span><span><b><?= CURRENCY . number_format(aou_lines_total($lines), 2) ?></b> <span class="muted">· <?= admin_h($o['created_at']) ?></span></span></div>
          </a>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  </div>
</div>

<div class="sec" id="products">
  <h2>📦 Products <a href="products.php" style="text-decoration:underline">Manage products</a></h2>
  <div class="three">
    <div class="card">
      <h3 style="margin:0 0 10px">Latest products</h3>
      <?php if (!$recentProducts): ?><p class="muted">No products yet.</p><?php endif; ?>
      <div class="plist"><?php foreach ($recentProducts as $p): ?>
        <a class="pr" href="product_view.php?id=<?= (int) $p['id'] ?>"><?= aou_thumb($p['image'] ?? '', $p['emoji'] ?? '', $p['bg_color'] ?? '', 44) ?>
          <span class="m"><b><?= admin_h($p['name']) ?></b><small><?= admin_h($p['seller']) ?> · <?= admin_h($stLabel[$p['status']] ?? $p['status']) ?></small></span>
          <span class="r"><b><?= CURRENCY . number_format((float) $p['price'], 2) ?></b><br><small class="muted">stock <?= (int) $p['stock'] ?></small></span></a>
      <?php endforeach; ?></div>
    </div>
    <div class="card">
      <h3 style="margin:0 0 10px">⚠️ Running low</h3>
      <?php if (!$lowItems): ?><p class="muted">Nothing is running low. 🎉</p><?php endif; ?>
      <div class="plist"><?php foreach ($lowItems as $p): ?>
        <a class="pr" href="product_view.php?id=<?= (int) $p['id'] ?>"><?= aou_thumb($p['image'] ?? '', $p['emoji'] ?? '', $p['bg_color'] ?? '', 44) ?>
          <span class="m"><b><?= admin_h($p['name']) ?></b></span><span class="tag <?= (int) $p['stock'] <= 0 ? 'wait' : 'na' ?>"><?= (int) $p['stock'] <= 0 ? 'Out of stock' : (int) $p['stock'] . ' left' ?></span></a>
      <?php endforeach; ?></div>
      <?php if ($reviewProducts): ?><p style="margin:14px 0 0"><a class="btn" href="products.php?status=pending"><?= $reviewProducts ?> product<?= $reviewProducts === 1 ? '' : 's' ?> waiting for review</a></p><?php endif; ?>
    </div>
  </div>
</div>

<div class="sec" id="categories">
  <h2>🏷️ Categories <a href="categories.php" style="text-decoration:underline">Manage categories</a></h2>
  <div class="card"><?php if (!$cats): ?><p class="muted">No categories yet.</p><?php endif; ?>
    <div class="cg"><?php foreach ($cats as $c0): ?>
      <a class="cc" href="products.php?category=<?= (int) $c0['id'] ?>"><b><?= admin_h($c0['name']) ?></b><div class="muted"><?= (int) $c0['n'] ?> product<?= (int) $c0['n'] === 1 ? '' : 's' ?></div><div class="bar"><i style="width:<?= max(4, (int) round(100 * (int) $c0['n'] / $maxCat)) ?>%"></i></div></a>
    <?php endforeach; ?></div>
  </div>
</div>

<div class="sec" id="sellers">
  <h2>🏪 Sellers <a href="sellers.php" style="text-decoration:underline">Manage sellers</a></h2>
  <div class="card"><?php if (!$sellerRows): ?><p class="muted">No sellers yet.</p><?php endif; ?>
    <div class="plist"><?php foreach ($sellerRows as $s0): $oc = $sellerOrderCounts[(int) $s0['id']] ?? ['orders' => 0, 'waiting' => 0]; ?>
      <a class="pr" href="sellers.php"><span class="m"><b>🏪 <?= admin_h($s0['business_name']) ?></b><small><?= (int) $s0['products'] ?> product<?= (int) $s0['products'] === 1 ? '' : 's' ?> · <?= (int) $oc['orders'] ?> order<?= (int) $oc['orders'] === 1 ? '' : 's' ?><?= $oc['waiting'] ? ' · ' . (int) $oc['waiting'] . ' waiting' : '' ?></small></span>
        <span class="tag <?= $s0['status'] === 'approved' ? 'ok' : ($s0['status'] === 'pending' ? 'wait' : 'na') ?>"><?= admin_h(ucfirst((string) $s0['status'])) ?></span></a>
    <?php endforeach; ?></div>
  </div>
</div>

<div class="sec" id="users">
  <h2>👥 Users <a href="users.php" style="text-decoration:underline">All users</a></h2>
  <div class="card"><?php if (!$userRows): ?><p class="muted">No users yet.</p><?php endif; ?>
    <div class="plist"><?php foreach ($userRows as $u): ?>
      <a class="pr" href="users.php"><span class="m"><b><?= admin_h($u['name'] ?: 'User') ?></b><small><?= admin_h($u['email']) ?></small></span></a>
    <?php endforeach; ?></div>
  </div>
</div>
<?php require 'includes/footer.php';
