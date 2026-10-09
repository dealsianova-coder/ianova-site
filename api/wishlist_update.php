<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
$pid = (int) (body()['product_id'] ?? 0);
if ($pid < 1) fail('Invalid product.');
if (method() === 'DELETE') {
    $pdo->prepare('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?')->execute([(int) $user['id'], $pid]);
} elseif (method() === 'POST' || method() === 'PUT') {
    $e = $pdo->prepare('SELECT id FROM products WHERE id = ? AND status = "approved"');
    $e->execute([$pid]);
    if (!$e->fetch()) fail('Product not found.', 404);
    $pdo->prepare('INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?, ?)')->execute([(int) $user['id'], $pid]);
} else {
    fail('Method not allowed.', 405);
}
out(['success' => true]);
