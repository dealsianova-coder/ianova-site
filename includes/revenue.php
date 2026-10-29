<?php
/**
 * Revenue: where the money came from, "clear revenue" (a start-over date, restorable for 30 days)
 * and "delete test orders" (kept in a recycle bin for 30 days). Orders are never touched by the revenue reset.
 */
require_once __DIR__ . '/seller_ops.php';
require_once __DIR__ . '/payouts.php';

const RV_HOLD_DAYS = 30;
const RV_OK = "('paid','shipped','delivered')";

function rv_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (ops_setting($pdo, 'schema_rv', '') === '1') return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS revenue_resets (id INT AUTO_INCREMENT PRIMARY KEY, reset_at DATETIME NOT NULL, prev_since VARCHAR(30) NOT NULL DEFAULT '', admin_id INT NOT NULL DEFAULT 0, admin_name VARCHAR(120) NOT NULL DEFAULT '', label VARCHAR(120) NOT NULL DEFAULT '', snapshot MEDIUMTEXT NULL, purge_after DATETIME NOT NULL, restored_at DATETIME NULL, purged_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_trash (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, admin_id INT NOT NULL DEFAULT 0, admin_name VARCHAR(120) NOT NULL DEFAULT '', n INT NOT NULL DEFAULT 0, total DECIMAL(12,2) NOT NULL DEFAULT 0, payload LONGTEXT NULL, purge_after DATETIME NOT NULL, restored_at DATETIME NULL, purged_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        ops_setting_set($pdo, 'schema_rv', '1');
    } catch (Throwable $e) { /* already there */ }
}

function rv_now(): string { return date('Y-m-d H:i:s'); }

/** The "start over" moment. Revenue counts only orders placed after it. '' = never reset. */
function rv_since(PDO $pdo): string { return ops_setting($pdo, 'rev_since', ''); }

/** SQL condition for orders that count as revenue (alias o). */
function rv_cond(PDO $pdo, string $a = 'o'): string {
    $s = rv_since($pdo);
    return "$a.status IN " . RV_OK . ($s !== '' ? " AND $a.created_at >= " . $pdo->quote($s) : '');
}

/** ' AND created_at >= ...' for dashboard figures (orders count, latest orders, trends) after a start-over; '' if never reset. */
function rv_since_and(PDO $pdo, string $col = 'created_at'): string {
    $s = rv_since($pdo);
    return $s !== '' ? " AND $col >= " . $pdo->quote($s) : '';
}

/** Total confirmed revenue since the last start-over. */
function rv_total(PDO $pdo): float {
    rv_ensure($pdo);
    try { return (float) $pdo->query('SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE ' . rv_cond($pdo))->fetchColumn(); } catch (Throwable $e) { return 0.0; }
}

/** Permanently drop recycle-bin contents once the hold time is over. */
function rv_purge(PDO $pdo): void {
    rv_ensure($pdo);
    try {
        $now = $pdo->quote(rv_now());
        $pdo->exec("UPDATE revenue_resets SET snapshot = NULL, purged_at = $now WHERE purged_at IS NULL AND restored_at IS NULL AND purge_after < $now");
        $pdo->exec("UPDATE order_trash SET payload = NULL, purged_at = $now WHERE purged_at IS NULL AND restored_at IS NULL AND purge_after < $now");
    } catch (Throwable $e) { /* ignore */ }
}

function rv_day(string $v, string $def): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $def; }

/**
 * The full breakdown for [$from, $to] (dates, inclusive), never earlier than the start-over date.
 * $days is 7, 30 or 0 (custom from/to).
 */
