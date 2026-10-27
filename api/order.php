<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
require_once __DIR__ . '/../includes/coupons.php';
cp_ensure($pdo);
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) fail('Invalid order ID.');
$s = $pdo->prepare('SELECT id, guest_name, guest_email, guest_phone, address, total, status, created_at, coupon_code, discount FROM orders WHERE id = ? AND user_id = ?');
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
require_once __DIR__ . '/../includes/order_ops.php';
$ist = oo_item_statuses($pdo, $id);   // item_status: packed / shipped / delivered / ''
foreach ($items as &$it_) { $it_['item_status'] = $ist[(int) $it_['product_id']] ?? ''; }
unset($it_);
$subtotal = round(array_sum(array_column($items, 'item_total')), 2);
$order['subtotal'] = $subtotal;
$order['delivery'] = round((float) $order['total'] - $subtotal + (float) ($order['discount'] ?? 0), 2);
require_once __DIR__ . '/../includes/payments.php';
$order += pay_order_fields($pdo, $id);
try {
    $tq_ = $pdo->prepare('SELECT tracking_courier, tracking_no, tracking_note FROM orders WHERE id = ?'); $tq_->execute([$id]);
    $tr_ = $tq_->fetch(PDO::FETCH_ASSOC) ?: [];
    $order['tracking_courier'] = (string) ($tr_['tracking_courier'] ?? ''); $order['tracking_no'] = (string) ($tr_['tracking_no'] ?? ''); $order['tracking_note'] = (string) ($tr_['tracking_note'] ?? '');
    $order['timeline'] = oo_customer_timeline($pdo, $id);
} catch (Throwable $e_) { }
out(['success' => true, 'order' => $order, 'items' => $items]);
