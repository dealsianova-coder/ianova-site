<?php
require __DIR__ . '/_bootstrap.php';
$rows = $pdo->query('SELECT id, name, slug, emoji, sort_order FROM categories WHERE is_hidden = 0 ORDER BY sort_order, name')->fetchAll();
out(['success' => true, 'categories' => $rows]);
