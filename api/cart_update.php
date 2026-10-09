<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
$uid = (int) $user['id'];
$b = body();
$pid = (int) ($b['product_id'] ?? 0);
if ($pid < 1) fail('Invalid product.');

if (method() === 'DELETE') {
    $pdo->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?')->execute([$uid, $pid]);
    out(['success' => true]);
}
if (!in_array(method(), ['POST', 'PUT'], true)) fail('Method not allowed.', 405);

$qty = (int) ($b['quantity'] ?? 1);
$p = $pdo->prepare('SELECT stock FROM products WHERE id = ? AND status = "approved"');
$p->execute([$pid]);
$prod = $p->fetch();
if (!$prod) fail('Product not found.', 404);

if (method() === 'PUT') {                       // set an exact quantity
    if ($qty <= 0) {
        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?')->execute([$uid, $pid]);
        out(['success' => true]);
    }
    $new = $qty;
} else {                                        // POST: add to what is already there
    $c = $pdo->prepare('SELECT quantity FROM cart_items WHERE user_id = ? AND product_id = ?');
    $c->execute([$uid, $pid]);
    $new = (int) ($c->fetchColumn() ?: 0) + max(1, $qty);
}
if ($new > (int) $prod['stock']) fail('Only ' . (int) $prod['stock'] . ' left in stock.', 409);

$pdo->prepare('INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)')->execute([$uid, $pid, $new]);
out(['success' => true]);
