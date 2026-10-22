<?php
// Order operations for admins: delivery tracking, refund records, cancelling one item of an order.
// Every function returns '' on success or a message to show.

function oo_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('ops_setting') && ops_setting($pdo, 'schema_oo', '') === '1') return;
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_courier VARCHAR(60) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_no VARCHAR(80) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_note VARCHAR(200) NOT NULL DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_refunds (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, reason VARCHAR(200) NOT NULL DEFAULT '', method VARCHAR(40) NOT NULL DEFAULT '', admin_id INT NOT NULL DEFAULT 0, admin_name VARCHAR(120) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        if (function_exists('ops_setting_set')) ops_setting_set($pdo, 'schema_oo', '1');
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
    if ($o['status'] === 'pending') return 'Nothing was paid yet: this order is still pending.';
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
    if ($own) $pdo->beginTransaction();
    try {
        if (!empty($target['stock_reserved'])) $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$target['quantity'], $target['product_id']]);
        $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$target['id']]);
        $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?')->execute([round($newSub + $delivery, 2), $orderId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Could not cancel the item.';
    }
    oo_note($pdo, $adminId, $orderId, 'Cancelled item: ' . $target['name'] . ' × ' . (int) $target['quantity'] . ' (' . number_format($line, 2) . '). New total ' . number_format($newSub + $delivery, 2) . '.');
    oo_log($pdo, $adminId, 'order_item_cancel', '#' . $orderId . ' product ' . $productId);
    require_once __DIR__ . '/notify.php';
    $od = notify_order_data($pdo, $orderId);
    if ($od && $od['cemail'] !== '') {
        notify_queue($pdo, 'item_cancel:' . $orderId . ':' . $productId . ':' . $target['id'], 'customer', (string) $od['cemail'], (string) $od['cname'], 'An item was removed from order #' . $orderId,
            notify_wrap('An item was removed', '<p>Hello ' . notify_esc($od['cname']) . ',</p><p>We removed <strong>' . notify_esc($target['name']) . '</strong> from order <strong>#' . $orderId . '</strong>.</p>' . notify_lines_html($od['lines']) . '<p><strong>New total: ' . notify_money($od['total']) . '</strong></p>'));
    }
    return '';
}
