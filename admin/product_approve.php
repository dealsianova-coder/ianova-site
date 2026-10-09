<?php
require 'includes/auth.php';
$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
if ($id && in_array($action, ['approve','reject'], true)) {
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $s = $pdo->prepare('UPDATE products SET status=? WHERE id=?');
    $s->execute([$status, $id]);
}
header('Location: products.php');
exit;
