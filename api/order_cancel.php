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
require_once __DIR__ . '/../includes/seller_ops.php';
ops_notify_admin($pdo, 'order_cancelled', 'Order #' . $id . ' cancelled by the customer', 'Stock was returned to the shelf.', 'order_view.php?id=' . $id);
out(['success' => true, 'status' => 'cancelled']);
