<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/analytics.php';
$months = (int) ($_GET['months'] ?? 6) === 12 ? 12 : 6;
$d = analytics_data($pdo, $months);
$page_title = 'Analytics';
$money = fn($v) => CURRENCY . number_format((float) $v, 0);
$colors = ['pending' => '#7C6FD0', 'approved' => '#2F6FDE', 'paid' => '#17A2A2', 'shipped' => '#E9B949', 'delivered' => '#1D6B52', 'cancelled' => '#B83B3B'];
$trend = function ($t) {
    if ($t === null) return '<span style="color:#166534;font-weight:700">▲ new</span>';
    if ($t >= 0) return '<span style="color:#166534;font-weight:700">▲ ' . $t . '%</span>';
    return '<span style="color:#b83b3b;font-weight:700">▼ ' . abs($t) . '%</span>';
};
require 'includes/header.php';
$c = $d['cards'];
?>
<style>
a.an-link{color:inherit;text-decoration:none}
.an-card{display:flex;gap:14px;align-items:center;border-left:6px solid var(--ac);transition:transform .12s,box-shadow .12s;cursor:pointer}
.an-card:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(20,45,35,.12)}
.an-ico{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:23px;flex:none;background:var(--bg)}
.an-metric{font-size:28px;font-weight:800;margin-top:6px;color:var(--ac)}
.an-grid2,.an-grid3{display:grid;grid-template-columns:3fr 2fr;gap:16px;margin-top:16px}.an-grid2>*,.an-grid3>*{min-width:0}
.an-sec{border-top:5px solid var(--ac)}
.an-h{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:0 0 12px;flex-wrap:wrap}.an-h h3{margin:0}
.an-bar{height:9px;border-radius:99px;background:#e8eeea;overflow:hidden}.an-bar i{display:block;height:100%;border-radius:99px;background:var(--ac)}
.an-leg{list-style:none;margin:0;padding:0}.an-leg a{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:9px;font-size:14px}.an-leg a:hover{background:#f1f5f3}.an-leg b{margin-left:auto}.an-dot{width:11px;height:11px;border-radius:50%;flex:none}
.an-st{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;color:#fff}
.an-tr{cursor:pointer}.an-tr:hover td{background:#f1f5f3}
.an-top{display:block;padding:8px;border-radius:10px;margin:0 -8px 6px}.an-top:hover{background:#f7f9f8}
.an-pt{cursor:pointer}.an-pt:hover circle{r:7}
.an-link-sub{color:inherit;text-decoration:underline dotted}
@media(max-width:900px){.an-grid2,.an-grid3{grid-template-columns:1fr}}
</style>
<div class="grid">
  <a class="an-link" href="orders.php"><div class="card an-card" style="--ac:#1d6b52;--bg:#e1f3e8"><div class="an-ico">💰</div><div><div class="muted">Total revenue</div><div class="an-metric"><?= $money($c['revenue']['value']) ?></div><div class="muted"><?= $trend($c['revenue']['trend']) ?> last 30 days: <?= $money($c['revenue']['last30']) ?></div></div></div></a>
  <a class="an-link" href="orders.php"><div class="card an-card" style="--ac:#2f6fde;--bg:#e3ecfb"><div class="an-ico">🛒</div><div><div class="muted">Total orders</div><div class="an-metric"><?= number_format($c['orders']['value']) ?></div><div class="muted"><?= $trend($c['orders']['trend']) ?> last 30 days: <?= (int) $c['orders']['last30'] ?></div></div></div></a>
  <a class="an-link" href="users.php?role=customer"><div class="card an-card" style="--ac:#7c6fd0;--bg:#efe9fb"><div class="an-ico">👥</div><div><div class="muted">Total customers</div><div class="an-metric"><?= number_format($c['customers']['value']) ?></div><div class="muted"><?= $trend($c['customers']['trend']) ?> last 30 days: <?= (int) $c['customers']['last30'] ?></div></div></div></a>
  <a class="an-link" href="products.php"><div class="card an-card" style="--ac:#e0742a;--bg:#fdebdd"><div class="an-ico">📦</div><div><div class="muted">Total products</div><div class="an-metric"><?= number_format($c['products']['value']) ?></div><div class="muted"><?= (int) $c['products']['review'] ?> waiting for review</div></div></div></a>
</div>

<div class="an-grid2">
  <div class="card an-sec" style="--ac:#1d6b52"><div class="an-h"><h3>📈 Sales overview</h3><span><a class="btn <?= $months === 6 ? '' : 'gray' ?>" href="analytics.php?months=6">6 months</a> <a class="btn <?= $months === 12 ? '' : 'gray' ?>" href="analytics.php?months=12">12 months</a></span></div>
<?php
$vals = array_column($d['sales'], 'value'); $max = max($vals ?: [0]); if ($max <= 0) $max = 1000;
$raw = $max / 4; $mag = pow(10, floor(log10($raw))); $step = $mag * 10; foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) { if ($m * $mag >= $raw) { $step = $m * $mag; break; } } $nice = $step * 4;
$W = 640; $H = 250; $L = 58; $R = 14; $T = 14; $B = 30; $n = count($d['sales']);
$px = fn($i) => $L + ($n > 1 ? $i * ($W - $L - $R) / ($n - 1) : 0);
$py = fn($v) => $T + ($H - $T - $B) * (1 - $v / $nice);
$pts = []; foreach ($d['sales'] as $i => $s) $pts[] = round($px($i), 1) . ',' . round($py($s['value']), 1);
?>
<svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="width:100%;height:auto" role="img" aria-label="Sales by month">
<defs><linearGradient id="ag" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1d6b52" stop-opacity=".28"/><stop offset="1" stop-color="#1d6b52" stop-opacity="0"/></linearGradient></defs>
<?php for ($g = 0; $g <= 4; $g++): $gv = $nice * $g / 4; $gy = $py($gv); ?>
<line x1="<?= $L ?>" x2="<?= $W - $R ?>" y1="<?= round($gy, 1) ?>" y2="<?= round($gy, 1) ?>" stroke="#e1e8e4"/><text x="<?= $L - 8 ?>" y="<?= round($gy + 4, 1) ?>" text-anchor="end" font-size="11" fill="#6b7973"><?= $gv >= 1000 ? round($gv / 1000, 1) . 'K' : round($gv) ?></text>
<?php endfor; ?>
<polygon points="<?= round($px(0), 1) ?>,<?= $H - $B ?> <?= implode(' ', $pts) ?> <?= round($px($n - 1), 1) ?>,<?= $H - $B ?>" fill="url(#ag)"/>
<polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#1d6b52" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
<?php foreach ($d['sales'] as $i => $s): ?><a class="an-pt" href="orders.php"><circle cx="<?= round($px($i), 1) ?>" cy="<?= round($py($s['value']), 1) ?>" r="5.5" fill="#fff" stroke="#1d6b52" stroke-width="2.5"><title><?= admin_h($s['label'] . ': ' . $money($s['value']) . ' (open orders)') ?></title></circle>
<text x="<?= round($px($i), 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle" font-size="11" fill="#6b7973"><?= admin_h($s['label']) ?></text></a><?php endforeach; ?>
</svg></div>

  <div class="card an-sec" style="--ac:#7c6fd0"><div class="an-h"><h3>🍩 Order status</h3></div>
<?php $tot = max(1, (int) $d['orders_total']); $r = 62; $circ = 2 * M_PI * $r; $off = 0; ?>
<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;justify-content:center">
<svg viewBox="0 0 170 170" width="170" height="170"><circle cx="85" cy="85" r="<?= $r ?>" fill="none" stroke="#eef1f0" stroke-width="26"/>
<?php foreach ($d['status'] as $s): if ($s['count'] < 1) continue; $len = $circ * $s['count'] / $tot; ?>
<a href="orders.php?status=<?= $s['status'] ?>" style="cursor:pointer"><circle cx="85" cy="85" r="<?= $r ?>" fill="none" stroke="<?= $colors[$s['status']] ?>" stroke-width="26" stroke-dasharray="<?= round($len, 2) ?> <?= round($circ - $len, 2) ?>" stroke-dashoffset="<?= round(-$off, 2) ?>" transform="rotate(-90 85 85)"><title><?= admin_h(ucfirst($s['status']) . ': ' . $s['count'] . ' orders') ?></title></circle></a>
<?php $off += $len; endforeach; ?>
<text x="85" y="84" text-anchor="middle" font-size="24" font-weight="800" fill="#17231e" pointer-events="none"><?= number_format($d['orders_total']) ?></text><text x="85" y="102" text-anchor="middle" font-size="12" fill="#6b7973" pointer-events="none">Orders</text></svg>
<ul class="an-leg" style="min-width:160px"><?php foreach ($d['status'] as $s): ?><li><a class="an-link" href="orders.php?status=<?= $s['status'] ?>"><span class="an-dot" style="background:<?= $colors[$s['status']] ?>"></span><?= admin_h(ucfirst($s['status'])) ?><b><?= $s['count'] ?> · <?= $s['percent'] ?>%</b></a></li><?php endforeach; ?></ul></div></div>
</div>

<div class="an-grid3">
  <div class="card an-sec" style="--ac:#2f6fde"><div class="an-h"><h3>🧾 Recent orders</h3><a class="btn gray" href="orders.php">View all</a></div>
  <div class="table-wrap" style="border:0;overflow-x:auto"><table class="table" style="min-width:520px"><tr><th>Order</th><th>Customer</th><th>Product</th><th>Status</th><th>Amount</th></tr>
  <?php foreach ($d['recent'] as $o): ?><tr class="an-tr" onclick="location.href='order_view.php?id=<?= (int) $o['id'] ?>'"><td><a href="order_view.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td><td><?= admin_h($o['customer']) ?></td><td><?= admin_h($o['product']) ?><?= $o['items'] > 1 ? ' +' . ($o['items'] - 1) : '' ?></td><td><a href="orders.php?status=<?= admin_h($o['status']) ?>" class="an-st" style="background:<?= $colors[$o['status']] ?? '#64736d' ?>" onclick="event.stopPropagation()"><?= admin_h(ucfirst($o['status'])) ?></a></td><td><?= $money($o['total']) ?></td></tr><?php endforeach; ?>
  <?php if (!$d['recent']): ?><tr><td colspan="5" class="muted">No orders yet.</td></tr><?php endif; ?></table></div></div>

  <div class="card an-sec" style="--ac:#e0742a"><div class="an-h"><h3>🏆 Top products</h3><a class="btn gray" href="products.php">View all</a></div>
  <?php $topMax = max(1, (int) ($d['top'][0]['sold'] ?? 1)); $tcol = ['#e0742a', '#e9b949', '#1d6b52', '#2f6fde', '#7c6fd0']; foreach ($d['top'] as $i => $t): ?>
    <a class="an-top an-link" href="product_view.php?id=<?= (int) $t['id'] ?>" style="--ac:<?= $tcol[$i % 5] ?>"><div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:5px"><strong><?= admin_h($t['name']) ?></strong><span class="muted"><?= (int) $t['sold'] ?> sold</span></div><div class="an-bar"><i style="width:<?= round($t['sold'] / $topMax * 100) ?>%"></i></div></a>
  <?php endforeach; if (!$d['top']): ?><p class="muted">No sales yet.</p><?php endif; ?></div>
</div>
<div class="card an-sec" style="--ac:#17a2a2;margin-top:16px"><div class="an-h"><h3>⬇️ Export orders (CSV)</h3></div>
<form method="get" action="export_orders.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><div class="field" style="margin:0"><label>From</label><input type="date" name="from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>"></div><div class="field" style="margin:0"><label>To</label><input type="date" name="to" value="<?= date('Y-m-d') ?>"></div><button class="btn">Download CSV</button></form>
<p class="muted" style="margin:8px 0 0">Opens in Excel or Google Sheets: order, date, status, total, customer, phone, address and items.</p></div>
<?php require 'includes/footer.php';
