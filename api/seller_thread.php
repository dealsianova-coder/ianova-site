<?php
/** GET: the signed-in seller's conversation with the admin. POST {message}: send a message. */
require __DIR__ . '/_seller.php';
$seller = require_seller($pdo);
$sid = (int) $seller['id'];

if (method() === 'POST') {
    $text = trim((string) (body()['message'] ?? ''));
    if ($text === '') fail('Write a message first.');
    if (mb_strlen($text) > 2000) fail('Message is too long (2000 characters max).');

    $recent = $pdo->prepare("SELECT COUNT(*) FROM seller_messages WHERE seller_id = ? AND sender = 'seller' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $recent->execute([$sid]);
    if ((int) $recent->fetchColumn() >= 20) fail('You are sending messages too quickly. Please wait a little.', 429);

    seller_message_add($pdo, $sid, 'seller', $text);
    send_admin_seller_alert($seller['business_name'], $seller['email'], $text);
} elseif (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

// Opening the thread marks the admin's replies as read.
$pdo->prepare("UPDATE seller_messages SET read_at = NOW() WHERE seller_id = ? AND sender = 'admin' AND read_at IS NULL")->execute([$sid]);

$messages = array_map(static fn($m) => [
    'id' => (int) $m['id'],
    'sender' => $m['sender'],
    'body' => $m['body'],
    'created_at' => $m['created_at'],
], seller_thread($pdo, $sid));

out(['success' => true, 'seller' => seller_json($seller), 'messages' => $messages]);
