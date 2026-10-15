<?php
/**
 * Seller's own products. GET: list + categories.
 * POST (JSON or multipart): action = save | stock | delete.
 * "save" accepts the same fields as the admin/seller web form, plus an `image` file.
 */
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/seller_ops.php';
$seller = require_approved_seller($pdo);
$sid = (int) $seller['id'];
$img = image_column($pdo);
$hasSub = has_subcategory($pdo);
$hasOpt = has_option_columns($pdo);
$autoApprove = !empty($seller['auto_approve']);

$select = "id, name, slug, category_id, price, original_price, stock, status, emoji, bg_color, is_flash_deal, description, COALESCE($img, '') AS image, (SELECT name FROM categories c WHERE c.id = products.category_id) AS category_name"
    . ($hasSub ? ", COALESCE(subcategory, '') AS subcategory" : ", '' AS subcategory")
    . ($hasOpt ? ", COALESCE(color, '') AS color, COALESCE(size, '') AS size" : ", '' AS color, '' AS size");

function seller_product_row(PDO $pdo, int $id, int $sid, string $select): ?array {
    $q = $pdo->prepare("SELECT $select FROM products WHERE id = ? AND seller_id = ?");
    $q->execute([$id, $sid]);
    $r = $q->fetch();
    return $r ?: null;
}

/** Products the admin hid or rejected cannot be changed by the seller until the admin approves them. */
function seller_lock_check(array $row): void {
    if (in_array($row['status'], ['hidden', 'rejected'], true)) {
        fail('This product is restricted by the admin. Use "Request review".', 403);
    }
}

/** Saves an uploaded product photo. Returns the relative path, or null when no file was sent. */
function seller_save_image(): ?string {
    if (empty($_FILES['image']['name'])) return null;
    $f = $_FILES['image'];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) fail('That photo is too large for the server. Choose a smaller one.');
    if ($f['error'] !== UPLOAD_ERR_OK) fail('The photo could not be uploaded. Please try again.');
    if ($f['size'] > 5 * 1024 * 1024) fail('Image must be 5MB or smaller.');
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : $f['type'];
    if (!isset($allowed[$mime])) fail('Image must be JPG, PNG, WEBP or GIF.');
    $dir = __DIR__ . '/../uploads/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) fail('The server could not create the uploads folder.', 500);
    $name = 'uploads/products/' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $name)) fail('Could not save the photo.', 500);
    return $name;
}

