<?php
/** IANOVA mobile API — seller application (same rules as seller/register.php). */
require __DIR__ . '/_bootstrap.php';
if (method() !== 'POST') fail('Method not allowed.', 405);

$b = body();
$business = trim((string) ($b['business_name'] ?? ''));
$email = strtolower(trim((string) ($b['email'] ?? '')));
$phone = trim((string) ($b['phone'] ?? ''));
$about = trim((string) ($b['business_description'] ?? ''));
$pass = (string) ($b['password'] ?? '');

if ($business === '' || $email === '' || $pass === '') fail('Please fill in all required fields.');
if (mb_strlen($business) > 150) fail('Business name is too long.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.');
if (strlen($pass) < 6) fail('Password must be at least 6 characters.');
if (mb_strlen($about) > 2000) fail('Description is too long (2000 characters max).');

$s = $pdo->prepare('SELECT id FROM sellers WHERE email = ?');
$s->execute([$email]);
if ($s->fetch()) fail('A seller account with this email already exists.', 409);

$pdo->prepare('INSERT INTO sellers (business_name, email, password_hash, phone, business_description, status) VALUES (?,?,?,?,?, "pending")')
    ->execute([$business, $email, password_hash($pass, PASSWORD_DEFAULT), $phone, $about]);

out(['success' => true, 'message' => 'Application received. We will review it and get back to you.'], 201);
