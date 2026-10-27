<?php
// Order operations for admins: delivery tracking, refund records, cancelling one item of an order.
// Every function returns '' on success or a message to show.

function oo_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('ops_setting') && ops_setting($pdo, 'schema_oo', '') === '3') return;
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_courier VARCHAR(60) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_no VARCHAR(80) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_note VARCHAR(200) NOT NULL DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_refunds (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, reason VARCHAR(200) NOT NULL DEFAULT '', method VARCHAR(40) NOT NULL DEFAULT '', admin_id INT NOT NULL DEFAULT 0, admin_name VARCHAR(120) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        $pdo->exec("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS item_status VARCHAR(12) NOT NULL DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_events (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, status VARCHAR(20) NOT NULL, admin_id INT NOT NULL DEFAULT 0, admin_name VARCHAR(120) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        if (function_exists('ops_setting_set')) ops_setting_set($pdo, 'schema_oo', '3');
    } catch (Throwable $e) {
    }
}

function oo_admin_name(PDO $pdo, int $adminId): string {
    $n = $pdo->prepare('SELECT name FROM admins WHERE id = ?'); $n->execute([$adminId]);
    return (string) $n->fetchColumn();
}

function oo_note(PDO $pdo, int $adminId, int $orderId, string $text): void {
    $pdo->prepare('INSERT INTO order_notes (order_id, admin_id, admin_name, note) VALUES (?, ?, ?, ?)')->execute([$orderId, $adminId, oo_admin_name($pdo, $adminId), mb_substr($text, 0, 1000)]);
}

function oo_log(PDO $pdo, int $adminId, string $action, string $detail): void {
    if (function_exists('team_log')) team_log($pdo, $adminId, $action, $detail);
}

/** Tracking + refunds of one order, for the order page and the API. */
function oo_extras(PDO $pdo, int $orderId): array {
    oo_ensure($pdo);
    $t = $pdo->prepare('SELECT tracking_courier, tracking_no, tracking_note FROM orders WHERE id = ?'); $t->execute([$orderId]);
    $row = $t->fetch(PDO::FETCH_ASSOC) ?: ['tracking_courier' => '', 'tracking_no' => '', 'tracking_note' => ''];
    $r = $pdo->prepare('SELECT id, amount, reason, method, admin_name, created_at FROM order_refunds WHERE order_id = ? ORDER BY id DESC'); $r->execute([$orderId]);
    $refunds = $r->fetchAll(PDO::FETCH_ASSOC);
    $sum = 0.0; foreach ($refunds as $x) $sum += (float) $x['amount'];
    return ['tracking' => ['courier' => (string) $row['tracking_courier'], 'number' => (string) $row['tracking_no'], 'note' => (string) $row['tracking_note']], 'refunds' => $refunds, 'refunded' => round($sum, 2)];
}

function oo_tracking_save(PDO $pdo, int $adminId, int $orderId, string $courier, string $number, string $note): string {
    oo_ensure($pdo);
    $courier = mb_substr(trim($courier), 0, 60); $number = mb_substr(trim($number), 0, 80); $note = mb_substr(trim($note), 0, 200);
    $q = $pdo->prepare('SELECT status FROM orders WHERE id = ?'); $q->execute([$orderId]);
    $status = $q->fetchColumn();
    if ($status === false) return 'Order not found.';
    if ($status === 'cancelled') return 'This order was cancelled.';
    $pdo->prepare('UPDATE orders SET tracking_courier = ?, tracking_no = ?, tracking_note = ? WHERE id = ?')->execute([$courier, $number, $note, $orderId]);
    oo_log($pdo, $adminId, 'order_tracking', '#' . $orderId . ' ' . $courier . ' ' . $number);
    if ($number !== '' || $note !== '') {   // tell the customer once per change
        require_once __DIR__ . '/notify.php';
        $o = notify_order_data($pdo, $orderId);
        if ($o && $o['cemail'] !== '') {
            $inner = '<p>Hello ' . notify_esc($o['cname']) . ',</p><p>Delivery update for order <strong>#' . $orderId . '</strong>:</p><table style="border-collapse:collapse;margin:8px 0">'
                . ($courier !== '' ? '<tr><td style="padding:4px 12px 4px 0;color:#6B7973">Courier</td><td><strong>' . notify_esc($courier) . '</strong></td></tr>' : '')
                . ($number !== '' ? '<tr><td style="padding:4px 12px 4px 0;color:#6B7973">Tracking number</td><td><strong>' . notify_esc($number) . '</strong></td></tr>' : '')
                . ($note !== '' ? '<tr><td style="padding:4px 12px 4px 0;color:#6B7973">Note</td><td>' . notify_esc($note) . '</td></tr>' : '') . '</table>';
            notify_queue($pdo, 'tracking:' . $orderId . ':' . md5($courier . '|' . $number . '|' . $note), 'customer', (string) $o['cemail'], (string) $o['cname'], 'Delivery update — order #' . $orderId, notify_wrap('Delivery update', $inner));
        }
    }
    return '';
}