function rv_report(PDO $pdo, string $from = '', string $to = ''): array {
    rv_ensure($pdo); rv_purge($pdo);
    $since = rv_since($pdo);
    $to = rv_day($to, date('Y-m-d'));
    $from = rv_day($from, date('Y-m-d', strtotime($to . ' -29 day')));
    if ($from > $to) $from = $to;
    $lo = $from . ' 00:00:00';
    if ($since !== '' && $since > $lo) $lo = $since;
    $hi = $to . ' 23:59:59';
    $win = ' AND o.created_at >= ' . $pdo->quote($lo) . ' AND o.created_at <= ' . $pdo->quote($hi);
    $one = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; } };
    $all = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };

    $c = $one('SELECT COALESCE(SUM(o.total),0) t, COUNT(*) n FROM orders o WHERE o.status IN ' . RV_OK . $win);
    $confirmed = (float) ($c['t'] ?? 0); $nConf = (int) ($c['n'] ?? 0);
    $r = $one('SELECT COALESCE(SUM(r.amount),0) t FROM order_refunds r JOIN orders o ON o.id = r.order_id WHERE o.status IN ' . RV_OK . $win);
    $refunded = (float) ($r['t'] ?? 0);
    $p = $one("SELECT COALESCE(SUM(o.total),0) t, COUNT(*) n FROM orders o WHERE o.status IN ('pending','approved')" . $win);
    $x = $one("SELECT COALESCE(SUM(o.total),0) t, COUNT(*) n FROM orders o WHERE o.status = 'cancelled'" . $win);

    // day by day
    $by = [];
    foreach ($all('SELECT DATE(o.created_at) d, COALESCE(SUM(o.total),0) t FROM orders o WHERE o.status IN ' . RV_OK . $win . ' GROUP BY DATE(o.created_at)') as $row) $by[$row['d']] = (float) $row['t'];
    $daily = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) { $d = date('Y-m-d', $t); $daily[] = ['d' => $d, 'total' => $by[$d] ?? 0.0]; if (count($daily) > 370) break; }

    // where it came from: product sales split into IANOVA direct and each seller (with the commission you keep)
    $lines = $all('SELECT p.seller_id sid, COALESCE(s.business_name, \'\') sname, SUM(oi.quantity * oi.unit_price) g FROM orders o JOIN order_items oi ON oi.order_id = o.id LEFT JOIN products p ON p.id = oi.product_id LEFT JOIN sellers s ON s.id = p.seller_id WHERE o.status IN ' . RV_OK . $win . ' GROUP BY p.seller_id, s.business_name');
    $direct = 0.0; $sellers = [];
    foreach ($lines as $l) {
        $g = (float) $l['g'];
        if ($l['sid'] === null || (int) $l['sid'] === 0) { $direct += $g; continue; }
        $rate = po_rate($pdo, (int) $l['sid']);
        $sellers[] = ['id' => (int) $l['sid'], 'name' => (string) $l['sname'], 'gross' => round($g, 2), 'rate' => $rate, 'commission' => round($g * $rate / 100, 2), 'seller_share' => round($g * (100 - $rate) / 100, 2)];
    }
    usort($sellers, fn($a, $b) => $b['gross'] <=> $a['gross']);
    $sellerGross = array_sum(array_column($sellers, 'gross')); $commission = array_sum(array_column($sellers, 'commission'));

    // recorded payments by method (deposits, balances, M-Pesa...) for these orders
    $methods = []; $recorded = 0.0;
    foreach ($all('SELECT COALESCE(NULLIF(op.method,\'\'),\'Other\') m, SUM(op.amount) a FROM order_payments op JOIN orders o ON o.id = op.order_id WHERE o.status IN ' . RV_OK . $win . ' GROUP BY m ORDER BY a DESC') as $m) { $methods[] = ['method' => (string) $m['m'], 'amount' => round((float) $m['a'], 2)]; $recorded += (float) $m['a']; }

    $prods = array_map(fn($z) => ['id' => (int) $z['id'], 'name' => (string) $z['name'], 'qty' => (int) $z['q'], 'amount' => round((float) $z['a'], 2)],
        $all('SELECT p.id, p.name, SUM(oi.quantity) q, SUM(oi.quantity * oi.unit_price) a FROM orders o JOIN order_items oi ON oi.order_id = o.id JOIN products p ON p.id = oi.product_id WHERE o.status IN ' . RV_OK . $win . ' GROUP BY p.id, p.name ORDER BY a DESC LIMIT 8'));
    $cats = array_map(fn($z) => ['name' => (string) $z['name'], 'amount' => round((float) $z['a'], 2)],
        $all('SELECT COALESCE(c.name, \'No category\') name, SUM(oi.quantity * oi.unit_price) a FROM orders o JOIN order_items oi ON oi.order_id = o.id JOIN products p ON p.id = oi.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE o.status IN ' . RV_OK . $win . ' GROUP BY c.name ORDER BY a DESC LIMIT 6'));

    return [
        'from' => $from, 'to' => $to, 'since' => $since, 'window_start' => $lo,
        'confirmed' => round($confirmed, 2), 'orders' => $nConf, 'refunded' => round($refunded, 2), 'net' => round($confirmed - $refunded, 2),
        'pending' => round((float) ($p['t'] ?? 0), 2), 'pending_n' => (int) ($p['n'] ?? 0),
        'cancelled' => round((float) ($x['t'] ?? 0), 2), 'cancelled_n' => (int) ($x['n'] ?? 0),
        'daily' => $daily,
        'sources' => ['direct' => round($direct, 2), 'sellers_gross' => round($sellerGross, 2), 'commission' => round($commission, 2), 'sellers' => array_slice($sellers, 0, 10)],
        'methods' => $methods, 'recorded' => round($recorded, 2), 'unrecorded' => round(max(0.0, $confirmed - $recorded), 2),
        'products' => $prods, 'categories' => $cats,
    ];
}

