<?php
require __DIR__ . '/_seller.php';
if (method() !== 'POST') fail('Method not allowed.', 405);

$b = body();
$email = strtolower(trim((string) ($b['email'] ?? '')));
$pass = (string) ($b['password'] ?? '');

$s = $pdo->prepare('SELECT id, business_name, email, status, password_hash FROM sellers WHERE email = ?');
$s->execute([$email]);
$seller = $s->fetch();
if (!$seller || !password_verify($pass, $seller['password_hash'])) {
    fail('Incorrect email or password.', 401);
}

out(['success' => true, 'token' => issue_seller_token($pdo, (int) $seller['id']), 'seller' => seller_json($seller)]);
