<?php
/** Customer checks a coupon code against their cart. Nothing is used up until the order is placed. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/coupons.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$user = require_user($pdo);
$uid = (int) $user['id'];
$code = trim((string) (body()['code'] ?? ''));
if ($code === '') fail('Enter a coupon code.');
$s = $pdo->prepare('SELECT p.price, c.quantity FROM cart_items c JOIN products p ON p.id = c.product_id WHERE c.user_id = ? AND p.status = "approved"');
$s->execute([$uid]);
$subtotal = 0.0;
foreach ($s->fetchAll() as $l) $subtotal += (float) $l['price'] * (int) $l['quantity'];
$subtotal = round($subtotal, 2);
if ($subtotal <= 0) fail('Your cart is empty.');
$delivery = $subtotal < store_free_delivery_threshold($pdo) ? store_delivery_fee($pdo) : 0.0;
$r = cp_check($pdo, $code, $uid, $subtotal, $delivery);
if (!$r['ok']) fail($r['message'], 422);
out(['success' => true, 'message' => $r['message'], 'code' => $r['coupon']['code'], 'label' => cp_label($r['coupon']),
     'discount' => $r['discount'], 'subtotal' => $subtotal, 'delivery' => $delivery, 'total' => round($subtotal + $delivery - $r['discount'], 2)]);
