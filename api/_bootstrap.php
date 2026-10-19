<?php
/** IANOVA mobile API — shared helpers. Every endpoint requires this file. */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notify.php'; // email push
notify_tick($pdo);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const API_DELIVERY_FEE = 3.50; // matches cart.php / checkout.php

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): void {
    out(['success' => false, 'error' => $message], $code);
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/** Column names of the products table (read once per request). */
function product_column_names(PDO $pdo): array {
    static $names = null;
    if ($names === null) {
        $names = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    }
    return $names;
}

/** Real column that stores the product image: image_url (new) or image (old). */
function image_column(PDO $pdo): string {
    return in_array('image_url', product_column_names($pdo), true) ? 'image_url' : 'image';
}

/** True when the optional products.subcategory column exists. */
function has_subcategory(PDO $pdo): bool {
    return in_array('subcategory', product_column_names($pdo), true);
}

/** True when the optional products.color / products.size columns exist. */
function has_option_columns(PDO $pdo): bool {
    $names = product_column_names($pdo);
    return in_array('color', $names, true) && in_array('size', $names, true);
}

/** Keeps one listing per product name: colors and sizes of the same product are grouped. */
function representative_filter(string $alias = 'p'): string {
    return "$alias.id IN (SELECT COALESCE(MIN(CASE WHEN g.stock > 0 THEN g.id END), MIN(g.id)) FROM products g WHERE g.status = 'approved' AND " . display_sql('g') . " GROUP BY g.category_id, COALESCE(g.seller_id, 0), LOWER(TRIM(g.name)))";
}

/** SQL column list for a product row, always exposing the image as `image`. */
function product_cols(PDO $pdo, string $alias = 'p'): string {
    $img = image_column($pdo);
    $sub = has_subcategory($pdo) ? "COALESCE($alias.subcategory, '') AS subcategory" : "'' AS subcategory";
    $opt = has_option_columns($pdo) ? "COALESCE($alias.color, '') AS color, COALESCE($alias.size, '') AS size" : "'' AS color, '' AS size";
    $cnt = "(SELECT COUNT(*) FROM products o WHERE o.status = 'approved' AND o.category_id = $alias.category_id AND COALESCE(o.seller_id, 0) = COALESCE($alias.seller_id, 0) AND LOWER(TRIM(o.name)) = LOWER(TRIM($alias.name))) AS option_count";
    return "$alias.id, $alias.name, $alias.slug, $alias.description, $alias.price, $alias.original_price, "
         . "$alias.emoji, $alias.bg_color, $alias.rating, $alias.review_count, $alias.stock, "
         . "$alias.is_flash_deal, $alias.seller_name, $alias.category_id, $sub, $opt, $cnt, COALESCE($alias.$img, '') AS image";
}

function issue_token(PDO $pdo, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))')
        ->execute([$userId, hash('sha256', $token)]);
    return $token;
}

function bearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = $v; break; }
        }
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : '';
}

/** Returns the signed-in user row, or sends a 401. */
function require_user(PDO $pdo): array {
    $token = bearer();
    if ($token === '') fail('Please sign in.', 401);
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email FROM api_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch();
    if (!$user) fail('Your session has expired. Please sign in again.', 401);
    return $user;
}

function user_json(array $u): array {
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email']];
}

/** Reads one value from the site's `settings` table (editable in the admin). */
function store_setting(PDO $pdo, string $key): ?string {
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? null : (string) $value;
    } catch (Throwable $e) {
        return null;
    }
}

/** Delivery fee: the admin setting if present, otherwise the built-in default. */
function store_delivery_fee(PDO $pdo): float {
    $value = store_setting($pdo, 'delivery_fee');
    return ($value !== null && is_numeric($value) && (float) $value >= 0) ? (float) $value : (float) API_DELIVERY_FEE;
}

/** Free-delivery limit: the admin setting if present, otherwise config.php. */
function store_free_delivery_threshold(PDO $pdo): float {
    $value = store_setting($pdo, 'free_delivery_threshold');
    if ($value !== null && is_numeric($value) && (float) $value > 0) {
        return (float) $value;
    }
    return (float) FREE_DELIVERY_THRESHOLD;
}

/** Cart lines for a user, shaped for the app's CartItem.fromJson. */
function cart_lines(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT ' . product_cols($pdo) . ', c.quantity
         FROM cart_items c JOIN products p ON p.id = c.product_id
         WHERE c.user_id = ? AND p.status = "approved" ORDER BY c.id'
    );
    $stmt->execute([$userId]);
    $lines = $stmt->fetchAll();
    foreach ($lines as &$l) {
        $l['quantity'] = (int) $l['quantity'];
        $l['item_total'] = round((float) $l['price'] * $l['quantity'], 2);
    }
    return $lines;
}
