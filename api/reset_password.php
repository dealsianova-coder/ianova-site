<?php
/** POST {kind, email, code, password} -> sets a new password using the emailed 6-digit code. */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/auth_security.php';
if (method() !== 'POST') fail('Method not allowed.', 405);
$b = body();
$kind = (string) ($b['kind'] ?? 'user');
$email = auth_norm((string) ($b['email'] ?? ''));
if (!in_array($kind, ['user', 'seller', 'admin'], true)) fail('Unknown account type.');
$err = auth_reset_with_code($pdo, $kind, $email, (string) ($b['code'] ?? ''), (string) ($b['password'] ?? ''));
if ($err) fail($err, 400);
auth_clear($pdo, $kind, $email);
out(['success' => true, 'message' => 'Password changed. You can sign in now.']);
