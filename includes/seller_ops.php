<?php
/**
 * Seller order approval, admin restrictions and admin alerts.
 * Tables are created on first use, so there is no migration to run.
 */
require_once __DIR__ . '/seller_messaging.php';

function ops_h($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function ops_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        ensure_seller_messaging($pdo);
        $firstRun = !$pdo->query("SHOW TABLES LIKE 'order_notified'")->fetchColumn();
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_notified (
            order_id INT NOT NULL PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Orders that already exist are left as they are; only new orders are handled automatically.
        if ($firstRun) $pdo->exec('INSERT IGNORE INTO order_notified (order_id) SELECT id FROM orders');
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(30) NOT NULL,
            title VARCHAR(200) NOT NULL,
            body TEXT,
            link VARCHAR(200) NOT NULL DEFAULT '',
            seller_id INT DEFAULT NULL,
            product_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_unread (read_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS seller_order_approvals (
            order_id INT NOT NULL,
            seller_id INT NOT NULL,
            approved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (order_id, seller_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
    ops_auto_process($pdo);
}

/**
 * New orders: tell the main admin, and approve each seller's part automatically when the seller
 * is approved and every one of their products is approved and in stock. Otherwise it stays
 * waiting for the seller to approve by hand. Runs once per order (order_notified is the gate).
 * Called right after checkout and also whenever the admin or seller pages load, so it never gets missed.
 */
function ops_auto_process(PDO $pdo): void {
    static $running = false;
    if ($running) return;
    $running = true;
    try {
        $orders = $pdo->query(
            "SELECT o.id, o.user_id, COALESCE(o.guest_name, 'Customer') AS customer, o.total
             FROM orders o
             WHERE o.status <> 'cancelled'
               AND NOT EXISTS (SELECT 1 FROM order_notified n WHERE n.order_id = o.id)
               AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)
             ORDER BY o.id LIMIT 50"
        )->fetchAll();
        $gate = $pdo->prepare('INSERT IGNORE INTO order_notified (order_id) VALUES (?)');
        $lq = $pdo->prepare(
            "SELECT p.seller_id, p.name, p.status, p.stock, oi.quantity,
                    COALESCE(s.status, '') AS seller_status, COALESCE(s.business_name, 'Seller') AS seller_name
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             LEFT JOIN sellers s ON s.id = p.seller_id
             WHERE oi.order_id = ? AND p.seller_id IS NOT NULL"
        );
        $ins = $pdo->prepare('INSERT IGNORE INTO seller_order_approvals (order_id, seller_id) VALUES (?, ?)');
        $autoOn = ops_setting($pdo, 'auto_approve', '1') === '1';
        foreach ($orders as $o) {
            $gate->execute([$o['id']]);
            if ($gate->rowCount() === 0) continue;
            $oid = (int) $o['id'];
            $link = 'order_view.php?id=' . $oid;
            ops_notify_admin($pdo, 'new_order', 'New order #' . $oid, $o['customer'] . ' - ' . number_format((float) $o['total'], 2), $link, null, null);
            $lq->execute([$oid]);
            $bySeller = [];
            foreach ($lq->fetchAll() as $l) $bySeller[(int) $l['seller_id']][] = $l;
            // Orders from the app already had their stock reduced at checkout; website guest orders did not.
            $stockReduced = !empty($o['user_id']);
            foreach ($bySeller as $sid => $lines) {
                $name = $lines[0]['seller_name'];
                $why = $autoOn ? '' : 'automatic approval is switched off';
                $sellerOff = $autoOn && !ops_seller_auto($pdo, $sid);
                if ($sellerOff) $why = 'the seller switched automatic approval off';
                if ($lines[0]['seller_status'] !== 'approved') $why = 'the seller account is restricted';
                foreach ($lines as $l) {
                    if ($why !== '') break;
                    if ($l['status'] !== 'approved') $why = '"' . $l['name'] . '" is not approved';
                    elseif ($stockReduced ? (int) $l['stock'] < 0 : (int) $l['stock'] < (int) $l['quantity']) $why = '"' . $l['name'] . '" is out of stock';
                }
                if ($why === '') {
                    $ins->execute([$oid, $sid]);
                    if ($ins->rowCount() > 0) {
                        ops_notify_admin($pdo, 'auto_approved', 'Order #' . $oid . ' approved automatically for ' . $name,
                            'All of the seller\'s items are in stock, so no manual approval was needed.', $link, $sid, null);
                        seller_message_add($pdo, $sid, 'admin', 'New order #' . $oid . ' was approved automatically because your items are in stock. Please prepare it for delivery.');
                    }
                } else {
                    ops_notify_admin($pdo, 'needs_approval', 'Order #' . $oid . ' needs ' . $name . ' to approve', ($autoOn && !$sellerOff ? 'Not approved automatically: ' . $why . '.' : 'Automatic approval is off' . ($sellerOff ? ' for this seller' : '') . ', so the seller has to approve this order by hand.'), $link, $sid, null);
                }
            }
        }
    } catch (Throwable $e) {
    }
    $running = false;
}

/** On/off switches set by the admin. Automatic order approval is ON unless switched off. */
function ops_setting(PDO $pdo, string $key, string $default = '1'): string {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ops_settings (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $q = $pdo->prepare('SELECT v FROM ops_settings WHERE k = ?');
        $q->execute([$key]);
        $v = $q->fetchColumn();
        return $v === false ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function ops_setting_set(PDO $pdo, string $key, string $value): void {
    ops_setting($pdo, $key);
    $pdo->prepare('INSERT INTO ops_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$key, $value]);
}

/** A seller's own switch for automatic approval of their orders (default ON). The admin's master switch comes first. */
function ops_seller_auto(PDO $pdo, int $sellerId): bool {
    return ops_setting($pdo, 'auto_seller_' . $sellerId, '1') === '1';
}

function ops_notify_admin(PDO $pdo, string $kind, string $title, string $body, string $link = '', ?int $sellerId = null, ?int $productId = null): void {
    ops_ensure($pdo);
    try {
        $pdo->prepare('INSERT INTO admin_notifications (kind, title, body, link, seller_id, product_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$kind, mb_substr($title, 0, 200), $body, $link, $sellerId, $productId]);
    } catch (Throwable $e) {
    }
}

function ops_unread_count(PDO $pdo): int {
    ops_ensure($pdo);
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** A product the admin hid or rejected: the seller cannot change it until it is approved again. */
function ops_product_locked(array $product): bool {
    return in_array((string) ($product['status'] ?? ''), ['hidden', 'rejected'], true);
}

function ops_product_status(PDO $pdo, int $id): string {
    $q = $pdo->prepare('SELECT status FROM products WHERE id = ?');
    $q->execute([$id]);
    return (string) $q->fetchColumn();
}

/** Tell the seller when the admin restricts a product, or lifts the restriction. */
function ops_product_status_changed(PDO $pdo, int $productId, string $old, string $new): void {
    ops_ensure($pdo);
    try {
        $q = $pdo->prepare('SELECT name, seller_id FROM products WHERE id = ?');
        $q->execute([$productId]);
        $p = $q->fetch();
        if (!$p || empty($p['seller_id'])) return;
        $sid = (int) $p['seller_id'];
        $locked = ['hidden', 'rejected'];
        if (in_array($new, $locked, true) && !in_array($old, $locked, true)) {
            seller_message_add($pdo, $sid, 'admin',
                'Your product "' . $p['name'] . '" was ' . $new . ' by the admin. It is locked: you cannot edit it until the admin approves it again. '
                . 'Open your seller dashboard and tap "Request review" to send your corrections to the admin.');
        } elseif (in_array($old, $locked, true) && $new === 'approved') {
            seller_message_add($pdo, $sid, 'admin',
                'Your product "' . $p['name'] . '" was approved by the admin. It is live again and you can edit it.');
            $pdo->prepare("UPDATE admin_notifications SET read_at = NOW() WHERE kind = 'review_request' AND product_id = ? AND read_at IS NULL")
                ->execute([$productId]);
        }
    } catch (Throwable $e) {
    }
}

/** Tell the seller when the admin restricts or restores the seller account. */
function ops_seller_status_changed(PDO $pdo, int $sellerId, string $old, string $new): void {
    ops_ensure($pdo);
    try {
        if ($old === $new) return;
        if ($new === 'suspended' || $new === 'rejected') {
            seller_message_add($pdo, $sellerId, 'admin',
                'Your seller account was ' . $new . ' by the admin. You cannot add or edit products or approve orders until the admin approves your account again. '
                . 'Reply here with your corrections.');
        } elseif ($new === 'approved' && $old === 'suspended') {
            seller_message_add($pdo, $sellerId, 'admin', 'Your seller account was approved again. You can sell on IANOVA again.');
            $pdo->prepare("UPDATE admin_notifications SET read_at = NOW() WHERE kind = 'review_request' AND seller_id = ? AND product_id IS NULL AND read_at IS NULL")
                ->execute([$sellerId]);
        }
    } catch (Throwable $e) {
    }
}

/** Admin order page: which sellers have approved their part of the order. */
function ops_order_approvals_html(PDO $pdo, int $orderId): void {
    ops_ensure($pdo);
    try {
        $q = $pdo->prepare(
            'SELECT DISTINCT p.seller_id, COALESCE(s.business_name, p.seller_name) AS name, a.approved_at
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             LEFT JOIN sellers s ON s.id = p.seller_id
             LEFT JOIN seller_order_approvals a ON a.order_id = oi.order_id AND a.seller_id = p.seller_id
             WHERE oi.order_id = ? AND p.seller_id IS NOT NULL'
        );
        $q->execute([$orderId]);
        $rows = $q->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    if (!$rows) return;
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = ops_h($r['name']) . ($r['approved_at'] ? ' - <span style="color:green;font-weight:700">approved</span> (' . ops_h($r['approved_at']) . ')' : ' - <span style="color:#b26a00;font-weight:700">waiting</span>');
    }
    echo '<p><b>Seller approval:</b> ' . implode(' &nbsp;|&nbsp; ', $parts) . '</p>';
}

/** Numbers for the seller badges in the app. */
function ops_seller_counts(PDO $pdo, int $sellerId): array {
    ops_ensure($pdo);
    $q = $pdo->prepare("SELECT COUNT(*) FROM seller_messages WHERE seller_id = ? AND sender = 'admin' AND read_at IS NULL");
    $q->execute([$sellerId]);
    $unread = (int) $q->fetchColumn();
    $q = $pdo->prepare(
        "SELECT COUNT(DISTINCT o.id) FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         JOIN products p ON p.id = oi.product_id
         WHERE p.seller_id = ? AND o.status <> 'cancelled'
         AND NOT EXISTS (SELECT 1 FROM seller_order_approvals a WHERE a.order_id = o.id AND a.seller_id = ?)"
    );
    $q->execute([$sellerId, $sellerId]);
    $orders = (int) $q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ? AND status IN ('hidden', 'rejected')");
    $q->execute([$sellerId]);
    return ['unread_messages' => $unread, 'pending_orders' => $orders, 'locked_products' => (int) $q->fetchColumn()];
}

/** Approve the seller's part of an order. Returns [ok, message]. */
function ops_approve_order(PDO $pdo, array $seller, int $orderId): array {
    ops_ensure($pdo);
    $sellerId = (int) $seller['id'];
    if (($seller['status'] ?? '') !== 'approved') {
        return [false, 'Your seller account is restricted. You cannot approve orders until the admin approves your account.'];
    }
    $iq = $pdo->prepare('SELECT p.id, p.status FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.seller_id = ?');
    $iq->execute([$orderId, $sellerId]);
    $mine = $iq->fetchAll();
    if (!$mine) return [false, 'That order has none of your products.'];
    $oq = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $oq->execute([$orderId]);
    if ((string) $oq->fetchColumn() === 'cancelled') return [false, 'This order was cancelled.'];
    foreach ($mine as $m) {
        if (ops_product_locked($m)) return [false, 'This order has a product restricted by the admin. Wait for the admin to approve it first.'];
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO seller_order_approvals (order_id, seller_id) VALUES (?, ?)');
    $ins->execute([$orderId, $sellerId]);
    if ($ins->rowCount() > 0) {
        ops_notify_admin($pdo, 'order_approved', ($seller['business_name'] ?? 'A seller') . ' approved order #' . $orderId,
            'The seller confirmed they can fulfil their items in this order.', 'order_view.php?id=' . $orderId, $sellerId, null);
    }
    return [true, 'Order #' . $orderId . ' approved.'];
}

/** Ask the admin to review a restricted product (or the account when $productId is 0). Returns [ok, message]. */
function ops_request_review(PDO $pdo, array $seller, int $productId, string $note): array {
    ops_ensure($pdo);
    $sellerId = (int) $seller['id'];
    $name = $seller['business_name'] ?? 'Seller';
    $note = mb_substr(trim($note), 0, 800);
    if ($productId > 0) {
        $pq = $pdo->prepare('SELECT id, name, status FROM products WHERE id = ? AND seller_id = ?');
        $pq->execute([$productId, $sellerId]);
        $product = $pq->fetch();
        if (!$product || !ops_product_locked($product)) return [false, 'That product is not restricted.'];
        $title = $name . ' asks for a review of "' . $product['name'] . '"';
        $link = 'product_edit.php?id=' . $productId;
    } else {
        if (($seller['status'] ?? '') === 'approved') return [false, 'Your account is not restricted.'];
        $title = $name . ' asks for their seller account to be reviewed';
        $link = 'seller_messages.php?id=' . $sellerId;
    }
    $dup = $pdo->prepare("SELECT id FROM admin_notifications WHERE kind = 'review_request' AND seller_id = ? AND COALESCE(product_id, 0) = ? AND read_at IS NULL");
    $dup->execute([$sellerId, $productId]);
    if ($dup->fetch()) return [false, 'Your review request is already waiting for the admin.'];
    ops_notify_admin($pdo, 'review_request', $title, $note !== '' ? $note : 'No note added.', $link, $sellerId, $productId ?: null);
    seller_message_add($pdo, $sellerId, 'seller', 'Review request: ' . $title . ($note !== '' ? ' - ' . $note : ''));
    return [true, 'Sent. The admin has been notified and will review your corrections.'];
}

/** The seller's orders (only their own items) for the app. */
function ops_seller_orders(PDO $pdo, array $seller): array {
    ops_ensure($pdo);
    $sellerId = (int) $seller['id'];
    $canAct = ($seller['status'] ?? '') === 'approved';
    $q = $pdo->prepare(
        "SELECT o.id, o.created_at, o.status, o.address, o.guest_phone, COALESCE(o.guest_name, u.name, 'Customer') AS customer
         FROM orders o LEFT JOIN users u ON u.id = o.user_id
         WHERE EXISTS (SELECT 1 FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id AND p.seller_id = ?)
         ORDER BY o.id DESC LIMIT 100"
    );
    $q->execute([$sellerId]);
    $orders = $q->fetchAll();
    $aq = $pdo->prepare('SELECT order_id FROM seller_order_approvals WHERE seller_id = ?');
    $aq->execute([$sellerId]);
    $approved = array_flip(array_map('intval', $aq->fetchAll(PDO::FETCH_COLUMN)));
    $iq = $pdo->prepare('SELECT oi.quantity, oi.unit_price, p.name, p.status, COALESCE(p.color, \'\') AS color, COALESCE(p.size, \'\') AS size FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? AND p.seller_id = ?');
    $out = [];
    foreach ($orders as $o) {
        $iq->execute([$o['id'], $sellerId]);
        $lines = [];
        $total = 0.0;
        $locked = false;
        foreach ($iq->fetchAll() as $it) {
            $line = (float) $it['unit_price'] * (int) $it['quantity'];
            $total += $line;
            if (ops_product_locked($it)) $locked = true;
            $lines[] = ['name' => $it['name'], 'option' => trim($it['color'] . ' ' . $it['size']), 'quantity' => (int) $it['quantity'], 'line_total' => $line];
        }
        $done = isset($approved[(int) $o['id']]);
        $note = '';
        if ($done) $note = 'You approved this order.';
        elseif ($o['status'] === 'cancelled') $note = 'This order was cancelled.';
        elseif ($locked) $note = 'Has a product restricted by the admin.';
        elseif (!$canAct) $note = 'Your account is restricted.';
        $out[] = [
            'id' => (int) $o['id'], 'created_at' => $o['created_at'], 'status' => $o['status'],
            'customer' => $o['customer'], 'phone' => (string) ($o['guest_phone'] ?? ''), 'address' => (string) ($o['address'] ?? ''),
            'total' => $total, 'approved' => $done, 'can_approve' => !$done && $note === '', 'note' => $note, 'items' => $lines,
        ];
    }
    return $out;
}
