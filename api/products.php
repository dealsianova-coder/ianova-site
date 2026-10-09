<?php
require __DIR__ . '/_bootstrap.php';
$sql = 'SELECT ' . product_cols($pdo) . ' FROM products p WHERE p.status = "approved" AND ' . representative_filter('p');
$args = [];
if (isset($_GET['category']) && (int) $_GET['category'] > 0) {
    $sql .= ' AND p.category_id = ?';
    $args[] = (int) $_GET['category'];
}
$sql .= ' ORDER BY p.id DESC LIMIT 200';
$s = $pdo->prepare($sql);
$s->execute($args);
out(['success' => true, 'products' => $s->fetchAll()]);
