<?php
/**
 * Stock helpers. Every checkout takes stock when the order is placed, so each order
 * item is marked stock_reserved (default 1 for new rows). Cancelling gives it back.
 * Seller approval itself lives in includes/seller_ops.php.
 */
function ensure_seller_orders(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $col = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'stock_reserved'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE order_items ADD COLUMN stock_reserved TINYINT(1) NOT NULL DEFAULT 1');
    } elseif ((string) $col['Default'] !== '1') {
        $pdo->exec('ALTER TABLE order_items MODIFY stock_reserved TINYINT(1) NOT NULL DEFAULT 1');
    }
    $done = true;
}

/** Thrown when an order cannot be placed or changed because stock ran out. */
class OutOfStockException extends Exception {}

/** Give the held stock of a whole order back to the shelf (used when it is cancelled). */
function release_order_stock(PDO $pdo, int $orderId): void {
    ensure_seller_orders($pdo);
    $q = $pdo->prepare('SELECT id, product_id, quantity FROM order_items WHERE order_id = ? AND stock_reserved = 1');
    $q->execute([$orderId]);
    foreach ($q->fetchAll() as $it) {
        $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$it['quantity'], $it['product_id']]);
        $pdo->prepare('UPDATE order_items SET stock_reserved = 0 WHERE id = ?')->execute([$it['id']]);
    }
}

/**
 * The one place that changes an order's status. Cancelling returns the stock.
 * A cancelled order cannot be reopened (its stock was already given back).
 * Returns '' on success or a message.
 */
function set_order_status(PDO $pdo, int $orderId, string $status): string {
    $allowed = ['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'];
    if (!in_array($status, $allowed, true)) return 'Invalid status.';
    $q = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $q->execute([$orderId]);
    $old = $q->fetchColumn();
    if ($old === false) return 'Order not found.';
    if ($old === $status) return '';
    if ($old === 'cancelled') return 'A cancelled order cannot be reopened. Ask the customer to place a new order.';

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
        if ($status === 'cancelled') { release_order_stock($pdo, $orderId); require_once __DIR__ . '/coupons.php'; cp_release($pdo, $orderId); }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Could not update the order.';
    }
    return '';
}
