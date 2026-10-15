<?php
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/category_images.php';
cat_img_ensure($pdo);
$rows = $pdo->query('SELECT id, name, slug, emoji, image, sort_order FROM categories WHERE is_hidden = 0 ORDER BY sort_order, name')->fetchAll();
out(['success' => true, 'categories' => $rows]);
