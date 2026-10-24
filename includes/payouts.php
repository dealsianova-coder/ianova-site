<?php
/**
 * Seller earnings and payouts.
 * A seller earns (item price x quantity) minus the store's commission once an order is DELIVERED.
 * Refunds on delivered orders reduce the earnings in proportion to the seller's share of the order.
 * Coupons are paid by the store, so they never reduce what the seller earns.
 * The admin pays sellers outside the system (M-Pesa, bank) and records each payout here.
 */
require_once __DIR__ . '/seller_ops.php';
require_once __DIR__ . '/order_ops.php';

function po_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        oo_ensure($pdo);
        if (ops_setting($pdo, 'schema_po', '') === '1') return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS seller_payouts (id INT AUTO_INCREMENT PRIMARY KEY, seller_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, method VARCHAR(40) NOT NULL DEFAULT '', reference VARCHAR(80) NOT NULL DEFAULT '', note VARCHAR(200) NOT NULL DEFAULT '', admin_id INT NOT NULL DEFAULT 0, admin_name VARCHAR(120) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (seller_id)) ENGINE=InnoDB");
        $pdo->exec("ALTER TABLE sellers ADD COLUMN IF NOT EXISTS commission_pct DECIMAL(5,2) NULL");
        ops_setting_set($pdo, 'schema_po', '1');
    } catch (Throwable $e) { /* already there */ }
}

function po_default_rate(PDO $pdo): float {
    return max(0.0, min(100.0, (float) ops_setting($pdo, 'commission_pct', '10')));
}

function po_rate(PDO $pdo, int $sellerId): float {
    po_ensure($pdo);
    $q = $pdo->prepare('SELECT commission_pct FROM sellers WHERE id = ?'); $q->execute([$sellerId]);
    $v = $q->fetchColumn();
    return ($v === false || $v === null || $v === '') ? po_default_rate($pdo) : max(0.0, min(100.0, (float) $v));
}

/** Money numbers for one seller, or for every seller when $sellerId = 0 (keyed by seller id). */
function po_figures(PDO $pdo, int $sellerId = 0): array {
    po_ensure($pdo);
    $out = [];
    $blank = fn() => ['gross' => 0.0, 'refunded' => 0.0, 'coming' => 0.0, 'paid' => 0.0];
    // delivered orders: what each seller sold in them, and the refunds on the order
    $sql = "SELECT p.seller_id sid, o.id oid, SUM(oi.quantity * oi.unit_price) mine,
                   (SELECT COALESCE(SUM(oi2.quantity * oi2.unit_price), 0) FROM order_items oi2 WHERE oi2.order_id = o.id) allsum,
                   (SELECT COALESCE(SUM(r.amount), 0) FROM order_refunds r WHERE r.order_id = o.id) refunded
            FROM orders o JOIN order_items oi ON oi.order_id = o.id JOIN products p ON p.id = oi.product_id
            WHERE o.status = 'delivered' AND p.seller_id IS NOT NULL" . ($sellerId ? ' AND p.seller_id = ' . (int) $sellerId : '') . "
            GROUP BY p.seller_id, o.id";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int) $r['sid']; $out[$sid] = $out[$sid] ?? $blank();
        $mine = (float) $r['mine']; $all = (float) $r['allsum'];
        $out[$sid]['gross'] += $mine;
        if ($all > 0 && (float) $r['refunded'] > 0) $out[$sid]['refunded'] += min($mine, (float) $r['refunded'] * $mine / $all);
    }
    // sold but not delivered yet (not cancelled)
    $sql = "SELECT p.seller_id sid, SUM(oi.quantity * oi.unit_price) mine FROM orders o JOIN order_items oi ON oi.order_id = o.id JOIN products p ON p.id = oi.product_id
            WHERE o.status IN ('pending','approved','paid','shipped') AND p.seller_id IS NOT NULL" . ($sellerId ? ' AND p.seller_id = ' . (int) $sellerId : '') . " GROUP BY p.seller_id";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) { $sid = (int) $r['sid']; $out[$sid] = $out[$sid] ?? $blank(); $out[$sid]['coming'] += (float) $r['mine']; }
    $sql = 'SELECT seller_id sid, SUM(amount) paid FROM seller_payouts' . ($sellerId ? ' WHERE seller_id = ' . (int) $sellerId : '') . ' GROUP BY seller_id';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) { $sid = (int) $r['sid']; $out[$sid] = $out[$sid] ?? $blank(); $out[$sid]['paid'] += (float) $r['paid']; }
    if ($sellerId && !isset($out[$sellerId])) $out[$sellerId] = $blank();
    $def = po_default_rate($pdo);
    $rates = [];
    foreach ($pdo->query('SELECT id, commission_pct FROM sellers')->fetchAll(PDO::FETCH_ASSOC) as $s) $rates[(int) $s['id']] = ($s['commission_pct'] === null || $s['commission_pct'] === '') ? null : (float) $s['commission_pct'];
    foreach ($out as $sid => &$f) {
        $rate = $rates[$sid] ?? $def; $keep = (100 - $rate) / 100;
        $net = max(0.0, $f['gross'] - $f['refunded']);
        $f = ['rate' => $rate, 'custom_rate' => ($rates[$sid] ?? null) !== null, 'gross' => round($f['gross'], 2), 'refunded' => round($f['refunded'], 2),
              'commission' => round($net * $rate / 100, 2), 'earned' => round($net * $keep, 2), 'paid' => round($f['paid'], 2),
              'balance' => round($net * $keep - $f['paid'], 2), 'coming' => round($f['coming'] * $keep, 2)];
    }
    return $out;
}

