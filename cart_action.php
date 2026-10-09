<?php
require 'config.php';
require 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action     = $_POST['action'] ?? '';
$product_id = $_POST['product_id'] ?? 0;
$redirect   = $_POST['redirect'] ?? 'cart.php';

// Only allow redirecting to a local path, never an external URL.
if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $redirect) || str_starts_with($redirect, '//')) {
    $redirect = 'cart.php';
}

switch ($action) {
    case 'add':
        $qty = isset($_POST['qty']) ? (int) $_POST['qty'] : 1;
        cart_add($product_id, $qty);
        $_SESSION['flash_message'] = 'Added to cart.';
        break;
    case 'update':
        cart_set_qty($product_id, $_POST['qty'] ?? 1);
        break;
    case 'remove':
        cart_remove($product_id);
        break;
}

header('Location: ' . $redirect);
exit;
