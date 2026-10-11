<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/seller_ops.php';

if (empty($_SESSION['seller_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['ops_csrf']) || !hash_equals($_SESSION['ops_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    header('Location: index.php');
    exit;
}

ops_ensure($pdo);
$sellerId = (int) $_SESSION['seller_id'];
$productId = (int) ($_POST['product_id'] ?? 0);
$note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 800);

$sq = $pdo->prepare('SELECT business_name, status FROM sellers WHERE id = ?');
$sq->execute([$sellerId]);
$seller = $sq->fetch();
$name = $seller['business_name'] ?? 'Seller';

if ($productId) {
    $pq = $pdo->prepare('SELECT id, name, status FROM products WHERE id = ? AND seller_id = ?');
    $pq->execute([$productId, $sellerId]);
    $product = $pq->fetch();
    if (!$product || !ops_product_locked($product)) {
        $_SESSION['ops_flash'] = 'That product is not restricted.';
        header('Location: index.php');
        exit;
    }
    $title = $name . ' asks for a review of "' . $product['name'] . '"';
    $link = 'product_edit.php?id=' . $productId;
} else {
    if (($seller['status'] ?? '') === 'approved') {
        header('Location: index.php');
        exit;
    }
    $title = $name . ' asks for their seller account to be reviewed';
    $link = 'seller_messages.php?id=' . $sellerId;
}

$dup = $pdo->prepare("SELECT id FROM admin_notifications WHERE kind = 'review_request' AND seller_id = ? AND COALESCE(product_id, 0) = ? AND read_at IS NULL");
$dup->execute([$sellerId, $productId]);
if ($dup->fetch()) {
    $_SESSION['ops_flash'] = 'Your review request is already waiting for the admin.';
    header('Location: index.php');
    exit;
}

ops_notify_admin($pdo, 'review_request', $title, $note !== '' ? $note : 'No note added.', $link, $sellerId, $productId ?: null);
seller_message_add($pdo, $sellerId, 'seller', 'Review request: ' . $title . ($note !== '' ? ' - ' . $note : ''));
$_SESSION['ops_flash'] = 'Sent. The admin has been notified and will review your corrections.';
header('Location: index.php');
exit;