function oo_refund_add(PDO $pdo, int $adminId, int $orderId, float $amount, string $reason, string $method): string {
    oo_ensure($pdo);
    $q = $pdo->prepare('SELECT status, total FROM orders WHERE id = ?'); $q->execute([$orderId]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return 'Order not found.';
    if ($o['status'] === 'pending') {   // a pending order can still have a deposit recorded
        $hasPay = false;
        try { $hasPay = (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM order_payments WHERE order_id = ' . (int) $orderId)->fetchColumn() > 0; } catch (Throwable $e) { $hasPay = false; }
        if (!$hasPay) return 'Nothing was paid yet: this order is still pending.';
    }
    $amount = round($amount, 2);
    if ($amount <= 0) return 'Enter an amount above zero.';
    $e = oo_extras($pdo, $orderId);
    $left = round((float) $o['total'] - $e['refunded'], 2);
    if ($amount > $left + 0.001) return 'You can refund at most ' . number_format($left, 2) . ' for this order.';
    $reason = mb_substr(trim($reason), 0, 200); $method = mb_substr(trim($method), 0, 40);
    $pdo->prepare('INSERT INTO order_refunds (order_id, amount, reason, method, admin_id, admin_name) VALUES (?, ?, ?, ?, ?, ?)')->execute([$orderId, $amount, $reason, $method, $adminId, oo_admin_name($pdo, $adminId)]);
    $rid = (int) $pdo->lastInsertId();
    oo_note($pdo, $adminId, $orderId, 'Refund recorded: ' . number_format($amount, 2) . ($method !== '' ? ' via ' . $method : '') . ($reason !== '' ? ' (' . $reason . ')' : ''));
    oo_log($pdo, $adminId, 'order_refund', '#' . $orderId . ' ' . number_format($amount, 2));
    require_once __DIR__ . '/notify.php';
    $od = notify_order_data($pdo, $orderId);
    if ($od && $od['cemail'] !== '') {
        notify_queue($pdo, 'refund:' . $orderId . ':' . $rid, 'customer', (string) $od['cemail'], (string) $od['cname'], 'Refund for order #' . $orderId,
            notify_wrap('Your refund', '<p>Hello ' . notify_esc($od['cname']) . ',</p><p>We refunded <strong>' . notify_money($amount) . '</strong> for order <strong>#' . $orderId . '</strong>' . ($method !== '' ? ' via ' . notify_esc($method) : '') . '.' . ($reason !== '' ? '<br>Reason: ' . notify_esc($reason) : '') . '</p><p>It can take a little while to show on your side.</p>'));
    }
    return '';
}

/** Cancel one item of an order (before it ships). The stock goes back and the total is worked out again. */
function oo_item_cancel(PDO $pdo, int $adminId, int $orderId, int $productId): string {
    oo_ensure($pdo);
    $q = $pdo->prepare('SELECT status, total FROM orders WHERE id = ?'); $q->execute([$orderId]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return 'Order not found.';
    if (!in_array($o['status'], ['pending', 'approved', 'paid'], true)) return 'Items can only be cancelled before the order ships.';
    $it = $pdo->prepare('SELECT oi.id, oi.product_id, oi.quantity, oi.unit_price, oi.stock_reserved, COALESCE(p.name, "Item") name FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id');
    $it->execute([$orderId]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);
    $target = null; $oldSub = 0.0;
    foreach ($items as $x) { $oldSub += (float) $x['unit_price'] * (int) $x['quantity']; if ((int) $x['product_id'] === $productId && $target === null) $target = $x; }
    if (!$target) return 'That item is not in this order.';
    if (count($items) === 1) {   // the only item: cancelling it cancels the whole order (and returns the stock)
        require_once __DIR__ . '/seller_orders.php';
        $m = set_order_status($pdo, $orderId, 'cancelled');
        if ($m === '') { oo_note($pdo, $adminId, $orderId, 'Cancelled the only item (' . $target['name'] . '), so the whole order was cancelled.'); oo_log($pdo, $adminId, 'order_item_cancel', '#' . $orderId . ' (whole order)'); }
        return $m;
    }
    $line = (float) $target['unit_price'] * (int) $target['quantity'];
    $newSub = round($oldSub - $line, 2);
    $delivery = max(0.0, round((float) $o['total'] - $oldSub, 2));
    if (function_exists('store_delivery_fee') && function_exists('store_free_delivery_threshold')) $delivery = $newSub < store_free_delivery_threshold($pdo) ? store_delivery_fee($pdo) : 0.0;
    $own = !$pdo->inTransaction();
    $disc = 0.0;
    if ($own) $pdo->beginTransaction();
    try {
        if (!empty($target['stock_reserved'])) $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$target['quantity'], $target['product_id']]);
        $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$target['id']]);
        require_once __DIR__ . '/coupons.php';
        $disc = cp_reprice($pdo, $orderId, $newSub, $delivery);   // a coupon on this order is recalculated for the smaller order
        $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?')->execute([round($newSub + $delivery - $disc, 2), $orderId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Could not cancel the item.';
    }
    oo_note($pdo, $adminId, $orderId, 'Cancelled item: ' . $target['name'] . ' × ' . (int) $target['quantity'] . ' (' . number_format($line, 2) . '). New total ' . number_format($newSub + $delivery - $disc, 2) . '.');
    oo_log($pdo, $adminId, 'order_item_cancel', '#' . $orderId . ' product ' . $productId);
    require_once __DIR__ . '/notify.php';
    $od = notify_order_data($pdo, $orderId);
    if ($od && $od['cemail'] !== '') {
        notify_queue($pdo, 'item_cancel:' . $orderId . ':' . $productId . ':' . $target['id'], 'customer', (string) $od['cemail'], (string) $od['cname'], 'An item was removed from order #' . $orderId,
            notify_wrap('An item was removed', '<p>Hello ' . notify_esc($od['cname']) . ',</p><p>We removed <strong>' . notify_esc($target['name']) . '</strong> from order <strong>#' . $orderId . '</strong>.</p>' . notify_lines_html($od['lines']) . '<p><strong>New total: ' . notify_money($od['total']) . '</strong></p>'));
    }
    return '';
}

