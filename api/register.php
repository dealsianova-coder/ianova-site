<?php
require __DIR__ . '/_bootstrap.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$b = body();
$name = trim((string) ($b['name'] ?? ''));
$email = strtolower(trim((string) ($b['email'] ?? '')));
$pass = (string) ($b['password'] ?? '');
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter your name and a valid email.');
if (strlen($pass) < 6) fail('Password must be at least 6 characters.');
$s = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$s->execute([$email]);
if ($s->fetch()) fail('An account with this email already exists.', 409);
$pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
$id = (int) $pdo->lastInsertId();
out(['success' => true, 'token' => issue_token($pdo, $id),
     'user' => ['id' => $id, 'name' => $name, 'email' => $email]], 201);
