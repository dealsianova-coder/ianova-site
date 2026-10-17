<?php
/** POST {current, password} with the shopper's bearer token -> changes the password, keeps this device signed in. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/auth_security.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$u = require_user($pdo);
$b = body();
$cur = (string) ($b['current'] ?? '');
$new = (string) ($b['password'] ?? '');
if ($w = auth_locked($pdo, 'user', $u['email'])) fail(auth_lock_message($w), 429);
$s = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$s->execute([(int) $u['id']]);
$hash = (string) $s->fetchColumn();
if ($hash === '' || !password_verify($cur, $hash)) {
    auth_record($pdo, 'user', $u['email']);
    fail('Your current password is not correct.', 400);
}
if ($e = auth_password_error($new)) fail($e);
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int) $u['id']]);
$pdo->prepare('DELETE FROM api_tokens WHERE user_id = ? AND token_hash <> ?')->execute([(int) $u['id'], hash('sha256', bearer())]);
auth_clear($pdo, 'user', $u['email']);
out(['success' => true]);
