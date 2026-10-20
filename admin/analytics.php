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
.an-card{display:flex;gap:14px;align-items:center}.an-ico{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex:none}
.an-grid2{display:grid;grid-template-columns:3fr 2fr;gap:16px;margin-top:16px}.an-grid3{display:grid;grid-template-columns:3fr 2fr;gap:16px;margin-top:16px}
.an-h{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:0 0 12px}.an-h h3{margin:0}
.an-bar{height:8px;border-radius:99px;background:#e8eeea;overflow:hidden}.an-bar i{display:block;height:100%;border-radius:99px;background:#1d6b52}
.an-leg{list-style:none;margin:0;padding:0}.an-leg li{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:14px}.an-leg b{margin-left:auto}.an-dot{width:10px;height:10px;border-radius:50%;flex:none}
.an-st{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;color:#fff}
@media(max-width:900px){.an-grid2,.an-grid3{grid-template-columns:1fr}}
.an-grid2>*,.an-grid3>*{min-width:0}
</style>
<div class="grid">
  <div class="card an-card"><div class="an-ico" style="background:#e1f3e8">💰</div><div><div class="muted">Total revenue</div><div class="metric"><?= $money($c['revenue']['value']) ?></div><div class="muted"><?= $trend($c['revenue']['trend']) ?> last 30 days: <?= $money($c['revenue']['last30']) ?></div></div></div>
  <div class="card an-card"><div class="an-ico" style="background:#e3ecfb">🛒</div><div><div class="muted">Total orders</div><div class="metric"><?= number_format($c['orders']['value']) ?></div><div class="muted"><?= $trend($c['orders']['trend']) ?> last 30 days: <?= (int) $c['orders']['last30'] ?></div></div></div>
  <div class="card an-card"><div class="an-ico" style="background:#efe9fb">👥</div><div><div class="muted">Total customers</div><div class="metric"><?= number_format($c['customers']['value']) ?></div><div class="muted"><?= $trend($c['customers']['trend']) ?> last 30 days: <?= (int) $c['customers']['last30'] ?></div></div></div>
  <div class="card an-card"><div class="an-ico" style="background:#fdeaea">📦</div><div><div class="muted">Total products</div><div class="metric"><?= number_format($c['products']['value']) ?></div><div class="muted"><?= (int) $c['products']['review'] ?> waiting for review</div></div></div>
</div>

<div class="an-grid2">
  <div class="card"><div class="an-h"><h3>Sales overview</h3><span><a class="btn <?= $months === 6 ? '' : 'gray' ?>" href="analytics.php?months=6">6 months</a> <a class="btn <?= $months === 12 ? '' : 'gray' ?>" href="analytics.php?months=12">12 months</a></span></div>
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
<?php foreach ($d['sales'] as $i => $s): ?><circle cx="<?= round($px($i), 1) ?>" cy="<?= round($py($s['value']), 1) ?>" r="4.5" fill="#fff" stroke="#1d6b52" stroke-width="2.5"><title><?= admin_h($s['label'] . ': ' . $money($s['value'])) ?></title></circle>
<text x="<?= round($px($i), 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle" font-size="11" fill="#6b7973"><?= admin_h($s['label']) ?></text><?php endforeach; ?>
</svg></div>

  <div class="card"><div class="an-h"><h3>Order status</h3></div>
<?php $tot = max(1, (int) $d['orders_total']); $r = 62; $circ = 2 * M_PI * $r; $off = 0; ?>
<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;justify-content:center">
<svg viewBox="0 0 170 170" width="170" height="170"><circle cx="85" cy="85" r="<?= $r ?>" fill="none" stroke="#eef1f0" stroke-width="26"/>
<?php foreach ($d['status'] as $s): if ($s['count'] < 1) continue; $len = $circ * $s['count'] / $tot; ?>
<circle cx="85" cy="85" r="<?= $r ?>" fill="none" stroke="<?= $colors[$s['status']] ?>" stroke-width="26" stroke-dasharray="<?= round($len, 2) ?> <?= round($circ - $len, 2) ?>" stroke-dashoffset="<?= round(-$off, 2) ?>" transform="rotate(-90 85 85)"/>
<?php $off += $len; endforeach; ?>
<text x="85" y="84" text-anchor="middle" font-size="24" font-weight="800" fill="#17231e"><?= number_format($d['orders_total']) ?></text><text x="85" y="102" text-anchor="middle" font-size="12" fill="#6b7973">Orders</text></svg>
<ul class="an-leg" style="min-width:150px"><?php foreach ($d['status'] as $s): ?><li><span class="an-dot" style="background:<?= $colors[$s['status']] ?>"></span><?= admin_h(ucfirst($s['status'])) ?><b><?= $s['percent'] ?>%</b></li><?php endforeach; ?></ul></div></div>
</div>

<div class="an-grid3">
  <div class="card"><div class="an-h"><h3>Recent orders</h3><a class="btn gray" href="orders.php">View all</a></div>
  <div class="table-wrap" style="border:0;overflow-x:auto"><table class="table" style="min-width:520px"><tr><th>Order</th><th>Customer</th><th>Product</th><th>Status</th><th>Amount</th></tr>
  <?php foreach ($d['recent'] as $o): ?><tr><td><a href="order_view.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td><td><?= admin_h($o['customer']) ?></td><td><?= admin_h($o['product']) ?><?= $o['items'] > 1 ? ' +' . ($o['items'] - 1) : '' ?></td><td><span class="an-st" style="background:<?= $colors[$o['status']] ?? '#64736d' ?>"><?= admin_h(ucfirst($o['status'])) ?></span></td><td><?= $money($o['total']) ?></td></tr><?php endforeach; ?>
  <?php if (!$d['recent']): ?><tr><td colspan="5" class="muted">No orders yet.</td></tr><?php endif; ?></table></div></div>

  <div class="card"><div class="an-h"><h3>Top products</h3><a class="btn gray" href="products.php">View all</a></div>
  <?php $topMax = max(1, (int) ($d['top'][0]['sold'] ?? 1)); foreach ($d['top'] as $t): ?>
    <div style="margin-bottom:14px"><div style="display:flex;justify-content:space-between;gap:8px"><strong><?= admin_h($t['name']) ?></strong><span class="muted"><?= (int) $t['sold'] ?> sold</span></div><div class="an-bar"><i style="width:<?= round($t['sold'] / $topMax * 100) ?>%"></i></div></div>
  <?php endforeach; if (!$d['top']): ?><p class="muted">No sales yet.</p><?php endif; ?></div>
</div>
<?php require 'includes/footer.php';
