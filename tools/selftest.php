<?php
// Self-test for the admin team, access rules, order lock, email setup and key pages.
// Run:  sh ~/ianova-php/tools/run.sh selftest      (command line only; makes and removes its own test admin)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_team.php';
$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($why !== '' ? "  ($why)" : '') . "\n"; } }
function skip(string $name, string $why): void { echo "  skip  $name ($why)\n"; }

echo "== Database\n";
t('database connection', isset($pdo) && $pdo instanceof PDO);
foreach (['admins', 'orders', 'order_items', 'products', 'users', 'sellers'] as $tb) { $ok = true; try { $pdo->query("SELECT 1 FROM $tb LIMIT 1"); } catch (Throwable $e) { $ok = false; } t("table $tb exists", $ok); }

echo "== Admin team\n";
team_ensure($pdo);
$cols = $pdo->query('SHOW COLUMNS FROM admins')->fetchAll(PDO::FETCH_COLUMN);
foreach (['status', 'is_master', 'security_hash', 'perms'] as $c) t("admins.$c column", in_array($c, $cols, true));
t('exactly one master admin', (int) $pdo->query('SELECT COUNT(*) FROM admins WHERE is_master = 1')->fetchColumn() === 1);
$mail = 'selftest+' . bin2hex(random_bytes(3)) . '@example.invalid';
$tid = 0;
try {
    $pdo->prepare("INSERT INTO admins (name, email, password_hash, status, is_master, perms) VALUES ('Self Test', ?, ?, 'pending', 0, 'products,orders')")->execute([$mail, password_hash('LoginPass123', PASSWORD_DEFAULT)]);
    $tid = (int) $pdo->lastInsertId();
    t('pending admin cannot sign in', team_login_block($pdo, ['id' => $tid]) !== '');
    $pdo->prepare("UPDATE admins SET status = 'suspended' WHERE id = ?")->execute([$tid]);
    t('suspended admin cannot sign in', team_login_block($pdo, ['id' => $tid]) !== '');
    $pdo->prepare("UPDATE admins SET status = 'active' WHERE id = ?")->execute([$tid]);
    t('active admin can sign in', team_login_block($pdo, ['id' => $tid]) === '');
    t('no security password yet', !team_has_security($pdo, $tid));
    t('security password must differ from sign-in password', team_set_security($pdo, $tid, 'LoginPass123', 'LoginPass123') !== '');
    t('security password needs the sign-in password first', team_set_security($pdo, $tid, 'wrong', 'SecretPass456') !== '');
    t('set security password', team_set_security($pdo, $tid, 'LoginPass123', 'SecretPass456') === '');
    t('security password verifies', team_verify($pdo, $tid, 'SecretPass456') === '');
    t('wrong security password refused', team_verify($pdo, $tid, 'nope') !== '');
    t('edit own account needs security password', team_edit_self($pdo, $tid, 'Self Test 2', $mail, '', 'bad') !== '');
    t('edit own account works', team_edit_self($pdo, $tid, 'Self Test 2', $mail, '', 'SecretPass456') === '');
    $taken = (string) $pdo->query('SELECT email FROM users LIMIT 1')->fetchColumn();
    if ($taken !== '') t('admin email cannot match a customer', team_edit_self($pdo, $tid, 'Self Test 2', $taken, '', 'SecretPass456') !== ''); else skip('admin email vs customer', 'no customers yet');
    $allowed = team_allowed_for($pdo, $tid);
    t('access limited to chosen sections', $allowed === ['products', 'orders'], implode(',', $allowed));
    t('can open Orders', team_can($pdo, $tid, 'orders'));
    t('cannot open Settings', !team_can($pdo, $tid, 'settings'));
    t('API action mapping (orders_list)', team_section_of_action('panel', 'orders_list') === 'orders');
    t('API action mapping (settings_set)', team_section_of_action('panel', 'settings_set') === 'settings');
    t('API action mapping (admin.php order_status)', team_section_of_action('admin', 'order_status') === 'orders');
    $master = (int) $pdo->query('SELECT id FROM admins WHERE is_master = 1')->fetchColumn();
    t('master sees every section', count(team_allowed_for($pdo, $master)) === count(team_sections()));
    // email reset link
    $tok = bin2hex(random_bytes(8));
    $pdo->prepare('REPLACE INTO admin_sec_reset (admin_id, token_hash, expires_at, requested_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())')->execute([$tid, hash('sha256', $tok)]);
    t('reset link rejects a wrong token', team_sec_reset_apply($pdo, $tid, 'bad', 'LoginPass123', 'NewSecret789') !== '');
    t('reset link needs the sign-in password', team_sec_reset_apply($pdo, $tid, $tok, 'wrong', 'NewSecret789') !== '');
    t('reset link sets a new security password', team_sec_reset_apply($pdo, $tid, $tok, 'LoginPass123', 'NewSecret789') === '' && team_verify($pdo, $tid, 'NewSecret789') === '');
    t('activity log records', (int) $pdo->query("SELECT COUNT(*) FROM admin_log WHERE admin_id = $tid")->fetchColumn() > 0);
} catch (Throwable $e) {
    t('admin team tests ran', false, $e->getMessage());
} finally {
    if ($tid) {
        $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$tid]);
        $pdo->prepare('DELETE FROM admin_log WHERE admin_id = ?')->execute([$tid]);
        $pdo->prepare('DELETE FROM admin_sec_reset WHERE admin_id = ?')->execute([$tid]);
        $pdo->prepare('DELETE FROM admin_sec_fail WHERE admin_id = ?')->execute([$tid]);
    }
}