if (method() === 'POST') {
    $b = !empty($_POST) ? $_POST : body();
    $action = (string) ($b['action'] ?? 'save');
    $id = (int) ($b['id'] ?? 0);

    if ($action === 'request_review') {
        [$ok, $msg] = ops_request_review($pdo, $seller, $id, (string) ($b['note'] ?? ''));
        if (!$ok) fail($msg, 409);
    } elseif ($action === 'stock') {
        $row = seller_product_row($pdo, $id, $sid, $select);
        if (!$row) fail('Product not found.', 404);
        seller_lock_check($row);
        $pdo->prepare('UPDATE products SET stock = ? WHERE id = ? AND seller_id = ?')->execute([max(0, (int) ($b['stock'] ?? 0)), $id, $sid]);
    } elseif ($action === 'delete') {
        $row = seller_product_row($pdo, $id, $sid, $select);
        if (!$row) fail('Product not found.', 404);
        seller_lock_check($row);
        try {
            $pdo->prepare('DELETE FROM products WHERE id = ? AND seller_id = ?')->execute([$id, $sid]);
        } catch (Throwable $e) {
            fail('This product has orders and cannot be deleted. Set its stock to 0 instead.', 409);
        }
    } else {
        $name = trim((string) ($b['name'] ?? ''));
        $cat = (int) ($b['category_id'] ?? 0);
        $price = (float) ($b['price'] ?? 0);
        $origRaw = $b['original_price'] ?? null;
        $orig = ($origRaw === null || $origRaw === '') ? null : (float) $origRaw;
        $stock = max(0, (int) ($b['stock'] ?? 0));
        $desc = trim((string) ($b['description'] ?? ''));
        $sub = mb_substr(trim((string) ($b['subcategory'] ?? '')), 0, 60);
        $color = mb_substr(trim((string) ($b['color'] ?? '')), 0, 40);
        $size = mb_substr(trim((string) ($b['size'] ?? '')), 0, 30);
        $emoji = mb_substr(trim((string) ($b['emoji'] ?? '')), 0, 8);
        if ($emoji === '') $emoji = '📦';
        $bg = trim((string) ($b['bg_color'] ?? ''));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $bg)) $bg = '#EFF3EC';
        $flash = !empty($b['is_flash_deal']) && $b['is_flash_deal'] !== '0' && $b['is_flash_deal'] !== 'false' ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 150) fail('Enter a product name (150 characters max).');
        if ($cat < 1) fail('Choose a category.');
        if ($price <= 0) fail('Enter a price above zero.');
        if ($orig !== null && $orig <= $price) $orig = null;
        if (mb_strlen($desc) > 3000) fail('Description is too long.');
        $c = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
        $c->execute([$cat]);
        if (!$c->fetch()) fail('That category does not exist.');

        $image = seller_save_image();
        // Trusted sellers (set by the admin) publish straight away; others wait for review.
        $status = $autoApprove ? 'approved' : 'pending';

        if ($id) {
            $old = seller_product_row($pdo, $id, $sid, $select);
            if (!$old) fail('Product not found.', 404);
            seller_lock_check($old);
            $slug = ($old['name'] === $name && $old['slug'] !== '') ? $old['slug'] : seller_slug($pdo, $name, $id);
            $set = ['category_id=?', 'name=?', 'slug=?', 'description=?', 'price=?', 'original_price=?', 'emoji=?', 'bg_color=?', 'stock=?', 'is_flash_deal=?', 'status=?'];
            $par = [$cat, $name, $slug, $desc, $price, $orig, $emoji, $bg, $stock, $flash, $status];
            if ($hasSub) { $set[] = 'subcategory=?'; $par[] = $sub; }
            if ($hasOpt) { $set[] = 'color=?'; $set[] = 'size=?'; $par[] = $color; $par[] = $size; }
            if ($image !== null) { $set[] = "$img=?"; $par[] = $image; }
            $par[] = $id; $par[] = $sid;
            $pdo->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id=? AND seller_id=?')->execute($par);
        } else {
            $cols = ['category_id', 'name', 'slug', 'description', 'price', 'original_price', 'emoji', 'bg_color', 'stock', 'is_flash_deal', 'seller_id', 'seller_name', 'rating', 'review_count', 'status'];
            $par = [$cat, $name, seller_slug($pdo, $name), $desc, $price, $orig, $emoji, $bg, $stock, $flash, $sid, $seller['business_name'], 4.5, 0, $status];
            if ($hasSub) { $cols[] = 'subcategory'; $par[] = $sub; }
            if ($hasOpt) { $cols[] = 'color'; $cols[] = 'size'; $par[] = $color; $par[] = $size; }
            if ($image !== null) { $cols[] = $img; $par[] = $image; }
            try {
                $pdo->prepare('INSERT INTO products (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($par);
            } catch (Throwable $e) {
                fail('Could not save the product. Please try again.', 500);
            }
        }
    }
} elseif (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$list = $pdo->prepare("SELECT $select FROM products WHERE seller_id = ? ORDER BY id DESC");
$list->execute([$sid]);
$products = array_map(static function ($p) {
    $p['id'] = (int) $p['id'];
    $p['category_id'] = (int) $p['category_id'];
    $p['price'] = (float) $p['price'];
    $p['original_price'] = $p['original_price'] === null ? null : (float) $p['original_price'];
    $p['stock'] = (int) $p['stock'];
    $p['is_flash_deal'] = (int) $p['is_flash_deal'];
    $p['locked'] = in_array($p['status'], ['hidden', 'rejected'], true);
    unset($p['slug']);
    return $p;
}, $list->fetchAll());

$cats = $pdo->query('SELECT id, name FROM categories WHERE is_hidden = 0 ORDER BY sort_order, name')->fetchAll();
out(['success' => true, 'auto_approve' => $autoApprove, 'products' => $products, 'categories' => $cats]);
