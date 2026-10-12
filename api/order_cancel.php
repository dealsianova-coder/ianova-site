<?php
/** Customer cancels their own pending order. The held stock goes back on the shelf. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/seller_orders.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$user = require_user($pdo);
$id = (int) (body()['id'] ?? 0);

$s = $pdo->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ?');
$s->execute([$id, (int) $user['id']]);
$status = $s->fetchColumn();
if ($status === false) fail('Order not found.', 404);
if ($status !== 'pending') fail('Only pending orders can be cancelled.', 409);

$msg = set_order_status($pdo, $id, 'cancelled');
if ($msg !== '') fail($msg, 409);
seller_orders_notify_admin('Order #' . $id . ' cancelled by the customer', '<h2>Order #' . $id . '</h2><p>The customer cancelled this order. Stock has been returned.</p>');
out(['success' => true, 'status' => 'cancelled']);
