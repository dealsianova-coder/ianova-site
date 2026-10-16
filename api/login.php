<?php
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/auth_security.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$b = body();
$email = auth_norm((string) ($b['email'] ?? ''));
$pass = (string) ($b['password'] ?? '');
if ($email === '' || $pass === '') fail('Enter your email and password.');
if ($w = auth_locked($pdo, 'user', $email)) fail(auth_lock_message($w), 429);
$s = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
$s->execute([$email]);
$u = $s->fetch();
// Google-only accounts may have an empty hash; password_verify then simply fails.
if (!$u || $u['password_hash'] === '' || !password_verify($pass, $u['password_hash'])) {
    auth_record($pdo, 'user', $email);
    fail('Incorrect email or password.', 401);
}
auth_clear($pdo, 'user', $email);
out(['success' => true, 'token' => issue_token($pdo, (int) $u['id']), 'user' => user_json($u)]);
