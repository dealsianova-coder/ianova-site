<?php
require __DIR__ . '/_bootstrap.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$b = body();
$email = strtolower(trim((string) ($b['email'] ?? '')));
$pass = (string) ($b['password'] ?? '');
$s = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
$s->execute([$email]);
$u = $s->fetch();
// Google-only accounts may have an empty hash; password_verify then simply fails.
if (!$u || $u['password_hash'] === '' || !password_verify($pass, $u['password_hash'])) {
    fail('Incorrect email or password.', 401);
}
out(['success' => true, 'token' => issue_token($pdo, (int) $u['id']), 'user' => user_json($u)]);
