<?php
/**
 * Seller order approval.
 *
 * Every order item carries a seller_status (pending / approved / rejected) and a
 * stock_reserved flag. Items sold by the store itself (no seller) are approved
 * automatically and never need a seller. Columns are created on first use.
 */
require_once __DIR__ . '/mailer.php';

function ensure_seller_orders(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $has = static function (string $table, string $col) use ($pdo): bool {
        return (bool) $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
    };
    if (!$has('sellers', 'auto_approve_orders')) {
        $pdo->exec('ALTER TABLE sellers ADD COLUMN auto_approve_orders TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!$has('order_items', 'seller_status')) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN seller_status VARCHAR(10) NOT NULL DEFAULT 'pending'");
        $pdo->exec('ALTER TABLE order_items ADD COLUMN stock_reserved TINYINT(1) NOT NULL DEFAULT 0');
        // Orders placed before this feature existed are already handled.
        $pdo->exec("UPDATE order_items SET seller_status = 'approved', stock_reserved = 1");
    }
    $done = true;
}

function seller_orders_wrap(string $inner): string {
    return '<div style="font-family:Arial,sans-serif;color:#0F0F12;max-width:520px;line-height:1.5;">' . $inner
        . '<p style="margin-top:24px;color:#5F6575;font-size:13px;">— ' . h(SITE_NAME) . '</p></div>';
}

function seller_orders_notify_admin(string $subject, string $inner): void {
    if (!defined('ADMIN_EMAIL') || ADMIN_EMAIL === '') return;
    try { send_mail(ADMIN_EMAIL, 'Admin', $subject, seller_orders_wrap($inner)); } catch (Throwable $e) { error_log('admin order mail: ' . $e->getMessage()); }
}

function seller_orders_items_html(array $items): string {
    $out = '<ul style="padding-left:18px">';
    foreach ($items as $i) $out .= '<li>' . (int) $i['quantity'] . ' × ' . h($i['name']) . '</li>';
    return $out . '</ul>';
}

/** Approve one pending item, reserving stock first if it was not reserved. Returns '' or an error text. */
function seller_item_approve(PDO $pdo, array $item): string {
    if (!(int) $item['stock_reserved']) {
        $u = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
        $u->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
        if ($u->rowCount() < 1) return 'Not enough stock for "' . $item['name'] . '".';
    }
    $pdo->prepare("UPDATE order_items SET seller_status = 'approved', stock_reserved = 1 WHERE id = ?")->execute([$item['id']]);
    return '';
}

function seller_item_reject(PDO $pdo, array $item): void {
    if ((int) $item['stock_reserved']) {
        $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$item['quantity'], $item['product_id']]);
    }
    $pdo->prepare("UPDATE order_items SET seller_status = 'rejected', stock_reserved = 0 WHERE id = ?")->execute([$item['id']]);
}

/**
 * Looks at every seller item in the order. When all of them are approved, the
 * order itself moves from pending to approved. Returns none|pending|rejected|approved.
 */
