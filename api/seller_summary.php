<?php
/** Sales numbers for the signed-in seller. Cancelled orders are not counted. */
require __DIR__ . '/_seller.php';
$seller = require_approved_seller($pdo);
$sid = (int) $seller['id'];

$sales = function (string $where) use ($pdo, $sid): array {
    $q = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0) revenue, COALESCE(SUM(oi.quantity), 0) units, COUNT(DISTINCT o.id) orders
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
         WHERE p.seller_id = ? AND o.status <> 'cancelled' $where"
    );
    $q->execute([$sid]);
    $r = $q->fetch();
    return ['revenue' => round((float) $r['revenue'], 2), 'units' => (int) $r['units'], 'orders' => (int) $r['orders']];
};

$counts = $pdo->prepare('SELECT status, COUNT(*) c FROM products WHERE seller_id = ? GROUP BY status');
$counts->execute([$sid]);
$byStatus = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
foreach ($counts->fetchAll() as $r) $byStatus[$r['status']] = (int) $r['c'];

$low = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ? AND stock <= 3 AND status = 'approved'");
$low->execute([$sid]);

$top = $pdo->prepare(
    "SELECT p.name, SUM(oi.quantity) units, SUM(oi.quantity * oi.unit_price) revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
     WHERE p.seller_id = ? AND o.status <> 'cancelled' GROUP BY p.id, p.name ORDER BY units DESC LIMIT 5"
);
$top->execute([$sid]);

out([
    'success' => true,
    'all_time' => $sales(''),
    'last_30_days' => $sales(' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
    'last_7_days' => $sales(' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
    'products' => $byStatus,
    'low_stock' => (int) $low->fetchColumn(),
    'top_products' => array_map(static fn($t) => ['name' => $t['name'], 'units' => (int) $t['units'], 'revenue' => round((float) $t['revenue'], 2)], $top->fetchAll()),
]);
