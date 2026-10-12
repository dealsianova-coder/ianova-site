<?php
/** Seller site: switch automatic order approval on or off. */
require_once __DIR__ . '/_layout.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($_SESSION['ops_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        ops_setting_set($pdo, 'auto_seller_' . $seller_id, ($_POST['on'] ?? '') === '1' ? '1' : '0');
        $_SESSION['ops_flash'] = 'Saved.';
    }
    header('Location: auto_approval.php');
    exit;
}
$master = ops_setting($pdo, 'auto_approve', '1') === '1';
$mine = ops_seller_auto($pdo, $seller_id);
$on = $mine && $master;
seller_head('Order approval', 'auto_approval.php');
?>
<div class="blk">
  <div class="blk-head"><h2>Automatic approval: <span style="color:<?= $on ? '#0B6B37' : '#B00F2F' ?>"><?= $on ? 'ON' : 'OFF' ?></span></h2></div>
  <p class="muted" style="margin-top:0"><?php if (!$master): ?>IANOVA has switched automatic approval off for everyone, so you approve orders by hand.<?php elseif ($mine): ?>When your products are in stock, new orders are approved for you. You will see them as "Approved" in Orders.<?php else: ?>You approve every new order by hand in Orders.<?php endif; ?></p>
  <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="on" value="<?= $mine ? '0' : '1' ?>"><button class="btn <?= $mine ? 'red' : 'ok' ?>" type="submit"><?= $mine ? 'Switch to manual' : 'Switch on automatic' ?></button></form>
</div>
<?php seller_foot();
