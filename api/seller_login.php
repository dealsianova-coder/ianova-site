<?php
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/auth_security.php';
if (method() !== 'POST') fail('Method not allowed.', 405);

$b = body();
$email = auth_norm((string) ($b['email'] ?? ''));
$pass = (string) ($b['password'] ?? '');
if ($email === '' || $pass === '') fail('Enter your email and password.');
if ($w = auth_locked($pdo, 'seller', $email)) fail(auth_lock_message($w), 429);

$s = $pdo->prepare('SELECT id, business_name, email, status, password_hash FROM sellers WHERE email = ?');
$s->execute([$email]);
$seller = $s->fetch();
if (!$seller || !password_verify($pass, $seller['password_hash'])) {
    auth_record($pdo, 'seller', $email);
    fail('Incorrect email or password.', 401);
}
auth_clear($pdo, 'seller', $email);
out(['success' => true, 'token' => issue_seller_token($pdo, (int) $seller['id']), 'seller' => seller_json($seller)]);