/** product_id => 'packed'|'shipped'|'delivered' for the items of one order that have their own status. */
function oo_item_statuses(PDO $pdo, int $orderId): array {
    oo_ensure($pdo);
    $o = [];
    try {
        $q = $pdo->prepare('SELECT product_id, item_status FROM order_items WHERE order_id = ?'); $q->execute([$orderId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if ((string) $r['item_status'] !== '') $o[(int) $r['product_id']] = (string) $r['item_status'];
    } catch (Throwable $e) {
    }
    return $o;
}

/** Give one item its own progress ('' clears it). Cancelled orders cannot change. '' on success, otherwise the message. */
function oo_item_status(PDO $pdo, int $adminId, int $orderId, int $productId, string $status): string {
    oo_ensure($pdo);
    if (!in_array($status, ['', 'packed', 'shipped', 'delivered'], true)) return 'Unknown item status.';
    $q = $pdo->prepare('SELECT status FROM orders WHERE id = ?'); $q->execute([$orderId]);
    $st = $q->fetchColumn();
    if ($st === false) return 'Order not found.';
    if ($st === 'cancelled') return 'This order is cancelled and cannot be changed.';
    if ($st === 'pending') return 'Approve the order before packing or shipping items.';
    $n = $pdo->prepare('SELECT COALESCE(p.name, "Item") FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND oi.product_id = ? LIMIT 1');
    $n->execute([$orderId, $productId]);
    $name = $n->fetchColumn();
    if ($name === false) return 'That item is not in this order.';
    $pdo->prepare('UPDATE order_items SET item_status = ? WHERE order_id = ? AND product_id = ?')->execute([$status, $orderId, $productId]);
    oo_note($pdo, $adminId, $orderId, 'Item "' . $name . '" ' . ($status === '' ? 'status cleared.' : 'marked ' . $status . '.'));
    oo_item_advance($pdo, $adminId, $orderId);
    return '';
}

/** When every item is shipped (or delivered) the order moves to Shipped; when every item is delivered, to Delivered. Only moves forward. */
function oo_item_advance(PDO $pdo, int $adminId, int $orderId): void {
    $q = $pdo->prepare('SELECT status FROM orders WHERE id = ?'); $q->execute([$orderId]);
    $cur = (string) $q->fetchColumn();
    $rank = ['approved' => 1, 'paid' => 2, 'shipped' => 3, 'delivered' => 4];
    if (!isset($rank[$cur])) return;
    $c = $pdo->prepare("SELECT COUNT(*), SUM(item_status = 'delivered'), SUM(item_status IN ('shipped', 'delivered')) FROM order_items WHERE order_id = ?");
    $c->execute([$orderId]);
    [$all, $del, $shp] = array_map('intval', $c->fetch(PDO::FETCH_NUM));
    if ($all < 1) return;
    $target = $del === $all ? 'delivered' : ($shp === $all ? 'shipped' : '');
    if ($target === '' || $rank[$target] <= $rank[$cur]) return;
    require_once __DIR__ . '/seller_orders.php';
    if (set_order_status($pdo, $orderId, $target) === '') oo_note($pdo, $adminId, $orderId, 'All items are ' . $target . ', so the order was marked ' . $target . '.');
}

/** Remember an order status change (who and when). No table changes here: this runs inside the status change itself. */
function oo_event(PDO $pdo, int $orderId, string $status): void {
    try {
        $aid = (int) ($GLOBALS['ORDER_ACTOR_ID'] ?? ($_SESSION['admin_id'] ?? 0));
        $name = $aid > 0 ? oo_admin_name($pdo, $aid) : '';
        $pdo->prepare('INSERT INTO order_events (order_id, status, admin_id, admin_name) VALUES (?, ?, ?, ?)')->execute([$orderId, $status, $aid, $name]);
    } catch (Throwable $e) {
    }
}

/** The story of one order, oldest first: placed, status changes, payments, refunds and notes. */
function oo_timeline(PDO $pdo, int $orderId): array {
    oo_ensure($pdo);
    $out = [];
    $one = function (string $sql) use ($pdo, $orderId): array {
        try { $q = $pdo->prepare($sql); $q->execute([$orderId]); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
    };
    foreach ($one('SELECT created_at FROM orders WHERE id = ?') as $r) $out[] = ['at' => (string) $r['created_at'], 'icon' => '🛒', 'title' => 'Order placed', 'detail' => '', 'by' => ''];
    $ic = ['pending' => '⏳', 'approved' => '✅', 'paid' => '💰', 'shipped' => '🚚', 'delivered' => '📦', 'cancelled' => '⛔'];
    foreach ($one('SELECT status, admin_name, created_at FROM order_events WHERE order_id = ?') as $r) $out[] = ['at' => (string) $r['created_at'], 'icon' => $ic[$r['status']] ?? '🔄', 'title' => 'Status: ' . ucfirst((string) $r['status']), 'detail' => '', 'by' => (string) $r['admin_name']];
    foreach ($one('SELECT amount, method, reference, admin_name, created_at FROM order_payments WHERE order_id = ?') as $r) $out[] = ['at' => (string) $r['created_at'], 'icon' => '💳', 'title' => 'Payment received ' . number_format((float) $r['amount'], 2), 'detail' => trim($r['method'] . ' ' . $r['reference']), 'by' => (string) $r['admin_name']];
    foreach ($one('SELECT amount, reason, method, admin_name, created_at FROM order_refunds WHERE order_id = ?') as $r) $out[] = ['at' => (string) $r['created_at'], 'icon' => '↩️', 'title' => 'Refund ' . number_format((float) $r['amount'], 2), 'detail' => trim($r['method'] . ' ' . $r['reason']), 'by' => (string) $r['admin_name']];
    foreach ($one('SELECT note, admin_name, created_at FROM order_notes WHERE order_id = ?') as $r) $out[] = ['at' => (string) $r['created_at'], 'icon' => '📝', 'title' => (string) $r['note'], 'detail' => '', 'by' => (string) $r['admin_name']];
    usort($out, fn($a, $b) => strcmp($a['at'], $b['at']));
    return $out;
}

/** Move several orders forward in one go. Only forward to approved / shipped / delivered; cancelled orders and orders already past that step are skipped. Returns [updated, skipped]. */
function oo_bulk_status(PDO $pdo, int $adminId, array $ids, string $target): array {
    $rank = ['pending' => 0, 'approved' => 1, 'paid' => 2, 'shipped' => 3, 'delivered' => 4];
    if (!in_array($target, ['approved', 'shipped', 'delivered'], true)) return [0, 0];
    $GLOBALS['ORDER_ACTOR_ID'] = $adminId;
    require_once __DIR__ . '/seller_orders.php';
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0))), 0, 100);
    $ok = 0; $skip = 0;
    $q = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    foreach ($ids as $id) {
        $q->execute([$id]); $cur = $q->fetchColumn();
        if ($cur === false || !isset($rank[$cur]) || $rank[$cur] >= $rank[$target]) { $skip++; continue; }
        if (set_order_status($pdo, $id, $target) === '') $ok++; else $skip++;
    }
    return [$ok, $skip];
}

/** The customer's view of an order's story: placed, status changes, payments and refunds. No internal notes and no admin names. */
function oo_customer_timeline(PDO $pdo, int $orderId): array {
    $out = [];
    foreach (oo_timeline($pdo, $orderId) as $e) {
        if ($e['icon'] === '📝') continue;
        $out[] = ['icon' => $e['icon'], 'title' => $e['title'], 'detail' => $e['detail'], 'at' => $e['at']];
    }
    return $out;
}
