<?php
/**
 * IANOVA — shared helper functions
 */

/** Add a product to the session cart (or bump quantity if already present). */
function cart_add($product_id, $qty = 1) {
    $product_id = (int) $product_id;
    $qty = max(1, (int) $qty);
    if (!isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id] = ['qty' => 0];
    }
    $_SESSION['cart'][$product_id]['qty'] += $qty;
}

/** Remove a product entirely from the cart. */
function cart_remove($product_id) {
    unset($_SESSION['cart'][(int) $product_id]);
}

/** Set an explicit quantity for a product in the cart. */
function cart_set_qty($product_id, $qty) {
    $product_id = (int) $product_id;
    $qty = (int) $qty;
    if ($qty <= 0) {
        cart_remove($product_id);
    } else {
        $_SESSION['cart'][$product_id]['qty'] = $qty;
    }
}

/** Fetch full cart contents joined with live product data. */
function cart_items(PDO $pdo) {
    if (empty($_SESSION['cart'])) return [];
    $ids = array_keys($_SESSION['cart']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND status='approved'");
    $stmt->execute($ids);
    $products = $stmt->fetchAll();

    $items = [];
    foreach ($products as $p) {
        $qty = $_SESSION['cart'][$p['id']]['qty'];
        $items[] = [
            'product'  => $p,
            'qty'      => $qty,
            'subtotal' => round($p['price'] * $qty, 2),
        ];
    }
    return $items;
}

function cart_total(PDO $pdo) {
    $total = 0;
    foreach (cart_items($pdo) as $item) {
        $total += $item['subtotal'];
    }
    return round($total, 2);
}

/** Star rating as a repeated-glyph string, e.g. "★★★★☆". */
function star_string($rating) {
    $full = (int) round($rating);
    $full = max(0, min(5, $full));
    return str_repeat('★', $full) . str_repeat('☆', 5 - $full);
}

function discount_percent($price, $original_price) {
    if (!$original_price || $original_price <= $price) return null;
    return (int) round((($original_price - $price) / $original_price) * 100);
}

/** Renders one product card. Returns HTML (echoed by caller). */
function render_product_card(array $p) {
    $discount = discount_percent($p['price'], $p['original_price']);
    ob_start();
    ?>
    <div class="product-card">
        <a href="product.php?slug=<?= h($p['slug']) ?>" class="product-media" style="background:<?= h($p['bg_color']) ?>;">
            <?php if (!empty($p['image'])): ?>
                <img src="<?= h($p['image']) ?>" alt="<?= h($p['name']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">
            <?php else: ?>
                <?= h($p['emoji']) ?>
            <?php endif; ?>
            <?php if ($discount): ?><span class="badge">-<?= $discount ?>%</span><?php endif; ?>
        </a>
        <form method="post" action="wishlist_action.php" class="wishlist-form">
            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI']) ?>">
            <button type="submit" class="wishlist-btn <?= in_array((int) $p['id'], wishlist_ids(), true) ? 'is-saved' : '' ?>" aria-label="Save to wishlist">♥</button>
        </form>
        <div class="product-body">
            <span class="cat"><?= h($p['seller_name']) ?></span>
            <h4><a href="product.php?slug=<?= h($p['slug']) ?>"><?= h($p['name']) ?></a></h4>
            <div class="stars"><?= star_string($p['rating']) ?> <span class="muted">(<?= (int) $p['review_count'] ?>)</span></div>
            <div class="price-row">
                <span class="now"><?= CURRENCY . number_format($p['price'], 2) ?></span>
                <?php if ($p['original_price']): ?>
                    <span class="was"><?= CURRENCY . number_format($p['original_price'], 2) ?></span>
                <?php endif; ?>
            </div>
            <form method="post" action="cart_action.php">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="add-btn">Add to cart</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Returns the current logged-in user's wishlisted product IDs (cached per request). */
function wishlist_ids() {
    static $ids = null;
    if ($ids !== null) return $ids;
    global $pdo;
    $ids = [];
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT product_id FROM wishlist WHERE user_id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return $ids;
}

/** SQL that keeps one listing per product name (colors and sizes of one product are grouped). */
function dedupe_sql(string $alias = ''): string {
    $a = $alias !== '' ? $alias . '.' : '';
    return "{$a}id IN (SELECT COALESCE(MIN(CASE WHEN g.stock > 0 THEN g.id END), MIN(g.id)) FROM products g WHERE g.status = 'approved' GROUP BY g.category_id, COALESCE(g.seller_id, 0), LOWER(TRIM(g.name)))";
}

/** Best sibling to open for a color / size chip: same other choice and in stock when possible. */
function option_target(array $list, string $key, string $want): array {
    foreach ($list as $o) { if (trim((string) ($o[$key] ?? '')) === $want && (int) $o['stock'] > 0) return $o; }
    foreach ($list as $o) { if ((int) $o['stock'] > 0) return $o; }
    return $list[0];
}

/** Swatch color for common color names (empty when unknown). */
function color_hex(string $name): string {
    static $map = ['black'=>'#111111','white'=>'#FFFFFF','red'=>'#E5173F','blue'=>'#2F6FED','navy'=>'#0B1F3F','green'=>'#2FA84F','pink'=>'#F472B6','purple'=>'#8B5CF6','yellow'=>'#FFD60A','orange'=>'#FF8A00','grey'=>'#9AA0AE','gray'=>'#9AA0AE','brown'=>'#8B5E3C','beige'=>'#E8D8C0','cream'=>'#F5EBDD','gold'=>'#D4AF37','silver'=>'#C0C4CC','maroon'=>'#7B1E3A','teal'=>'#14B8A6','khaki'=>'#C3B091'];
    return $map[strtolower(trim($name))] ?? '';
}

/** Delivery fee from the admin Store control page (falls back to 3.50). */
function delivery_fee_value(PDO $pdo): float {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'delivery_fee'");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && is_numeric($v) && (float) $v >= 0) return (float) $v;
    } catch (Throwable $e) {}
    return 3.5;
}

/** Free-delivery limit from the admin settings (falls back to config.php). */
function free_delivery_value(PDO $pdo): float {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'free_delivery_threshold'");
        $s->execute();
        $v = $s->fetchColumn();
        if ($v !== false && is_numeric($v) && (float) $v > 0) return (float) $v;
    } catch (Throwable $e) {}
    return (float) FREE_DELIVERY_THRESHOLD;
}
