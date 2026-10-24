<?php
/** An app tells the server its Firebase token after sign-in (and removes it on sign-out). */
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/push.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$b = body();
$kind = (string) ($b['kind'] ?? 'app');
$token = trim((string) ($b['token'] ?? ''));
if (!in_array($kind, ['app', 'seller', 'admin'], true)) fail('Unknown app.');
if ($token === '' || strlen($token) > 255) fail('Missing device token.');
if ($kind === 'seller') {
    $owner = (int) require_seller($pdo)['id'];
} elseif ($kind === 'admin') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tokens (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $bt = bearer();
    if ($bt === '') fail('Please sign in as admin.', 401);
    $q = $pdo->prepare("SELECT a.id FROM admin_tokens t JOIN admins a ON a.id = t.admin_id WHERE t.token_hash = ? AND t.expires_at > NOW() AND a.status = 'active' LIMIT 1");
    $q->execute([hash('sha256', $bt)]);
    $owner = (int) $q->fetchColumn();
    if ($owner < 1) fail('Admin session expired. Please sign in again.', 401);
} else {
    $owner = (int) require_user($pdo)['id'];
}
push_ensure($pdo);
if (!empty($b['remove'])) {
    $pdo->prepare('DELETE FROM device_tokens WHERE token = ? AND kind = ? AND owner_id = ?')->execute([$token, $kind, $owner]);
    out(['success' => true]);
}
$platform = substr(preg_replace('/[^a-z]/', '', strtolower((string) ($b['platform'] ?? 'android'))), 0, 12) ?: 'android';
$pdo->prepare('INSERT INTO device_tokens (kind, owner_id, token, platform) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE kind = VALUES(kind), owner_id = VALUES(owner_id), platform = VALUES(platform)')
    ->execute([$kind, $owner, $token, $platform]);
out(['success' => true, 'push_ready' => push_enabled()]);
