<?php
/** POST {kind: user|seller|admin, email} -> emails a reset link + 6-digit code. Always answers the same. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/auth_security.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$b = body();
$kind = (string) ($b['kind'] ?? 'user');
$email = auth_norm((string) ($b['email'] ?? ''));
if (!in_array($kind, ['user', 'seller', 'admin'], true)) fail('Unknown account type.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
auth_reset_start($pdo, $kind, $email);
out(['success' => true, 'message' => 'If that email has an account, we sent a code and a link. It works for ' . AUTH_RESET_MINUTES . ' minutes.']);
