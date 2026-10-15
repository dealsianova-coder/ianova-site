<?php
/** Customer edits a pending order: phone, address and item quantities (0 removes an item). */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/seller_orders.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$user = require_user($pdo);
$uid = (int) $user['id'];
$b = body();
$id = (int) ($b['id'] ?? 0);
$phone = trim((string) ($b['phone'] ?? ''));
$address = trim((string) ($b['address'] ?? ''));
$req = [];
foreach ((array) ($b['items'] ?? []) as $row) {
    if (is_array($row)) $req[(int) ($row['id'] ?? 0)] = max(0, (int) ($row['quantity'] ?? 0));
}
if ($address === '') fail('Delivery address is required.');
ensure_seller_orders($pdo);

$o = $pdo->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ?');
$o->execute([$id, $uid]);
$status = $o->fetchColumn();
if ($status === false) fail('Order not found.', 404);
if ($status !== 'pending') fail('This order can no longer be changed.', 409);

$cancelled = false;
try {
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT oi.id, oi.product_id, oi.quantity, oi.unit_price, oi.stock_reserved, p.name
                        FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? FOR UPDATE');
    $q->execute([$id]);
    $subtotal = 0.0; $remaining = 0;
    foreach ($q->fetchAll() as $it) {
        $q2 = array_key_exists((int) $it['id'], $req) ? $req[(int) $it['id']] : (int) $it['quantity'];
        if (!empty($it['stock_reserved'])) {
            $delta = $q2 - (int) $it['quantity'];
            if ($delta > 0) {
                $take = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                $take->execute([$delta, $it['product_id'], $delta]);
                if ($take->rowCount() < 1) throw new OutOfStockException('Not enough stock for "' . $it['name'] . '" to increase the quantity.');
            } elseif ($delta < 0) {
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([-$delta, $it['product_id']]);
            }
        }
        if ($q2 === 0) {
            $pdo->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?')->execute([$it['id'], $id]);
            continue;
        }
        $pdo->prepare('UPDATE order_items SET quantity = ? WHERE id = ? AND order_id = ?')->execute([$q2, $it['id'], $id]);
        $subtotal += $q2 * (float) $it['unit_price'];
        $remaining++;
    }
    if ($remaining === 0) {
        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        $cancelled = true;
        $total = 0.0;
    } else {
        $subtotal = round($subtotal, 2);
        $delivery = $subtotal < store_free_delivery_threshold($pdo) ? store_delivery_fee($pdo) : 0.0;
        $total = round($subtotal + $delivery, 2);
        $pdo->prepare('UPDATE orders SET guest_phone = ?, address = ?, total = ? WHERE id = ?')->execute([$phone, $address, $total, $id]);
    }
    $pdo->commit();
} catch (OutOfStockException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Could not update your order. Please try again.', 500);
}

out(['success' => true, 'cancelled' => $cancelled, 'total' => $total]);
