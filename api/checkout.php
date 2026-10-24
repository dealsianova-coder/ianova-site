<?php
require __DIR__ . '/_bootstrap.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$user = require_user($pdo);
$uid = (int) $user['id'];
$b = body();
$name = trim((string) ($b['name'] ?? ''));
$email = trim((string) ($b['email'] ?? ''));
$phone = trim((string) ($b['phone'] ?? ''));
$addr = trim((string) ($b['address'] ?? ''));
if ($name === '' || $email === '' || $phone === '' || $addr === '') fail('Name, email, phone and address are required.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');

try {
    $pdo->beginTransaction();
    $s = $pdo->prepare(
        'SELECT p.id, p.name, p.price, p.stock, c.quantity
         FROM cart_items c JOIN products p ON p.id = c.product_id
         WHERE c.user_id = ? AND p.status = "approved" FOR UPDATE'
    );
    $s->execute([$uid]);
    $lines = $s->fetchAll();
    if (!$lines) { $pdo->rollBack(); fail('Your cart is empty.'); }

    $subtotal = 0.0;
    foreach ($lines as $l) {
        if ((int) $l['quantity'] > (int) $l['stock']) {
            $pdo->rollBack();
            fail('Not enough stock for "' . $l['name'] . '" (' . (int) $l['stock'] . ' left).', 409);
        }
        $subtotal += (float) $l['price'] * (int) $l['quantity'];
    }
    $subtotal = round($subtotal, 2);
    $delivery = ($subtotal > 0 && $subtotal < store_free_delivery_threshold($pdo)) ? store_delivery_fee($pdo) : 0.0;
    $total = round($subtotal + $delivery, 2);
    $discount = 0.0; $couponRow = null; $couponCode = trim((string) ($b['coupon'] ?? ''));
    if ($couponCode !== '') {
        require_once __DIR__ . '/../includes/coupons.php';
        $cc = cp_check($pdo, $couponCode, $uid, $subtotal, $delivery, true);
        if (!$cc['ok']) { $pdo->rollBack(); fail($cc['message'], 422); }
        $discount = $cc['discount']; $couponRow = $cc['coupon'];
        $total = round($total - $discount, 2);
    }

    $pdo->prepare('INSERT INTO orders (user_id, guest_name, guest_email, guest_phone, address, total, status) VALUES (?,?,?,?,?,?,"pending")')
        ->execute([$uid, $name, $email, $phone, $addr, $total]);
    $orderId = (int) $pdo->lastInsertId();
    if ($couponRow) cp_apply($pdo, $orderId, $uid, $couponRow, $discount);

    $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?,?,?,?)');
    $dec = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
    foreach ($lines as $l) {
        $ins->execute([$orderId, $l['id'], $l['quantity'], $l['price']]);
        $dec->execute([$l['quantity'], $l['id']]);
    }
    $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Could not place your order. Please try again.', 500);
}

require_once __DIR__ . '/../includes/seller_ops.php';
ops_ensure($pdo); // approves the sellers' parts automatically when their items are in stock
require_once __DIR__ . '/../includes/payments.php';
$payInfo = pay_checkout_info($pdo, $orderId, (string) ($b['pay_plan'] ?? 'full')); // deposit or pay in full
out(['success' => true, 'order' => ['id' => $orderId, 'subtotal' => $subtotal, 'delivery' => $delivery,
                                     'total' => $total, 'discount' => $discount, 'coupon' => $couponRow ? $couponRow['code'] : '', 'status' => 'pending'] + $payInfo], 201);
