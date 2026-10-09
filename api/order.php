<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) fail('Invalid order ID.');
$s = $pdo->prepare('SELECT id, guest_name, guest_email, guest_phone, address, total, status, created_at FROM orders WHERE id = ? AND user_id = ?');
$s->execute([$id, (int) $user['id']]);
$order = $s->fetch();
if (!$order) fail('Order not found.', 404);

$img = image_column($pdo);
$i = $pdo->prepare(
    "SELECT oi.id, oi.product_id, NULL AS variant_id, p.name, p.slug, COALESCE(p.$img, '') AS image,
            NULL AS variant_color, NULL AS variant_size, NULL AS variant_sku,
            oi.quantity, oi.unit_price, ROUND(oi.quantity * oi.unit_price, 2) AS item_total
     FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id"
);
$i->execute([$id]);
$items = $i->fetchAll();
$subtotal = round(array_sum(array_column($items, 'item_total')), 2);
$order['subtotal'] = $subtotal;
$order['delivery'] = round((float) $order['total'] - $subtotal, 2);
out(['success' => true, 'order' => $order, 'items' => $items]);