function seller_order_refresh(PDO $pdo, int $orderId): string {
    $q = $pdo->prepare('SELECT oi.seller_status, COALESCE(p.seller_id, 0) AS sid FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $q->execute([$orderId]);
    $hasSeller = false; $pending = 0; $rejected = 0;
    foreach ($q->fetchAll() as $r) {
        if ((int) $r['sid'] < 1) continue;
        $hasSeller = true;
        if ($r['seller_status'] === 'pending') $pending++;
        if ($r['seller_status'] === 'rejected') $rejected++;
    }
    if (!$hasSeller) return 'none';
    if ($pending > 0) return 'pending';
    if ($rejected > 0) return 'rejected';
    $pdo->prepare("UPDATE orders SET status = 'approved' WHERE id = ? AND status = 'pending'")->execute([$orderId]);
    return 'approved';
}

/**
 * Call once right after an order is saved.
 * $stockReserved: true when checkout already checked and took the stock (the app's
 * checkout does); false when it did not (the website checkout).
 * Items of sellers who turned on "auto-accept orders" are approved straight away
 * when the stock is available. Sellers and the admin are notified by email.
 */
function seller_orders_process(PDO $pdo, int $orderId, bool $stockReserved): void {
    ensure_seller_orders($pdo);

    $q = $pdo->prepare('SELECT oi.id, oi.product_id, oi.quantity, oi.seller_status, oi.stock_reserved, p.name, COALESCE(p.seller_id, 0) AS sid
                        FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $q->execute([$orderId]);
    $bySeller = [];
    foreach ($q->fetchAll() as $it) {
        if ((int) $it['sid'] < 1) {
            $pdo->prepare("UPDATE order_items SET seller_status = 'approved', stock_reserved = ? WHERE id = ?")->execute([$stockReserved ? 1 : 0, $it['id']]);
            continue;
        }
        if ($stockReserved) {
            $pdo->prepare('UPDATE order_items SET stock_reserved = 1 WHERE id = ?')->execute([$it['id']]);
            $it['stock_reserved'] = 1;
        }
        $bySeller[(int) $it['sid']][] = $it;
    }
    if (!$bySeller) return;

    $auto = [];
    foreach ($bySeller as $sid => $items) {
        $s = $pdo->prepare('SELECT business_name, email, auto_approve_orders FROM sellers WHERE id = ?');
        $s->execute([$sid]);
        $seller = $s->fetch();
        if (!$seller) continue;

        $approved = 0;
        if (!empty($seller['auto_approve_orders'])) {
            foreach ($items as $it) {
                $pdo->beginTransaction();
                try {
                    if (seller_item_approve($pdo, $it) === '') { $approved++; $pdo->commit(); } else { $pdo->rollBack(); }
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
            }
        }
        if ($approved > 0) $auto[] = $seller['business_name'];

        $all = $approved === count($items);
        $msg = $approved > 0
            ? ($all ? '<p>The items were <strong>approved automatically</strong> because they are in stock.</p>' : '<p>Some items were approved automatically; the rest need your approval.</p>')
            : '<p><strong>This order needs your approval.</strong> Open the app: Profile, Sell on ' . h(SITE_NAME) . ', Orders.</p>';
        try {
            send_mail($seller['email'], $seller['business_name'],
                'New order #' . $orderId . ($all ? ' (auto-approved)' : ' needs your approval'),
                seller_orders_wrap('<h2>New order #' . $orderId . '</h2><p>Hello ' . h($seller['business_name']) . ', a customer ordered your products:</p>'
                    . seller_orders_items_html($items) . $msg));
        } catch (Throwable $e) { error_log('seller order mail: ' . $e->getMessage()); }
    }

    $state = seller_order_refresh($pdo, $orderId);
    if ($auto) {
        seller_orders_notify_admin('Order #' . $orderId . ' auto-approved for ' . implode(', ', $auto),
            '<h2>Order #' . $orderId . '</h2><p>Auto-approved (items in stock) for: <strong>' . h(implode(', ', $auto)) . '</strong>.</p>'
            . ($state === 'approved' ? '<p>All sellers have approved, so the order is now <strong>Approved</strong>.</p>' : ''));
    }
}

/**
 * A seller approves or declines their pending items in one order.
 * Returns '' on success, otherwise a message to show the seller.
 */
function seller_order_decide(PDO $pdo, int $sellerId, int $orderId, bool $approve, string $reason = ''): string {
    ensure_seller_orders($pdo);
    $o = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch();
    if (!$order) return 'Order not found.';
    if ($order['status'] === 'cancelled') return 'This order was cancelled.';

    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT oi.id, oi.product_id, oi.quantity, oi.seller_status, oi.stock_reserved, p.name
                            FROM order_items oi JOIN products p ON p.id = oi.product_id
                            WHERE oi.order_id = ? AND p.seller_id = ? AND oi.seller_status = 'pending' FOR UPDATE");
        $q->execute([$orderId, $sellerId]);
        $items = $q->fetchAll();
        if (!$items) { $pdo->rollBack(); return 'There is nothing waiting for approval on this order.'; }
        foreach ($items as $it) {
            if ($approve) {
                $err = seller_item_approve($pdo, $it);
                if ($err !== '') { $pdo->rollBack(); return $err; }
            } else {
                seller_item_reject($pdo, $it);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Could not update the order. Please try again.';
    }

    $state = seller_order_refresh($pdo, $orderId);
    $s = $pdo->prepare('SELECT business_name FROM sellers WHERE id = ?');
    $s->execute([$sellerId]);
    $name = (string) $s->fetchColumn();

    if ($approve) {
        seller_orders_notify_admin('Order #' . $orderId . ' approved by ' . $name,
            '<h2>Order #' . $orderId . '</h2><p><strong>' . h($name) . '</strong> approved their items.</p>'
            . ($state === 'approved' ? '<p>All sellers have approved. The order is now <strong>Approved</strong> and ready to process.</p>' : '<p>Other sellers on this order are still pending.</p>'));
    } else {
        seller_orders_notify_admin('Order #' . $orderId . ' declined by ' . $name,
            '<h2>Order #' . $orderId . '</h2><p><strong>' . h($name) . '</strong> declined their items.</p>'
            . ($reason !== '' ? '<p>Reason: ' . h($reason) . '</p>' : '') . '<p>The order stays <strong>Pending</strong>. Please follow up with the customer.</p>');
    }
    return '';
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
        if ($status === 'cancelled') release_order_stock($pdo, $orderId);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return 'Could not update the order.';
    }
    return '';
}
