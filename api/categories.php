<?php
require __DIR__ . '/_bootstrap.php';
$hasImage = (bool) $pdo->query("SHOW COLUMNS FROM categories LIKE 'image'")->fetch();
$cols = $hasImage ? 'id, name, slug, emoji, image, sort_order' : 'id, name, slug, emoji, sort_order';
$rows = $pdo->query("SELECT $cols FROM categories WHERE is_hidden = 0 ORDER BY sort_order, name")->fetchAll();
out(['success' => true, 'categories' => $rows]);