function po_history(PDO $pdo, int $sellerId, int $limit = 50): array {
    po_ensure($pdo);
    $q = $pdo->prepare('SELECT id, amount, method, reference, note, admin_name, created_at FROM seller_payouts WHERE seller_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
    $q->execute([$sellerId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['amount'] = (float) $r['amount']; }
    return $rows;
}

/** All approved/suspended sellers with their money numbers, biggest balance first. */
function po_overview(PDO $pdo): array {
    po_ensure($pdo);
    $fig = po_figures($pdo, 0);
    $rows = [];
    foreach ($pdo->query("SELECT id, business_name, email FROM sellers ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sid = (int) $s['id'];
        $f = $fig[$sid] ?? ['rate' => po_rate($pdo, $sid), 'custom_rate' => false, 'gross' => 0, 'refunded' => 0, 'commission' => 0, 'earned' => 0, 'paid' => 0, 'balance' => 0, 'coming' => 0];
        $rows[] = ['id' => $sid, 'name' => (string) $s['business_name'], 'email' => (string) $s['email']] + $f;
    }
    usort($rows, fn($a, $b) => $b['balance'] <=> $a['balance']);
    return $rows;
}

/** Record a payout the admin made. Returns '' or a message. */
function po_add(PDO $pdo, int $adminId, int $sellerId, float $amount, string $method, string $reference, string $note): string {
    po_ensure($pdo);
    $s = $pdo->prepare('SELECT business_name, email FROM sellers WHERE id = ?'); $s->execute([$sellerId]);
    $sel = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sel) return 'Seller not found.';
    $amount = round($amount, 2);
    if ($amount <= 0) return 'Enter an amount above 0.';
    $f = po_figures($pdo, $sellerId)[$sellerId];
    if ($amount > $f['balance'] + 0.009) return 'That is more than the balance owed (' . number_format($f['balance'], 2) . ').';
    $an = (string) $pdo->query('SELECT name FROM admins WHERE id = ' . (int) $adminId)->fetchColumn();
    $pdo->prepare('INSERT INTO seller_payouts (seller_id, amount, method, reference, note, admin_id, admin_name) VALUES (?,?,?,?,?,?,?)')
        ->execute([$sellerId, $amount, mb_substr(trim($method), 0, 40), mb_substr(trim($reference), 0, 80), mb_substr(trim($note), 0, 200), $adminId, $an]);
    $pid = (int) $pdo->lastInsertId();
    try {
        require_once __DIR__ . '/admin_team.php';
        team_log($pdo, $adminId, 'payout', 'seller ' . $sellerId . ' ' . number_format($amount, 2));
        require_once __DIR__ . '/notify.php';
        $left = round($f['balance'] - $amount, 2);
        notify_queue($pdo, 'payout:' . $pid, 'seller', (string) $sel['email'], (string) $sel['business_name'], 'Payment sent: ' . notify_money($amount),
            notify_wrap('You have been paid', '<p>Hello ' . notify_esc($sel['business_name']) . ',</p><p>We sent you <strong>' . notify_money($amount) . '</strong>'
                . (trim($method) !== '' ? ' by ' . notify_esc(trim($method)) : '') . (trim($reference) !== '' ? ' (reference ' . notify_esc(trim($reference)) . ')' : '') . '.</p>'
                . '<p>Balance still owed to you: <strong>' . notify_money($left) . '</strong>.</p>'));
    } catch (Throwable $e) { /* the payout is saved even if the email fails */ }
    return '';
}

/** Commission %: seller id 0 = store default, otherwise that seller (null/'' = use the default). */
function po_set_rate(PDO $pdo, int $sellerId, $pct): string {
    po_ensure($pdo);
    if ($pct === null || $pct === '') {
        if ($sellerId === 0) return 'Enter a percentage.';
        $pdo->prepare('UPDATE sellers SET commission_pct = NULL WHERE id = ?')->execute([$sellerId]); return '';
    }
    $p = (float) $pct;
    if ($p < 0 || $p > 100) return 'The commission must be between 0 and 100.';
    if ($sellerId === 0) ops_setting_set($pdo, 'commission_pct', (string) round($p, 2));
    else $pdo->prepare('UPDATE sellers SET commission_pct = ? WHERE id = ?')->execute([round($p, 2), $sellerId]);
    return '';
}
