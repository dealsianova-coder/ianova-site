<?php
/** Seller sign-in helpers for the mobile API. */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/seller_messaging.php';
require_once __DIR__ . '/../includes/mailer.php';
ensure_seller_messaging($pdo);

function issue_seller_token(PDO $pdo, int $sellerId): string {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO seller_api_tokens (seller_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))')
        ->execute([$sellerId, hash('sha256', $token)]);
    return $token;
}

/** Signed-in seller row (any status, so rejected applicants can still read replies), or a 401. */
function require_seller(PDO $pdo): array {
    $token = bearer();
    if ($token === '') fail('Please sign in.', 401);
    $stmt = $pdo->prepare(
        'SELECT s.id, s.business_name, s.email, s.status FROM seller_api_tokens t
         JOIN sellers s ON s.id = t.seller_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $seller = $stmt->fetch();
    if (!$seller) fail('Your session has expired. Please sign in again.', 401);
    return $seller;
}

function seller_json(array $s): array {
    return [
        'id' => (int) $s['id'],
        'business_name' => $s['business_name'],
        'email' => $s['email'],
        'status' => $s['status'],
    ];
}
