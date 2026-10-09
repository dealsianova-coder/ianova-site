<?php
require 'config.php';
require 'includes/functions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$product_id = (int) ($_POST['product_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'index.php';

if ($product_id > 0) {
    $check = $pdo->prepare('SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?');
    $check->execute([$user_id, $product_id]);
    if ($check->fetch()) {
        $pdo->prepare('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?')->execute([$user_id, $product_id]);
    } else {
        $pdo->prepare('INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)')->execute([$user_id, $product_id]);
    }
}

header('Location: ' . $redirect);
exit;
