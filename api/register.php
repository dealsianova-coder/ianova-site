<?php
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/auth_security.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$b = body();
$name = trim((string) ($b['name'] ?? ''));
$email = auth_norm((string) ($b['email'] ?? ''));
$pass = (string) ($b['password'] ?? '');
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter your name and a valid email.');
if ($e = auth_password_error($pass)) fail($e);
if (auth_locked($pdo, 'user', auth_ip(), 'signup')) fail('Too many sign-up attempts. Please wait a few minutes.', 429);
auth_record($pdo, 'user', auth_ip(), 'signup');
$s = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$s->execute([$email]);
if ($s->fetch()) fail('An account with this email already exists.', 409);
$pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
$id = (int) $pdo->lastInsertId();
out(['success' => true, 'token' => issue_token($pdo, $id),
     'user' => ['id' => $id, 'name' => $name, 'email' => $email]], 201);
