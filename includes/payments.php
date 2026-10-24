<?php
/**
 * Deposits and part payments.
 * - The admin sets a deposit % (0 = off) and the "how to pay" text shown to customers.
 * - At checkout the customer chooses "pay in full" or "pay the deposit now, the rest later".
 * - Customers pay outside the system (M-Pesa, cash, bank). The admin records each payment on the order;
 *   the order then shows paid / balance in the app, the website and the emails.
 * Everything here returns '' on success or a message to show, like order_ops.php.
 */
require_once __DIR__ . '/seller_ops.php';
require_once __DIR__ . '/order_ops.php';

function pay_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        oo_ensure($pdo);
        if (ops_setting($pdo, 'schema_dp', '') === '1') return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_payments (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, method VARCHAR(40) NOT NULL DEFAULT '', reference VARCHAR(80) NOT NULL DEFAULT '', note VARCHAR(200) NOT NULL DEFAULT '', admin_id INT NOT NULL DEFAULT 0, admin_name VARCHAR(120) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_plan VARCHAR(10) NOT NULL DEFAULT 'full'");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS deposit_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
        ops_setting_set($pdo, 'schema_dp', '1');
    } catch (Throwable $e) { /* already there */ }
}

// ---------------------------------------------------------------- settings
function pay_text_get(PDO $pdo, string $key): string {
    try {
        $s = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?'); $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? '' : (string) $v;
    } catch (Throwable $e) { return ''; }
}

function pay_text_put(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT IGNORE INTO settings(setting_key, setting_value) VALUES(?, ?)')->execute([$key, $value]);
    $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
}

/** Deposit % the customer can choose at checkout. 0 = deposits are off. */
function pay_deposit_pct(PDO $pdo): float {
    return max(0.0, min(100.0, (float) ops_setting($pdo, 'deposit_pct', '0')));
}

function pay_instructions(PDO $pdo): string {
    return pay_text_get($pdo, 'pay_instructions');
}

function pay_settings_get(PDO $pdo): array {
    pay_ensure($pdo);
    return ['deposit_pct' => pay_deposit_pct($pdo), 'instructions' => pay_instructions($pdo)];
}

function pay_settings_save(PDO $pdo, $pct, string $instructions): string {
    pay_ensure($pdo);
    if (!is_numeric($pct)) return 'Enter the deposit as a number from 0 to 100.';
    $p = (float) $pct;
    if ($p < 0 || $p > 100) return 'The deposit must be between 0 and 100.';
    ops_setting_set($pdo, 'deposit_pct', (string) round($p, 2));
    pay_text_put($pdo, 'pay_instructions', mb_substr(trim($instructions), 0, 400));
    return '';
}

