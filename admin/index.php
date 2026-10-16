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
$revenue = (float) dsh_val($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','shipped','delivered')");
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
];
$attnTotal = 0; foreach ($attn as $a0) $attnTotal += (int) $a0[2];
$stLabel = ['approved' => 'Approved', 'pending' => 'Review', 'rejected' => 'Rejected', 'hidden' => 'Hidden'];
echo aou_css();
?>
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

<div id="overview" class="tiles">
<?php foreach ($tiles as $t): ?>
  <a class="tile" href="<?= $t[3] ?>"><div class="ic"><?= $t[0] ?></div><div class="lb"><?= admin_h($t[1]) ?></div><div class="vl"><?= is_int($t[2]) ? number_format($t[2]) : $t[2] ?></div><?php if ($t[4] !== ''): ?><span class="sb"><?= admin_h($t[4]) ?></span><?php endif; ?></a>
<?php endforeach; ?>
</div>

<div class="attn">
  <h2><?= $attnTotal ? '👀 Needs your attention' : '✅ All caught up' ?></h2>
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
