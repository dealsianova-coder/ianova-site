<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_team.php';
require_once __DIR__ . '/../includes/revenue.php';
rv_ensure($pdo);
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$isM = team_is_master($pdo, $meId);
$error = ''; $notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    if (!$isM) { $error = 'Only the master admin can do this.'; }
    else {
        $m = team_verify($pdo, $meId, (string) ($_POST['security'] ?? ''));
        $who = (string) ($_SESSION['admin_name'] ?? 'Admin');
        if ($m === '') {
            if ($do === 'reset') { $m = strtoupper(trim((string) ($_POST['confirm'] ?? ''))) === 'RESET' ? rv_reset($pdo, $meId, $who, (string) ($_POST['label'] ?? '')) : 'Type RESET to confirm.'; $ok = 'Revenue started over from now. The old figures are archived for ' . RV_HOLD_DAYS . ' days.'; }
            elseif ($do === 'restore') { $m = rv_restore($pdo, (int) ($_POST['id'] ?? 0)); $ok = 'Revenue restored.'; }
            elseif ($do === 'trash') { $m = strtoupper(trim((string) ($_POST['confirm'] ?? ''))) === 'DELETE' ? rv_trash_all($pdo, $meId, $who) : 'Type DELETE to confirm.'; $ok = 'All orders moved to the recycle bin for ' . RV_HOLD_DAYS . ' days.'; }
            elseif ($do === 'trash_restore') { $m = rv_trash_restore($pdo, (int) ($_POST['id'] ?? 0)); $ok = 'Orders restored.'; }
            else { $m = 'Unknown action.'; }
        }
        if ($m === '') { team_log($pdo, $meId, 'web:revenue.php', 'do=' . $do); $notice = $ok ?? 'Done.'; } else $error = $m;
    }
}
$range = (string) ($_GET['r'] ?? '30');
$to = rv_day((string) ($_GET['to'] ?? ''), date('Y-m-d'));
$from = $range === '7' ? date('Y-m-d', strtotime($to . ' -6 day')) : ($range === 'c' ? rv_day((string) ($_GET['from'] ?? ''), date('Y-m-d', strtotime($to . ' -29 day'))) : date('Y-m-d', strtotime($to . ' -29 day')));
$R = rv_report($pdo, $from, $to);
$resets = $isM ? rv_resets($pdo) : []; $trash = $isM ? rv_trash_list($pdo) : [];
$ordersNow = rv_order_counts($pdo);
$page_title = 'Revenue';
require 'includes/header.php';
$m = fn($v) => CURRENCY . number_format((float) $v, 2);
function rv_chart(array $pts): string {
    $W = 800; $H = 220; $L = 46; $B = 26; $T = 10; $Rr = 12; $w = $W - $L - $Rr; $h = $H - $B - $T; $max = 0.0;
    foreach ($pts as $p) $max = max($max, (float) $p['total']); if ($max <= 0) $max = 1;
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:220px" preserveAspectRatio="none">';
    for ($g = 0; $g <= 4; $g++) { $y = $T + $h - $h * $g / 4; $v = $max * $g / 4; $t = $v >= 1000 ? round($v / 1000, $v >= 10000 ? 0 : 1) . 'K' : round($v);
        $o .= '<line x1="' . $L . '" x2="' . ($W - $Rr) . '" y1="' . $y . '" y2="' . $y . '" stroke="#e6e9e7"/><text x="' . ($L - 6) . '" y="' . ($y + 4) . '" font-size="11" fill="#6b7973" text-anchor="end">' . $t . '</text>'; }
    $n = count($pts); $xy = []; $line = '';
    foreach ($pts as $i => $p) { $x = $L + ($n > 1 ? $w * $i / ($n - 1) : $w / 2); $y = $T + $h - $h * ((float) $p['total'] / $max); $xy[] = [$x, $y]; $line .= ($i ? ' L' : 'M') . round($x, 1) . ' ' . round($y, 1); }
    if (!$xy) return $o . '</svg>';
    $o .= '<path d="' . $line . ' L' . end($xy)[0] . ' ' . ($T + $h) . ' L' . $xy[0][0] . ' ' . ($T + $h) . ' Z" fill="#e4b43c" fill-opacity=".18"/><path d="' . $line . '" fill="none" stroke="#e4b43c" stroke-width="3" stroke-linejoin="round"/>';
    $step = max(1, (int) ceil($n / 8));
    foreach ($pts as $i => $p) { if ($n <= 31) $o .= '<circle cx="' . round($xy[$i][0], 1) . '" cy="' . round($xy[$i][1], 1) . '" r="3.5" fill="#e4b43c"/>';
        if ($i % $step == 0 || $i == $n - 1) $o .= '<text x="' . round($xy[$i][0], 1) . '" y="' . ($H - 6) . '" font-size="11" fill="#6b7973" text-anchor="middle">' . date('d/m', strtotime($p['d'])) . '</text>'; }
    return $o . '</svg>';
}
$srcTotal = max(0.01, $R['sources']['direct'] + $R['sources']['sellers_gross']);
?>
<link rel="stylesheet" href="../assets/css/admin-dash-gold.css?v=3">
<style>.rvb{height:8px;border-radius:999px;background:#e8eeea;overflow:hidden;margin-top:5px}.rvb i{display:block;height:100%;background:var(--gg)}.rvr{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid #e8eeea}.rvr:first-child{border-top:0}.rvf{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}.rvf input{padding:7px 9px;border:1px solid #dce4df;border-radius:9px}.dz{border:2px solid #f0b4b4;background:#fff7f7;border-radius:16px;padding:16px;margin-top:20px}.dz input[type=text],.dz input[type=password]{padding:8px 10px;border:1px solid #dce4df;border-radius:9px;margin:3px 6px 3px 0}.two2{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:900px){.two2{grid-template-columns:1fr}}</style>
<?php if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= admin_h($error) ?></div><?php endif; ?>
<div class="wel"><div><h1 style="margin:0">💰 Revenue</h1><p>Where your money came from<?= $R['since'] !== '' ? ' · counting from ' . admin_h(substr($R['since'], 0, 16)) . ' (revenue was started over)' : '' ?></p></div></div>
<form class="rvf" method="get"><span class="rng"><a href="?r=7<?= '' ?>" class="<?= $range === '7' ? 'on' : '' ?>">7 days</a><a href="?r=30" class="<?= $range === '30' ? 'on' : '' ?>">30 days</a></span>
  <input type="hidden" name="r" value="c"><input type="date" name="from" value="<?= admin_h($from) ?>"> to <input type="date" name="to" value="<?= admin_h($to) ?>"><button class="btn gray" type="submit">Custom range</button></form>
<div class="kpis">
  <div class="kpi k1"><span class="ki">💰</span><span><span class="kl">Confirmed revenue</span><span class="kv" style="display:block"><?= $m($R['confirmed']) ?></span><span class="kt"><?= (int) $R['orders'] ?> orders</span></span></div>
  <div class="kpi k4"><span class="ki">✅</span><span><span class="kl">Net after refunds</span><span class="kv" style="display:block"><?= $m($R['net']) ?></span><span class="kt dn">− <?= $m($R['refunded']) ?> refunded</span></span></div>
  <div class="kpi k2"><span class="ki">⏳</span><span><span class="kl">Not counted yet (pending)</span><span class="kv" style="display:block"><?= $m($R['pending']) ?></span><span class="kt"><?= (int) $R['pending_n'] ?> orders</span></span></div>
  <div class="kpi k3"><span class="ki">🚫</span><span><span class="kl">Cancelled</span><span class="kv" style="display:block"><?= $m($R['cancelled']) ?></span><span class="kt"><?= (int) $R['cancelled_n'] ?> orders</span></span></div>
</div>
<div class="pan"><h2>Revenue by day</h2><div class="sub"><?= admin_h($R['from']) ?> to <?= admin_h($R['to']) ?></div><?= rv_chart($R['daily']) ?></div>
<div class="two2">
  <div class="pan"><h2>Where it came from</h2><div class="sub">Product sales in confirmed orders</div>
    <div class="rvr"><span>🏠 IANOVA direct</span><b><?= $m($R['sources']['direct']) ?></b></div><div class="rvb"><i style="width:<?= round($R['sources']['direct'] / $srcTotal * 100) ?>%"></i></div>
    <div class="rvr"><span>🏪 Sellers (total sales)</span><b><?= $m($R['sources']['sellers_gross']) ?></b></div><div class="rvb"><i style="width:<?= round($R['sources']['sellers_gross'] / $srcTotal * 100) ?>%"></i></div>
    <div class="rvr"><span>💼 Your commission from sellers</span><b><?= $m($R['sources']['commission']) ?></b></div>
    <?php foreach ($R['sources']['sellers'] as $s): ?><div class="rvr" style="padding-left:14px;font-size:13px"><span><?= admin_h($s['name']) ?> <span class="muted">(<?= (float) $s['rate'] ?>% commission)</span></span><span><?= $m($s['gross']) ?> · you keep <?= $m($s['commission']) ?></span></div><?php endforeach; ?></div>
  <div class="pan"><h2>How customers paid</h2><div class="sub">Payments recorded on these orders</div>
    <?php foreach ($R['methods'] as $x): ?><div class="rvr"><span><?= admin_h(ucfirst($x['method'])) ?></span><b><?= $m($x['amount']) ?></b></div><?php endforeach; ?>
    <div class="rvr"><span>No payment recorded (for example pay on delivery)</span><b><?= $m($R['unrecorded']) ?></b></div></div>
  <div class="pan"><h2>Top products</h2><div class="sub">By sales</div>
    <?php foreach ($R['products'] as $x): ?><div class="rvr"><span><?= admin_h($x['name']) ?> <span class="muted">× <?= (int) $x['qty'] ?></span></span><b><?= $m($x['amount']) ?></b></div><?php endforeach; if (!$R['products']) echo '<p class="muted">No sales in this period.</p>'; ?></div>
  <div class="pan"><h2>Top categories</h2><div class="sub">By sales</div>
    <?php foreach ($R['categories'] as $x): ?><div class="rvr"><span><?= admin_h($x['name']) ?></span><b><?= $m($x['amount']) ?></b></div><?php endforeach; if (!$R['categories']) echo '<p class="muted">No sales in this period.</p>'; ?></div>
</div>
<p class="muted">Orders that are pending or cancelled are not counted as revenue. Refunds are taken off to give the net figure. <a href="orders.php">See all orders ›</a></p>

<?php if ($isM): ?>
<div class="dz"><h2 style="margin:0 0 4px">🔄 Start revenue over</h2>
  <p class="muted" style="margin:0 0 10px">Revenue goes back to <?= $m(0) ?> from now. Your orders are <b>not</b> touched. The old figures are archived and can be restored for <?= RV_HOLD_DAYS ?> days, then they are permanently deleted.</p>
  <form method="post" onsubmit="return confirm('Start revenue over from now?')"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="reset">
    <input type="text" name="label" placeholder="Note (optional), e.g. Launch day"><input type="text" name="confirm" placeholder="Type RESET" required><input type="password" name="security" placeholder="🔒 Security password" required><button class="btn" style="background:#b83b3b" type="submit">Start over</button></form>
  <?php foreach ($resets as $r): ?><div class="rvr" style="margin-top:6px"><span>🗂️ <?= admin_h(substr($r['reset_at'], 0, 16)) ?> · <?= admin_h($r['admin']) ?><?= $r['label'] ? ' · ' . admin_h($r['label']) : '' ?><br><span class="muted"><?= $r['confirmed'] === null ? ($r['restored_at'] ? 'Restored' : 'Permanently deleted') : 'Archived: ' . $m($r['confirmed']) . ' from ' . (int) $r['orders'] . ' orders · ' . (int) $r['days_left'] . ' days left' ?></span></span>
    <?php if ($r['can_restore']): ?><form method="post" onsubmit="return confirm('Restore the old revenue?')" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="password" name="security" placeholder="🔒 Security password" required><button class="btn gray" type="submit">Restore</button></form><?php endif; ?></div><?php endforeach; ?>
</div>
<div class="dz"><h2 style="margin:0 0 4px">🗑️ Delete test orders</h2>
  <p class="muted" style="margin:0 0 10px">Moves <b>all <?= (int) $ordersNow['n'] ?> orders</b> (<?= $m($ordersNow['total']) ?>) with their items, payments and refunds to a recycle bin. You can bring them back for <?= RV_HOLD_DAYS ?> days, then they are permanently deleted. Customers, products and sellers are not touched.</p>
  <form method="post" onsubmit="return confirm('Move ALL orders to the recycle bin?')"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="trash">
    <input type="text" name="confirm" placeholder="Type DELETE" required><input type="password" name="security" placeholder="🔒 Security password" required><button class="btn" style="background:#b83b3b" type="submit">Delete all orders</button></form>
  <?php foreach ($trash as $t): ?><div class="rvr" style="margin-top:6px"><span>🗑️ <?= admin_h(substr($t['created_at'], 0, 16)) ?> · <?= admin_h($t['admin']) ?><br><span class="muted"><?= (int) $t['n'] ?> orders · <?= $m($t['total']) ?> · <?= $t['can_restore'] ? (int) $t['days_left'] . ' days left' : ($t['restored_at'] ? 'Restored' : 'Permanently deleted') ?></span></span>
    <?php if ($t['can_restore']): ?><form method="post" onsubmit="return confirm('Bring these orders back?')" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="trash_restore"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="password" name="security" placeholder="🔒 Security password" required><button class="btn gray" type="submit">Restore</button></form><?php endif; ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
