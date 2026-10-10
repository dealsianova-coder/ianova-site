<?php
/** Seller's own products. GET: list + categories. POST {action: save|stock|delete, ...}. */
require __DIR__ . '/_seller.php';
$seller = require_approved_seller($pdo);
$sid = (int) $seller['id'];
$img = image_column($pdo);

function seller_product_row(PDO $pdo, int $id, int $sid, string $img): ?array {
    $q = $pdo->prepare("SELECT id, name, category_id, price, original_price, stock, status, emoji, description, COALESCE($img, '') AS image FROM products WHERE id = ? AND seller_id = ?");
    $q->execute([$id, $sid]);
    $r = $q->fetch();
    return $r ?: null;
}

if (method() === 'POST') {
    $b = body();
    $action = (string) ($b['action'] ?? 'save');
    $id = (int) ($b['id'] ?? 0);

    if ($action === 'stock') {
        $stock = max(0, (int) ($b['stock'] ?? 0));
        $pdo->prepare('UPDATE products SET stock = ? WHERE id = ? AND seller_id = ?')->execute([$stock, $id, $sid]);
        if (!seller_product_row($pdo, $id, $sid, $img)) fail('Product not found.', 404);
    } elseif ($action === 'delete') {
        if (!seller_product_row($pdo, $id, $sid, $img)) fail('Product not found.', 404);
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

        if ($name === '' || mb_strlen($name) > 150) fail('Enter a product name (150 characters max).');
        if ($cat < 1) fail('Choose a category.');
        if ($price <= 0) fail('Enter a price above zero.');
        if ($orig !== null && $orig <= $price) $orig = null; // only keep a "was" price that is higher
        if (mb_strlen($desc) > 3000) fail('Description is too long.');

        $c = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
        $c->execute([$cat]);
        if (!$c->fetch()) fail('That category does not exist.');

        if ($id) {
            if (!seller_product_row($pdo, $id, $sid, $img)) fail('Product not found.', 404);
            $slug = seller_slug($pdo, $name, $id);
            // Like the website: edited listings go back to review before showing again.
            $pdo->prepare("UPDATE products SET category_id=?, name=?, slug=?, description=?, price=?, original_price=?, stock=?, status='pending' WHERE id=? AND seller_id=?")
                ->execute([$cat, $name, $slug, $desc, $price, $orig, $stock, $id, $sid]);
        } else {
            $slug = seller_slug($pdo, $name);
            try {
                $pdo->prepare("INSERT INTO products (category_id, name, slug, description, price, original_price, emoji, bg_color, stock, is_flash_deal, seller_id, seller_name, rating, review_count, status) VALUES (?,?,?,?,?,?,'📦','#EFF3EC',?,0,?,?,0,0,'pending')")
                    ->execute([$cat, $name, $slug, $desc, $price, $orig, $stock, $sid, $seller['business_name']]);
                $id = (int) $pdo->lastInsertId();
            } catch (Throwable $e) {
                fail('Could not save the product. Please try again.', 500);
            }
        }
    }
} elseif (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$list = $pdo->prepare("SELECT id, name, category_id, price, original_price, stock, status, emoji, description, COALESCE($img, '') AS image FROM products WHERE seller_id = ? ORDER BY id DESC");
$list->execute([$sid]);
$products = array_map(static function ($p) {
    $p['id'] = (int) $p['id'];
    $p['category_id'] = (int) $p['category_id'];
    $p['price'] = (float) $p['price'];
    $p['original_price'] = $p['original_price'] === null ? null : (float) $p['original_price'];
    $p['stock'] = (int) $p['stock'];
    return $p;
}, $list->fetchAll());

$cats = $pdo->query('SELECT id, name FROM categories WHERE is_hidden = 0 ORDER BY sort_order, name')->fetchAll();
out(['success' => true, 'products' => $products, 'categories' => $cats]);