echo "== Order tools\n";
require_once __DIR__ . '/../includes/order_ops.php';
oo_ensure($pdo);
$ocols = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN);
t('orders.tracking_no column', in_array('tracking_no', $ocols, true));
$hasRef = true; try { $pdo->query('SELECT 1 FROM order_refunds LIMIT 1'); } catch (Throwable $e) { $hasRef = false; }
t('order_refunds table', $hasRef);
t('refund / tracking / item-cancel need the Orders section', team_section_of_action('panel', 'order_refund_add') === 'orders' && team_section_of_action('panel', 'order_tracking_save') === 'orders' && team_section_of_action('panel', 'order_item_cancel') === 'orders');
$pend = (int) $pdo->query("SELECT id FROM orders WHERE status = 'pending' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($pend) t('refund refused on a pending order', oo_refund_add($pdo, 0, $pend, 1, 'test', 'test') !== ''); else skip('refund on pending order', 'no pending order');

echo "== Payments, deposits, coupons, payouts\n";
foreach (['payments', 'coupons', 'payouts'] as $inc) { $pf = __DIR__ . '/../includes/' . $inc . '.php'; if (is_file($pf)) require_once $pf; }
if (function_exists('pay_ensure')) {
    pay_ensure($pdo);
    $hasPay = true; try { $pdo->query('SELECT 1 FROM order_payments LIMIT 1'); } catch (Throwable $e) { $hasPay = false; }
    t('order_payments table', $hasPay);
    $ocols2 = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN);
    t('orders.pay_plan + deposit_pct columns', in_array('pay_plan', $ocols2, true) && in_array('deposit_pct', $ocols2, true));
    $dp = pay_deposit_pct($pdo);
    t('deposit % is between 0 and 100', $dp >= 0 && $dp <= 100);
    t('recording a payment needs the Orders section', team_section_of_action('panel', 'order_payment_add') === 'orders' && team_section_of_action('panel', 'payments_list') === 'payments');
    $lo = (int) $pdo->query("SELECT id FROM orders ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($lo) { $ps = pay_summary($pdo, $lo); t("order #$lo: balance = total - paid", $ps !== null && abs($ps['balance'] - max(0, $ps['total'] - ($ps['paid'] - $ps['refunded']))) < 0.01); }
    else skip('payment summary', 'no orders yet');
    t('a payment above the balance is refused', $lo ? pay_add($pdo, 0, $lo, 99999999, 'test', '', '') !== '' : true);
} else skip('payments', 'includes/payments.php is not installed');
if (function_exists('cp_ensure')) { cp_ensure($pdo); $okc = true; try { $pdo->query('SELECT 1 FROM coupons LIMIT 1'); } catch (Throwable $e) { $okc = false; } t('coupons table', $okc); }
if (function_exists('po_ensure')) { po_ensure($pdo); $okp = true; try { $pdo->query('SELECT 1 FROM seller_payouts LIMIT 1'); } catch (Throwable $e) { $okp = false; } t('seller_payouts table', $okp); t('commission % is between 0 and 100', po_default_rate($pdo) >= 0 && po_default_rate($pdo) <= 100); }

echo "== Cancelled orders\n";
require_once __DIR__ . '/../includes/seller_orders.php';
$cid = (int) $pdo->query("SELECT id FROM orders WHERE status = 'cancelled' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($cid) {
    $m = set_order_status($pdo, $cid, 'approved');
    t("cancelled order #$cid cannot be reopened", $m !== '' && (string) $pdo->query("SELECT status FROM orders WHERE id = $cid")->fetchColumn() === 'cancelled', $m);
} else skip('cancelled order lock', 'no cancelled order yet: cancel one in the shop app and run again');

echo "== Email setup\n";
t('PHPMailer installed', class_exists('PHPMailer\\PHPMailer\\PHPMailer') || is_file(__DIR__ . '/../vendor/autoload.php'));
t('SMTP password set', defined('SMTP_PASS') && SMTP_PASS !== '' && strpos(SMTP_PASS, ' ') === false, 'empty or has spaces');
require_once __DIR__ . '/../includes/push.php';
t('phone push ready (Firebase key on the server)', push_enabled(), 'put firebase-service-account.json in the site folder');
try { push_ensure($pdo); $pdo->query('SELECT 1 FROM device_tokens LIMIT 1'); t('phone tokens table', true); } catch (Throwable $e) { t('phone tokens table', false); }
t('SITE_URL set (needed for email links)', defined('SITE_URL') && SITE_URL !== '', 'set SITE_URL in config.local.php');
$logo = is_file(__DIR__ . '/../assets/img/ianova-email-logo.png');
t('email logo file exists', $logo);

echo "== Pages (needs the site running on 127.0.0.1:8080)\n";
$base = 'http://127.0.0.1:8080';
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0]]);
$r = @file_get_contents($base . '/admin/login.php', false, $ctx);
if ($r === false) skip('web pages', 'site is not running');
else {
    t('admin login page loads', strpos($r, '<form') !== false);
    $h = @get_headers($base . '/admin/admins.php', true, $ctx);
    t('admin pages need sign-in', is_array($h) && (strpos((string) ($h[0] ?? ''), '302') !== false || strpos((string) ($h[0] ?? ''), '301') !== false), (string) ($h[0] ?? ''));
    $api = @file_get_contents($base . '/api/admin_panel.php?action=nav_counts', false, $ctx);
    t('app API refuses requests without a token', is_string($api) && strpos($api, 'sign in') !== false, (string) $api);
}
echo "\nResult: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