// ------------------------------------------------------------------ clear revenue (start over)
function rv_resets(PDO $pdo): array {
    rv_ensure($pdo); rv_purge($pdo);
    try {
        $rows = $pdo->query('SELECT id, reset_at, admin_name, label, snapshot, purge_after, restored_at, purged_at FROM revenue_resets ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $lastActive = 0;
    foreach ($rows as $r) if ($r['restored_at'] === null && $lastActive === 0) { $lastActive = (int) $r['id']; }
    foreach ($rows as &$r) {
        $snap = $r['snapshot'] ? json_decode((string) $r['snapshot'], true) : null;
        $r = ['id' => (int) $r['id'], 'reset_at' => $r['reset_at'], 'admin' => $r['admin_name'], 'label' => $r['label'],
              'confirmed' => $snap ? (float) ($snap['confirmed'] ?? 0) : null, 'orders' => $snap ? (int) ($snap['orders'] ?? 0) : null, 'net' => $snap ? (float) ($snap['net'] ?? 0) : null,
              'purge_after' => $r['purge_after'], 'restored_at' => $r['restored_at'], 'purged_at' => $r['purged_at'],
              'can_restore' => $r['restored_at'] === null && $r['purged_at'] === null && (int) $r['id'] === $lastActive,
              'days_left' => $r['purged_at'] === null && $r['restored_at'] === null ? max(0, (int) ceil((strtotime((string) $r['purge_after']) - time()) / 86400)) : 0];
    }
    return $rows;
}

/** Start revenue over from now. The old figures are kept (archived) for 30 days and can be restored. */
function rv_reset(PDO $pdo, int $adminId, string $adminName, string $label = ''): string {
    rv_ensure($pdo);
    $snap = rv_report($pdo, '2000-01-01', date('Y-m-d'));
    $snap['daily'] = []; // keep the archive small
    $now = rv_now();
    $pdo->prepare('INSERT INTO revenue_resets (reset_at, prev_since, admin_id, admin_name, label, snapshot, purge_after) VALUES (?,?,?,?,?,?,?)')
        ->execute([$now, rv_since($pdo), $adminId, mb_substr($adminName, 0, 120), mb_substr($label, 0, 120), json_encode($snap), date('Y-m-d H:i:s', strtotime('+' . RV_HOLD_DAYS . ' days'))]);
    ops_setting_set($pdo, 'rev_since', $now);
    return '';
}

function rv_restore(PDO $pdo, int $id): string {
    rv_ensure($pdo);
    $q = $pdo->prepare('SELECT * FROM revenue_resets WHERE id = ?'); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'Not found.';
    if ($r['restored_at'] !== null) return 'Already restored.';
    if ($r['purged_at'] !== null) return 'The 30 days are over, this can no longer be restored.';
    $latest = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM revenue_resets WHERE restored_at IS NULL')->fetchColumn();
    if ($latest !== $id) return 'Restore the newest clear first.';
    ops_setting_set($pdo, 'rev_since', (string) $r['prev_since']);
    $pdo->prepare('UPDATE revenue_resets SET restored_at = ? WHERE id = ?')->execute([rv_now(), $id]);
    return '';
}

// ------------------------------------------------------------------ test orders (recycle bin)
const RV_ORDER_TABLES = ['order_items', 'order_notes', 'order_refunds', 'order_events', 'order_payments', 'order_notified', 'seller_order_approvals', 'coupon_uses'];

function rv_order_counts(PDO $pdo): array {
    try { $r = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM orders')->fetch(PDO::FETCH_ASSOC); return ['n' => (int) $r['n'], 'total' => (float) $r['t']]; } catch (Throwable $e) { return ['n' => 0, 'total' => 0.0]; }
}

/** Move every order (and its items, payments, refunds, notes...) to the recycle bin for 30 days. */
function rv_trash_all(PDO $pdo, int $adminId, string $adminName): string {
    rv_ensure($pdo);
    $orders = $pdo->query('SELECT * FROM orders')->fetchAll(PDO::FETCH_ASSOC);
    if (!$orders) return 'There are no orders to delete.';
    $ids = array_map('intval', array_column($orders, 'id'));
    $in = implode(',', $ids);
    $data = ['orders' => $orders];
    $tables = RV_ORDER_TABLES;
    try { // any other table that points at an order (so nothing blocks the delete)
        foreach ($pdo->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'order_id'")->fetchAll(PDO::FETCH_COLUMN) as $t0) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $t0) && !in_array($t0, ['orders', 'order_trash', 'revenue_resets'], true) && !in_array($t0, $tables, true)) $tables[] = (string) $t0;
        }
    } catch (Throwable $e) { /* use the known list */ }
    foreach ($tables as $t) {
        try { $data[$t] = $pdo->query("SELECT * FROM $t WHERE order_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { /* table not there */ }
    }
    $total = array_sum(array_map(fn($o) => (float) $o['total'], $orders));
    $json = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || $json === '') return 'Could not save the recycle bin copy, nothing was deleted.';
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO order_trash (created_at, admin_id, admin_name, n, total, payload, purge_after) VALUES (?,?,?,?,?,?,?)')
            ->execute([rv_now(), $adminId, mb_substr($adminName, 0, 120), count($orders), $total, $json, date('Y-m-d H:i:s', strtotime('+' . RV_HOLD_DAYS . ' days'))]);
        foreach (array_keys($data) as $t) if ($t !== 'orders') $pdo->exec("DELETE FROM $t WHERE order_id IN ($in)");
        $pdo->exec("DELETE FROM orders WHERE id IN ($in)");
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return 'Could not delete the orders: ' . $e->getMessage(); }
    return '';
}

function rv_trash_list(PDO $pdo): array {
    rv_ensure($pdo); rv_purge($pdo);
    try { $rows = $pdo->query('SELECT id, created_at, admin_name, n, total, purge_after, restored_at, purged_at FROM order_trash ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $live = $r['restored_at'] === null && $r['purged_at'] === null;
        $r = ['id' => (int) $r['id'], 'created_at' => $r['created_at'], 'admin' => $r['admin_name'], 'n' => (int) $r['n'], 'total' => (float) $r['total'],
              'purge_after' => $r['purge_after'], 'restored_at' => $r['restored_at'], 'purged_at' => $r['purged_at'], 'can_restore' => $live,
              'days_left' => $live ? max(0, (int) ceil((strtotime((string) $r['purge_after']) - time()) / 86400)) : 0];
    }
    return $rows;
}

function rv_trash_restore(PDO $pdo, int $id): string {
    rv_ensure($pdo);
    $q = $pdo->prepare('SELECT payload, restored_at, purged_at FROM order_trash WHERE id = ?'); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'Not found.';
    if ($r['restored_at'] !== null) return 'Already restored.';
    if ($r['purged_at'] !== null || !$r['payload']) return 'The 30 days are over, this can no longer be restored.';
    $data = json_decode((string) $r['payload'], true);
    if (!is_array($data) || empty($data['orders'])) return 'The saved copy is unreadable.';
    $pdo->beginTransaction();
    try {
        foreach (array_merge(['orders'], array_diff(array_keys($data), ['orders'])) as $t) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $t)) continue;
            foreach ($data[$t] ?? [] as $row) {
                $cols = array_keys($row);
                $sql = "INSERT INTO $t (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($row));
            }
        }
        $pdo->prepare('UPDATE order_trash SET restored_at = ?, payload = NULL WHERE id = ?')->execute([rv_now(), $id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return 'Could not restore (an order number may already exist): ' . $e->getMessage(); }
    return '';
}
