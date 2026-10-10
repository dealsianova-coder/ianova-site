<?php
/** Orders that contain this seller's products (read-only; the main admin manages order status). */
require __DIR__ . '/_seller.php';
$seller = require_approved_seller($pdo);
$sid = (int) $seller['id'];

$q = $pdo->prepare(
    'SELECT o.id, o.guest_name, o.guest_phone, o.address, o.status, o.created_at,
            oi.quantity, oi.unit_price, p.name AS product_name
     FROM orders o
     JOIN order_items oi ON oi.order_id = o.id
     JOIN products p ON p.id = oi.product_id
     WHERE p.seller_id = ?
     ORDER BY o.id DESC, oi.id
     LIMIT 400'
);
$q->execute([$sid]);

$orders = [];
foreach ($q->fetchAll() as $r) {
    $oid = (int) $r['id'];
    if (!isset($orders[$oid])) {
        $orders[$oid] = [
            'id' => $oid,
            'customer' => $r['guest_name'],
            'phone' => $r['guest_phone'],
            'address' => $r['address'],
            'status' => $r['status'],
            'created_at' => $r['created_at'],
            'subtotal' => 0.0,
            'items' => [],
        ];
    }
    $line = round((float) $r['unit_price'] * (int) $r['quantity'], 2);
    $orders[$oid]['subtotal'] += $line;
    $orders[$oid]['items'][] = ['name' => $r['product_name'], 'quantity' => (int) $r['quantity'], 'unit_price' => (float) $r['unit_price']];
}
out(['success' => true, 'orders' => array_slice(array_values($orders), 0, 100)]);
