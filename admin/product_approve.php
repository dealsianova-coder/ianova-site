<?php
require 'includes/auth.php';
require_once __DIR__ . '/../includes/seller_ops.php';
$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
if ($id && in_array($action, ['approve','reject'], true)) {
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $old = ops_product_status($pdo, $id);
    $s = $pdo->prepare('UPDATE products SET status=? WHERE id=?');
    $s->execute([$status, $id]);
    ops_product_status_changed($pdo, $id, $old, $status);
}
header('Location: products.php');
exit;