// ---------------------------------------------------------------- numbers for one order
function pay_list(PDO $pdo, int $orderId): array {
    pay_ensure($pdo);
    $q = $pdo->prepare('SELECT id, amount, method, reference, note, admin_name, created_at FROM order_payments WHERE order_id = ? ORDER BY id DESC');
    $q->execute([$orderId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['amount'] = (float) $r['amount']; }
    return $rows;
}

/** total, plan, deposit due, paid, refunded, balance and a state: unpaid | part | deposit | paid | cancelled. */
function pay_summary(PDO $pdo, int $orderId): ?array {
    pay_ensure($pdo);
    $q = $pdo->prepare('SELECT total, status, pay_plan, deposit_pct FROM orders WHERE id = ?'); $q->execute([$orderId]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    $total = round((float) $o['total'], 2);
    $paid = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = ' . (int) $orderId)->fetchColumn();
    $refunded = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM order_refunds WHERE order_id = ' . (int) $orderId)->fetchColumn();
    $net = round($paid - $refunded, 2);
    $plan = ((string) $o['pay_plan'] === 'deposit' && (float) $o['deposit_pct'] > 0) ? 'deposit' : 'full';
    $pct = $plan === 'deposit' ? (float) $o['deposit_pct'] : 0.0;
    $due = $plan === 'deposit' ? round($total * $pct / 100, 2) : 0.0;
    $balance = max(0.0, round($total - $net, 2));
    $left = $plan === 'deposit' ? max(0.0, round($due - $net, 2)) : 0.0;
    if ($o['status'] === 'cancelled') $state = 'cancelled';
    elseif ($total > 0 && $balance <= 0.009) $state = 'paid';
    elseif ($plan === 'deposit' && $net > 0.009 && $left <= 0.009) $state = 'deposit';
    elseif ($net > 0.009) $state = 'part';
    else $state = 'unpaid';
    return ['plan' => $plan, 'deposit_pct' => $pct, 'deposit_due' => $due, 'deposit_left' => $left, 'total' => $total,
            'paid' => round($paid, 2), 'refunded' => round($refunded, 2), 'balance' => $balance, 'state' => $state];
}

/** What the customer's order page and the admin order page show. */
function pay_order_fields(PDO $pdo, int $orderId): array {
    $s = pay_summary($pdo, $orderId);
    if (!$s) return [];
    return ['pay_plan' => $s['plan'], 'deposit_pct' => $s['deposit_pct'], 'deposit_due' => $s['deposit_due'], 'deposit_left' => $s['deposit_left'],
            'paid' => $s['paid'], 'balance' => $s['balance'], 'pay_state' => $s['state'], 'pay_instructions' => pay_instructions($pdo),
            'payments' => array_map(fn($p) => ['amount' => $p['amount'], 'method' => $p['method'], 'created_at' => $p['created_at']], pay_list($pdo, $orderId))];
}

/** Called right after an order is placed: remembers the customer's choice and answers with the amounts to pay. */
function pay_checkout_info(PDO $pdo, int $orderId, string $plan): array {
    try {
        pay_ensure($pdo);
        $pct = pay_deposit_pct($pdo);
        if ($plan === 'deposit' && $pct > 0) {
            $pdo->prepare("UPDATE orders SET pay_plan = 'deposit', deposit_pct = ? WHERE id = ?")->execute([$pct, $orderId]);
        }
        return pay_order_fields($pdo, $orderId);
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------- admin actions
function pay_add(PDO $pdo, int $adminId, int $orderId, float $amount, string $method, string $reference, string $note): string {
    pay_ensure($pdo);
    $s = pay_summary($pdo, $orderId);
    if (!$s) return 'Order not found.';
    if ($s['state'] === 'cancelled') return 'This order was cancelled.';
    $amount = round($amount, 2);
    if ($amount <= 0) return 'Enter an amount above 0.';
    if ($amount > $s['balance'] + 0.009) return 'That is more than the balance (' . number_format($s['balance'], 2) . ').';
    $an = oo_admin_name($pdo, $adminId);
    $pdo->prepare('INSERT INTO order_payments (order_id, amount, method, reference, note, admin_id, admin_name) VALUES (?,?,?,?,?,?,?)')
        ->execute([$orderId, $amount, mb_substr(trim($method), 0, 40), mb_substr(trim($reference), 0, 80), mb_substr(trim($note), 0, 200), $adminId, $an]);
    $pid = (int) $pdo->lastInsertId();
    oo_note($pdo, $adminId, $orderId, 'Payment recorded: ' . number_format($amount, 2) . (trim($method) !== '' ? ' via ' . trim($method) : '') . (trim($reference) !== '' ? ' (' . trim($reference) . ')' : ''));
    oo_log($pdo, $adminId, 'order_payment', '#' . $orderId . ' ' . number_format($amount, 2));
    try {
        require_once __DIR__ . '/notify.php';
        $od = notify_order_data($pdo, $orderId);
        $n = pay_summary($pdo, $orderId);
        if ($od && $od['cemail'] !== '' && $n) {
            $tail = $n['balance'] <= 0.009
                ? '<p><strong>Your order is paid in full. Thank you!</strong></p>'
                : '<p>Balance still to pay: <strong>' . notify_money($n['balance']) . '</strong>.</p>';
            notify_queue($pdo, 'payment:' . $orderId . ':' . $pid, 'customer', (string) $od['cemail'], (string) $od['cname'], 'Payment received for order #' . $orderId,
                notify_wrap('Payment received', '<p>Hello ' . notify_esc($od['cname']) . ',</p><p>We received <strong>' . notify_money($amount) . '</strong> for order <strong>#' . $orderId . '</strong>'
                    . (trim($method) !== '' ? ' by ' . notify_esc(trim($method)) : '') . '.</p>' . $tail));
        }
    } catch (Throwable $e) { /* the payment is saved even if the email fails */ }
    return '';
}

function pay_delete(PDO $pdo, int $adminId, int $paymentId): string {
    pay_ensure($pdo);
    $q = $pdo->prepare('SELECT order_id, amount FROM order_payments WHERE id = ?'); $q->execute([$paymentId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'Payment not found.';
    $pdo->prepare('DELETE FROM order_payments WHERE id = ?')->execute([$paymentId]);
    oo_note($pdo, $adminId, (int) $r['order_id'], 'Payment removed (entered by mistake): ' . number_format((float) $r['amount'], 2));
    oo_log($pdo, $adminId, 'order_payment_delete', '#' . (int) $r['order_id'] . ' ' . number_format((float) $r['amount'], 2));
    return '';
}

/** Orders with money still to collect (not cancelled), newest first. $filter: deposit | part | unpaid | all */
function pay_balances(PDO $pdo, string $filter = 'part', int $limit = 100): array {
    pay_ensure($pdo);
    $rows = $pdo->query("SELECT o.id, o.total, o.status, o.pay_plan, o.created_at, COALESCE(o.guest_name, u.name, 'Guest') customer
                         FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.status <> 'cancelled' ORDER BY o.id DESC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $s = pay_summary($pdo, (int) $r['id']);
        if (!$s || $s['balance'] <= 0.009) continue;
        $show = $filter === 'all' || ($filter === 'unpaid' && $s['state'] === 'unpaid')
            || ($filter === 'part' && in_array($s['state'], ['part', 'deposit'], true))
            || ($filter === 'deposit' && $s['plan'] === 'deposit');
        if (!$show) continue;
        $out[] = ['id' => (int) $r['id'], 'customer' => (string) $r['customer'], 'status' => (string) $r['status'], 'created_at' => (string) $r['created_at']] + $s;
        if (count($out) >= $limit) break;
    }
    return $out;
}
