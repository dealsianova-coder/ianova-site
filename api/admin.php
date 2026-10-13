<?php
require __DIR__ . '/_bootstrap.php';
date_default_timezone_set('Africa/Nairobi');

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_tokens (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

$b = body();
$action = (string) ($_GET['action'] ?? $b['action'] ?? '');

if ($action === 'login') {
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $pass = (string) ($b['password'] ?? '');
    $s = $pdo->prepare('SELECT id, name, email, password_hash FROM admins WHERE email = ? LIMIT 1');
    $s->execute([$email]);
    $a = $s->fetch();
    if (!$a || !password_verify($pass, $a['password_hash'])) fail('Incorrect admin email or password.', 401);
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
            if (!in_array($b['status'], ['approved', 'pending', 'rejected'], true)) fail('Invalid status.');
            $sets[] = 'status = ?'; $args[] = $b['status'];
        }
        if (!$sets) fail('Nothing to update.');
        $args[] = $id;
        $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        out(['success' => true]);

    case 'orders':
        $status = trim((string) ($_GET['status'] ?? ''));
        $sql = 'SELECT id, guest_name, guest_phone, address, total, status, created_at FROM orders';
        $args = [];
        if ($status !== '') { $sql .= ' WHERE status = ?'; $args[] = $status; }
        $st = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 100');
        $st->execute($args);
        $rows = $st->fetchAll();
        if ($rows) {
            $img = image_column($pdo);
            $ids = array_map('intval', array_column($rows, 'id'));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $it = $pdo->prepare("SELECT oi.order_id, oi.quantity, COALESCE(p.name, 'Deleted product') AS name, COALESCE(p.emoji, '') AS emoji, COALESCE(p.bg_color, '#EFF3EC') AS bg_color, COALESCE(p.$img, '') AS image
                                 FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id IN ($ph) ORDER BY oi.id");
            $it->execute($ids);
            $byOrder = [];
            foreach ($it->fetchAll() as $r) {
                $byOrder[(int) $r['order_id']][] = ['name' => $r['name'], 'quantity' => (int) $r['quantity'], 'emoji' => $r['emoji'], 'bg_color' => $r['bg_color'], 'image' => $r['image']];
            }
            foreach ($rows as &$row) $row['items'] = array_slice($byOrder[(int) $row['id']] ?? [], 0, 8);
            unset($row);
        }
        out(['success' => true, 'orders' => $rows]);

    case 'order_status':
        $id = (int) ($b['id'] ?? 0);
        $status = (string) ($b['status'] ?? '');
        if ($id < 1 || !in_array($status, ['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'], true)) fail('Invalid order or status.');
        require_once __DIR__ . '/../includes/seller_orders.php';
        $msg = set_order_status($pdo, $id, $status);
        if ($msg !== '') fail($msg, 409);
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

    default:
        fail('Unknown action.', 404);
}
