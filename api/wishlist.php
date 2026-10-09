<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
$s = $pdo->prepare('SELECT ' . product_cols($pdo) . ' FROM wishlist w JOIN products p ON p.id = w.product_id
                    WHERE w.user_id = ? AND p.status = "approved" ORDER BY w.id DESC');
$s->execute([(int) $user['id']]);
out(['success' => true, 'products' => $s->fetchAll()]);
