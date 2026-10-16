<?php
/** IANOVA mobile API — seller application (same rules as seller/register.php). */
require __DIR__ . '/_seller.php';
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
require_once __DIR__ . '/../includes/auth_security.php';
if ($pe = auth_password_error($pass)) fail($pe);
if (mb_strlen($about) > 2000) fail('Description is too long (2000 characters max).');

$s = $pdo->prepare('SELECT id FROM sellers WHERE email = ?');
$s->execute([$email]);
if ($s->fetch()) fail('A seller account with this email already exists. Try signing in.', 409);

$pdo->prepare('INSERT INTO sellers (business_name, email, password_hash, phone, business_description, status) VALUES (?,?,?,?,?, "pending")')
    ->execute([$business, $email, password_hash($pass, PASSWORD_DEFAULT), $phone, $about]);
$sellerId = (int) $pdo->lastInsertId();

if ($about !== '') seller_message_add($pdo, $sellerId, 'seller', $about);
seller_message_add($pdo, $sellerId, 'admin', 'Thanks for applying to sell on ' . SITE_NAME . '. We are reviewing your application and will reply here. You can also message us any time.');

if (defined('ADMIN_EMAIL') && ADMIN_EMAIL !== '') {
    send_admin_seller_alert($business, $email, 'New seller application' . ($about !== '' ? ': ' . $about : ''));
}

$seller = ['id' => $sellerId, 'business_name' => $business, 'email' => $email, 'status' => 'pending'];
out([
    'success' => true,
    'message' => 'Application received. We will review it and reply here.',
    'token' => issue_seller_token($pdo, $sellerId),
    'seller' => seller_json($seller),
], 201);
