<?php
/**
 * Seller <-> admin messaging. Tables are created on first use, so there is no
 * separate migration to run.
 */

function ensure_seller_messaging(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS seller_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seller_id INT NOT NULL,
        sender ENUM('seller','admin') NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_seller_thread (seller_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS seller_api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seller_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_token (token_hash),
        INDEX idx_seller (seller_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Per-seller switch: when 1, products this seller adds or edits go live without review.
    $col = $pdo->query("SHOW COLUMNS FROM sellers LIKE 'auto_approve'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE sellers ADD COLUMN auto_approve TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (function_exists('ensure_seller_orders')) ensure_seller_orders($pdo);
    $done = true;
}

function seller_message_add(PDO $pdo, int $sellerId, string $sender, string $body): void {
    $pdo->prepare('INSERT INTO seller_messages (seller_id, sender, body) VALUES (?, ?, ?)')
        ->execute([$sellerId, $sender, $body]);
}

/** All messages for a seller, oldest first. */
function seller_thread(PDO $pdo, int $sellerId): array {
    $s = $pdo->prepare('SELECT id, sender, body, created_at FROM seller_messages WHERE seller_id = ? ORDER BY id');
    $s->execute([$sellerId]);
    return $s->fetchAll();
}
