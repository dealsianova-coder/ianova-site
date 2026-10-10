<?php
/** GET: profile. POST {action: profile|password, ...}. Works for every seller status. */
require __DIR__ . '/_seller.php';
$seller = require_seller($pdo);
$sid = (int) $seller['id'];

if (method() === 'POST') {
    $b = body();
    $action = (string) ($b['action'] ?? '');

    if ($action === 'profile') {
        $name = trim((string) ($b['business_name'] ?? ''));
        $phone = trim((string) ($b['phone'] ?? ''));
        $about = trim((string) ($b['business_description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) fail('Enter your business name (150 characters max).');
        if (mb_strlen($phone) > 40) fail('Phone number is too long.');
        if (mb_strlen($about) > 2000) fail('Description is too long.');
        $pdo->prepare('UPDATE sellers SET business_name = ?, phone = ?, business_description = ? WHERE id = ?')->execute([$name, $phone, $about, $sid]);
        $pdo->prepare('UPDATE products SET seller_name = ? WHERE seller_id = ?')->execute([$name, $sid]);
    } elseif ($action === 'password') {
        $current = (string) ($b['current_password'] ?? '');
        $new = (string) ($b['new_password'] ?? '');
        $h = $pdo->prepare('SELECT password_hash FROM sellers WHERE id = ?');
        $h->execute([$sid]);
        if (!password_verify($current, (string) $h->fetchColumn())) fail('Your current password is incorrect.', 403);
        if (strlen($new) < 6) fail('New password must be at least 6 characters.');
        $pdo->prepare('UPDATE sellers SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $sid]);
        // Sign out every other device.
        $pdo->prepare('DELETE FROM seller_api_tokens WHERE seller_id = ? AND token_hash <> ?')->execute([$sid, hash('sha256', bearer())]);
    } else {
        fail('Unknown action.');
    }
} elseif (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$q = $pdo->prepare('SELECT id, business_name, email, status, phone, business_description FROM sellers WHERE id = ?');
$q->execute([$sid]);
$r = $q->fetch();
out(['success' => true, 'seller' => [
    'id' => (int) $r['id'], 'business_name' => $r['business_name'], 'email' => $r['email'], 'status' => $r['status'],
    'phone' => (string) $r['phone'], 'business_description' => (string) $r['business_description'],
]]);
