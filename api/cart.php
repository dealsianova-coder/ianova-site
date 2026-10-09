<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
$lines = cart_lines($pdo, (int) $user['id']);
out(['success' => true, 'items' => $lines,
     'item_count' => array_sum(array_column($lines, 'quantity')),
     'subtotal' => round(array_sum(array_column($lines, 'item_total')), 2)]);
