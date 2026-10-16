<?php
require __DIR__ . '/_bootstrap.php';
date_default_timezone_set('Africa/Nairobi');

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_tokens (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

$b = body();
$action = (string) ($_GET['action'] ?? $b['action'] ?? '');

if ($action === 'login') {
    require_once __DIR__ . '/../includes/auth_security.php';
    $email = auth_norm((string) ($b['email'] ?? ''));
    $pass = (string) ($b['password'] ?? '');
    if ($w = auth_locked($pdo, 'admin', $email)) fail(auth_lock_message($w), 429);
    $s = $pdo->prepare('SELECT id, name, email, password_hash FROM admins WHERE email = ? LIMIT 1');
    $s->execute([$email]);
    $a = $s->fetch();
    if (!$a || !password_verify($pass, $a['password_hash'])) { auth_record($pdo, 'admin', $email); fail('Incorrect admin email or password.', 401); }
    auth_clear($pdo, 'admin', $email);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO admin_tokens (admin_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
        ->execute([(int) $a['id'], hash('sha256', $token)]);
    out(['success' => true, 'token' => $token, 'admin' => ['name' => $a['name'], 'email' => $a['email']]]);
}

$token = bearer();
if ($token === '') fail('Please sign in as admin.', 401);
$s = $pdo->prepare('SELECT a.id, a.name FROM admin_tokens t JOIN admins a ON a.id = t.admin_id WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1');
$s->execute([hash('sha256', $token)]);
if (!$s->fetch()) fail('Admin session expired. Please sign in again.', 401);

function put_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT IGNORE INTO settings(setting_key, setting_value) VALUES(?, ?)')->execute([$key, $value]);
    $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
}

switch ($action) {
    case 'summary':
        out(['success' => true, 'summary' => [
            'products' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'approved'")->fetchColumn(),
            'pending_products' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'pending'")->fetchColumn(),
            'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
            'pending_orders' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
        ]]);

    case 'products':
        $q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
        $st = $pdo->prepare('SELECT ' . product_cols($pdo) . ', p.status FROM products p WHERE p.name LIKE ? ORDER BY p.id DESC LIMIT 100');
        $st->execute([$q]);
        out(['success' => true, 'products' => $st->fetchAll()]);

    case 'product_update':
        $id = (int) ($b['id'] ?? 0);
        if ($id < 1) fail('Invalid product.');
        $sets = []; $args = [];
        if (isset($b['price'])) { $v = (float) $b['price']; if ($v <= 0) fail('Price must be above 0.'); $sets[] = 'price = ?'; $args[] = $v; }
        if (isset($b['original_price'])) { $sets[] = 'original_price = ?'; $args[] = max(0, (float) $b['original_price']); }
        if (isset($b['stock'])) { $sets[] = 'stock = ?'; $args[] = max(0, (int) $b['stock']); }
        if (isset($b['is_flash_deal'])) { $sets[] = 'is_flash_deal = ?'; $args[] = $b['is_flash_deal'] ? 1 : 0; }
        if (isset($b['status'])) {
            if (!in_array($b['status'], ['approved', 'pending', 'rejected', 'hidden'], true)) fail('Invalid status.');
            $sets[] = 'status = ?'; $args[] = $b['status'];
        }
        if (!$sets) fail('Nothing to update.');
        $args[] = $id;
        require_once __DIR__ . '/../includes/seller_ops.php';
        $oldStatus = ops_product_status($pdo, $id);
        $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        if (isset($b['status'])) ops_product_status_changed($pdo, $id, $oldStatus, (string) $b['status']);
        out(['success' => true]);

    case 'orders':
        $status = trim((string) ($_GET['status'] ?? ''));
        $sql = 'SELECT id, guest_name, guest_phone, address, total, status, created_at FROM orders';
        $args = [];
        if ($status !== '') { $sql .= ' WHERE status = ?'; $args[] = $status; }
        $st = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 100');
        $st->execute($args);
        out(['success' => true, 'orders' => $st->fetchAll()]);

    case 'order_status':
        $id = (int) ($b['id'] ?? 0);
        $status = (string) ($b['status'] ?? '');
        if ($id < 1 || !in_array($status, ['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'], true)) fail('Invalid order or status.');
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
        out(['success' => true]);

    case 'store_get':
        $keys = ['announcement_enabled' => '0', 'announcement_text' => '', 'delivery_fee' => '3.5', 'free_delivery_threshold' => '1500', 'show_flash' => '1', 'show_sale' => '1', 'show_recent' => '1', 'show_popular' => '1', 'show_categories' => '1', 'flash_ends_at' => '', 'support_phone' => '', 'support_email' => ''];
        $out = [];
        foreach ($keys as $k => $d) { $v = store_setting($pdo, $k); $out[$k] = $v === null ? $d : $v; }
        out(['success' => true, 'store' => $out]);

    case 'store_set':
        $toggles = ['announcement_enabled', 'show_flash', 'show_sale', 'show_recent', 'show_popular', 'show_categories'];
        $numbers = ['delivery_fee', 'free_delivery_threshold'];
        $texts = ['announcement_text', 'support_phone', 'support_email'];
        foreach ($toggles as $k) if (array_key_exists($k, $b)) put_setting($pdo, $k, $b[$k] ? '1' : '0');
        foreach ($numbers as $k) if (array_key_exists($k, $b)) put_setting($pdo, $k, (string) max(0, (float) $b[$k]));
        foreach ($texts as $k) if (array_key_exists($k, $b)) put_setting($pdo, $k, substr(trim((string) $b[$k]), 0, 200));
        if (array_key_exists('flash_ends_at', $b)) {
            $raw = trim((string) $b['flash_ends_at']);
            $ts = $raw === '' ? false : strtotime($raw);
            put_setting($pdo, 'flash_ends_at', $ts ? date('Y-m-d H:i:s', $ts) : '');
        }
        out(['success' => true]);

    case 'badges':
        require_once __DIR__ . '/../includes/seller_ops.php';
        ops_ensure($pdo);
        out(['success' => true,
            'alerts' => ops_unread_count($pdo),
            'messages' => (int) $pdo->query("SELECT COUNT(*) FROM seller_messages WHERE sender = 'seller' AND read_at IS NULL AND body NOT LIKE 'Review request:%'")->fetchColumn(),
        ]);

    case 'alerts':
        require_once __DIR__ . '/../includes/seller_ops.php';
        ops_ensure($pdo);
        out(['success' => true, 'alerts' => $pdo->query('SELECT id, kind, title, body, link, seller_id, product_id, created_at, read_at FROM admin_notifications ORDER BY id DESC LIMIT 80')->fetchAll()]);

    case 'alerts_read':
        require_once __DIR__ . '/../includes/seller_ops.php';
        ops_ensure($pdo);
        if (!empty($b['all'])) {
            $pdo->exec('UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL');
        } else {
            $pdo->prepare('UPDATE admin_notifications SET read_at = NOW() WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        }
        out(['success' => true]);

    case 'seller_list':
        require_once __DIR__ . '/../includes/seller_ops.php';
        ops_ensure($pdo);
        $rows = $pdo->query(
            "SELECT s.id, s.business_name, s.email, s.phone, s.status,
                (SELECT COUNT(*) FROM seller_messages m WHERE m.seller_id = s.id AND m.sender = 'seller' AND m.read_at IS NULL) AS unread,
                (SELECT COUNT(*) FROM products p WHERE p.seller_id = s.id) AS products,
                (SELECT m2.body FROM seller_messages m2 WHERE m2.seller_id = s.id ORDER BY m2.id DESC LIMIT 1) AS last_message
             FROM sellers s ORDER BY unread DESC, FIELD(s.status, 'pending', 'approved', 'suspended', 'rejected'), s.id DESC LIMIT 200"
        )->fetchAll();
        out(['success' => true, 'sellers' => $rows]);

    case 'seller_thread':
        require_once __DIR__ . '/../includes/seller_ops.php';
        ops_ensure($pdo);
        $sellerId = (int) ($_GET['id'] ?? $b['id'] ?? 0);
        $st = $pdo->prepare('SELECT id, business_name, email, phone, status FROM sellers WHERE id = ?');
        $st->execute([$sellerId]);
        $sellerRow = $st->fetch();
        if (!$sellerRow) fail('Seller not found.', 404);
        $pdo->prepare("UPDATE seller_messages SET read_at = NOW() WHERE seller_id = ? AND sender = 'seller' AND read_at IS NULL")->execute([$sellerId]);
        out(['success' => true, 'seller' => $sellerRow, 'messages' => seller_thread($pdo, $sellerId)]);

    case 'seller_reply':
        require_once __DIR__ . '/../includes/seller_ops.php';
        ops_ensure($pdo);
        $sellerId = (int) ($b['id'] ?? 0);
        $text = trim((string) ($b['message'] ?? ''));
        if ($text === '' || mb_strlen($text) > 2000) fail('Write a message (2000 characters max).');
        $st = $pdo->prepare('SELECT business_name, email FROM sellers WHERE id = ?');
        $st->execute([$sellerId]);
        $sellerRow = $st->fetch();
        if (!$sellerRow) fail('Seller not found.', 404);
        seller_message_add($pdo, $sellerId, 'admin', $text);
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            if (function_exists('send_seller_reply_email')) send_seller_reply_email($sellerRow['email'], $sellerRow['business_name'], $text);
        } catch (Throwable $e) {
        }
        out(['success' => true]);

    case 'seller_set_status':
        require_once __DIR__ . '/../includes/seller_ops.php';
        ops_ensure($pdo);
        $sellerId = (int) ($b['id'] ?? 0);
        $new = (string) ($b['status'] ?? '');
        if (!in_array($new, ['approved', 'suspended', 'rejected'], true)) fail('Invalid status.');
        $st = $pdo->prepare('SELECT status FROM sellers WHERE id = ?');
        $st->execute([$sellerId]);
        $old = $st->fetchColumn();
        if ($old === false) fail('Seller not found.', 404);
        $pdo->prepare('UPDATE sellers SET status = ? WHERE id = ?')->execute([$new, $sellerId]);
        ops_seller_status_changed($pdo, $sellerId, (string) $old, $new);
        out(['success' => true]);

    case 'broadcast_options':
        require_once __DIR__ . '/../includes/broadcast.php';
        bc_ensure($pdo);
        out(['success' => true, 'sellers' => bc_sellers($pdo), 'history' => bc_history($pdo, 10)]);

    case 'broadcast_send':
        require_once __DIR__ . '/../includes/broadcast.php';
        bc_ensure($pdo);
        $bcRes = bc_send($pdo, (string) ($b['message'] ?? ''), (array) ($b['ids'] ?? []), (string) ($b['status'] ?? ''), !empty($b['mention']), !empty($b['email']));
        if (isset($bcRes['error'])) fail($bcRes['error']);
        out(['success' => true, 'count' => $bcRes['count'], 'names' => $bcRes['names'], 'changed' => $bcRes['changed'], 'emailed' => $bcRes['emailed'], 'email_failed' => $bcRes['email_failed']]);

    default:
        fail('Unknown action.', 404);
}
