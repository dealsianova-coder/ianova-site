<?php
/** Everything the seller dashboard shows (same blocks as the website's seller dashboard). */
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/seller_ops.php';
$seller = require_seller($pdo);
$sid = (int) $seller['id'];
$img = image_column($pdo);
ops_ensure($pdo);

$one = static function (string $sql, array $args) use ($pdo) {
    $q = $pdo->prepare($sql);
    $q->execute($args);
    return $q->fetchColumn();
};
$counts = ops_seller_counts($pdo, $sid);
$revenue = (float) $one("SELECT COALESCE(SUM(oi.quantity*oi.unit_price),0) FROM order_items oi JOIN products p ON p.id=oi.product_id JOIN orders o ON o.id=oi.order_id WHERE p.seller_id=? AND o.status IN ('paid','shipped','delivered')", [$sid]);

$rq = $pdo->prepare("SELECT o.id, COALESCE(o.guest_name, u.name, 'Customer') AS customer, o.status, o.created_at,
        (SELECT SUM(oi.quantity*oi.unit_price) FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.seller_id=?) AS mine
    FROM orders o LEFT JOIN users u ON u.id = o.user_id
    WHERE EXISTS (SELECT 1 FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.seller_id=?)
    ORDER BY o.id DESC LIMIT 6");
$rq->execute([$sid, $sid]);
$recent = $rq->fetchAll();

$approved = [];
$aq = $pdo->prepare('SELECT order_id FROM seller_order_approvals WHERE seller_id = ?');
$aq->execute([$sid]);
foreach ($aq->fetchAll(PDO::FETCH_COLUMN) as $oid) $approved[(int) $oid] = true;

$thumbs = [];
if ($recent) {
    $ids = array_map(static fn($r) => (int) $r['id'], $recent);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $tq = $pdo->prepare("SELECT oi.order_id, COALESCE(p.$img, '') AS image, COALESCE(p.emoji, '') AS emoji, COALESCE(p.bg_color, '#EFF3EC') AS bg_color
                         FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id IN ($ph) AND p.seller_id = ? ORDER BY oi.id");
    $tq->execute(array_merge($ids, [$sid]));
    foreach ($tq->fetchAll() as $t) $thumbs[(int) $t['order_id']][] = ['image' => $t['image'], 'emoji' => $t['emoji'], 'bg_color' => $t['bg_color']];
}
$recentOut = [];
foreach ($recent as $r) {
    $all = $thumbs[(int) $r['id']] ?? [];
    $recentOut[] = [
        'id' => (int) $r['id'], 'customer' => $r['customer'], 'status' => $r['status'], 'created_at' => $r['created_at'],
        'mine' => round((float) $r['mine'], 2), 'approved' => isset($approved[(int) $r['id']]),
        'photos' => array_slice($all, 0, 3), 'more' => max(0, count($all) - 3),
    ];
}

out([
    'success' => true,
    'status' => $seller['status'],
    'products' => (int) $one('SELECT COUNT(*) FROM products WHERE seller_id=?', [$sid]),
    'orders' => (int) $one('SELECT COUNT(DISTINCT oi.order_id) FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE p.seller_id=?', [$sid]),
    'low_stock' => (int) $one("SELECT COUNT(*) FROM products WHERE seller_id=? AND stock<=3 AND status='approved'", [$sid]),
    'revenue' => round($revenue, 2),
    'pending_products' => (int) $one("SELECT COUNT(*) FROM products WHERE seller_id=? AND status='pending'", [$sid]),
    'pending_orders' => (int) $counts['pending_orders'],
    'unread_messages' => (int) $counts['unread_messages'],
    'locked_products' => (int) $counts['locked_products'],
    'auto_master' => ops_setting($pdo, 'auto_approve', '1') === '1',
    'auto_mine' => ops_seller_auto($pdo, $sid),
    'recent' => $recentOut,
]);
