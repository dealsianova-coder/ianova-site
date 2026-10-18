<?php
/**
 * IANOVA Admin app: everything the website admin can do, as JSON.
 * Separate from api/admin.php on purpose (that file keeps login, store settings, alerts, seller chat).
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';
require_once __DIR__ . '/../includes/seller_ops.php';
require_once __DIR__ . '/../includes/gallery.php';
date_default_timezone_set('Africa/Nairobi');

// ---- admin session (same tokens as api/admin.php) -------------------------------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_tokens (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$token = bearer();
if ($token === '') fail('Please sign in as admin.', 401);
$st0 = $pdo->prepare('SELECT a.id FROM admin_tokens t JOIN admins a ON a.id = t.admin_id WHERE t.token_hash = ? AND t.expires_at > NOW() LIMIT 1');
$st0->execute([hash('sha256', $token)]);
if (!$st0->fetch()) fail('Admin session expired. Please sign in again.', 401);

$b = body();
if (!$b) $b = $_POST;
$action = (string) ($_GET['action'] ?? $b['action'] ?? '');
ops_ensure($pdo);
gallery_ensure($pdo);

// ---- helpers --------------------------------------------------------------------------------
function apx_rows(PDO $pdo, string $sql, array $par = []): array { try { $q = $pdo->prepare($sql); $q->execute($par); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } }
function apx_val(PDO $pdo, string $sql, array $par = []) { try { $q = $pdo->prepare($sql); $q->execute($par); $v = $q->fetchColumn(); return $v === false ? 0 : $v; } catch (Throwable $e) { return 0; } }
function apx_put_setting(PDO $pdo, string $key, string $value): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB");
    $pdo->prepare('INSERT IGNORE INTO settings(setting_key, setting_value) VALUES(?, ?)')->execute([$key, $value]);
    $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
}
function apx_line(array $l): array {
    return [
        'product_id' => (int) ($l['product_id'] ?? 0),
        'name' => (string) ($l['name'] ?? 'Deleted product'),
        'image' => (string) ($l['image'] ?? ''),
        'emoji' => (string) ($l['emoji'] ?? ''),
        'bg_color' => (string) ($l['bg_color'] ?? ''),
        'quantity' => (int) ($l['quantity'] ?? 0),
        'unit_price' => (float) ($l['unit_price'] ?? 0),
        'color' => (string) ($l['color'] ?? ''),
        'size' => (string) ($l['size'] ?? ''),
        'seller_id' => (int) ($l['seller_id'] ?? 0),
        'seller_name' => (string) ($l['seller_name'] ?? 'IANOVA'),
    ];
}
function apx_thumbs(array $lines, int $n = 4): array {
    $o = [];
    foreach (array_slice($lines, 0, $n) as $l) $o[] = ['image' => $l['image'] ?? '', 'emoji' => $l['emoji'] ?? '', 'bg_color' => $l['bg_color'] ?? ''];
    return $o;
}
function apx_image_upload(string $field, string $dir, string $prefix): ?string {
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    $f = $_FILES[$field];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : ($f['type'] ?? '');
    if (!isset($allowed[$mime]) || (int) $f['size'] > 5 * 1024 * 1024) return null;
    $abs = __DIR__ . '/../' . $dir;
    if (!is_dir($abs)) @mkdir($abs, 0775, true);
    $name = bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
    return move_uploaded_file($f['tmp_name'], $abs . '/' . $name) ? $dir . '/' . $name : null;
}
/** Collects $_FILES entries named "<prefix>_0..<prefix>_N" into the array shape gallery_save_files() expects. */
function apx_gallery_files(string $prefix, int $max = 10): array {
    $f = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => [], 'type' => []];
    for ($i = 0; $i < $max; $i++) {
        $k = $prefix . '_' . $i;
        if (empty($_FILES[$k]['name'])) continue;
        foreach (['name', 'tmp_name', 'error', 'size', 'type'] as $x) $f[$x][] = $_FILES[$k][$x];
    }
    return $f;
}
function apx_unique_slug(PDO $pdo, string $slug, int $id, string $color, string $size): string {
    $clean = function (string $v): string { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($v)), '-'); };
    $base = $slug; $n = 1;
    while (true) {
        $q = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND id <> ?');
        $q->execute([$slug, $id]);
        if (!$q->fetch()) return $slug;
        $extra = trim($clean($color) . '-' . $clean($size), '-');
        $slug = $base . (($n === 1 && $extra !== '') ? '-' . $extra : '-' . ($extra !== '' ? $extra . '-' : '') . $n);
        $n++;
        if ($n > 50) return $base . '-' . time();
    }
}
function apx_slugify(string $v): string { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($v)), '-'); }

$STATUSES = ['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'];
$img = image_column($pdo);

switch ($action) {

    // ============================== DASHBOARD ==============================
    case 'dashboard':
        $counts = [
            'products' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM products'),
            'categories' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM categories'),
            'orders' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM orders'),
            'users' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM users'),
            'sellers' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM sellers'),
            'revenue' => (float) apx_val($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','shipped','delivered')"),
            'pending_orders' => (int) apx_val($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'"),
            'review_products' => (int) apx_val($pdo, "SELECT COUNT(*) FROM products WHERE status = 'pending'"),
            'low_stock' => (int) apx_val($pdo, "SELECT COUNT(*) FROM products WHERE status = 'approved' AND stock <= 3"),
            'pending_sellers' => (int) apx_val($pdo, "SELECT COUNT(*) FROM sellers WHERE status = 'pending'"),
            'unread_messages' => (int) apx_val($pdo, "SELECT COUNT(*) FROM seller_messages WHERE sender = 'seller' AND read_at IS NULL"),
            'alerts' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL'),
        ];
        // IANOVA orders
        [$sql, $par] = aou_scope_sql('main');
        $q = $pdo->prepare("SELECT o.id, COALESCE(o.guest_name, u.name, 'Guest') customer, o.total, o.status, o.created_at FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE 1=1" . $sql . ' ORDER BY o.id DESC LIMIT 5');
        $q->execute($par);
        $mainRows = $q->fetchAll(PDO::FETCH_ASSOC);
        $mainItems = aou_items($pdo, array_column($mainRows, 'id'));
        $main = [];
        foreach ($mainRows as $r) {
            $lines = array_map('apx_line', aou_scope_lines($mainItems[(int) $r['id']] ?? [], 'main'));
            $main[] = ['id' => (int) $r['id'], 'customer' => $r['customer'], 'status' => $r['status'], 'created_at' => $r['created_at'],
                'total' => aou_lines_total($lines), 'thumbs' => apx_thumbs($lines)];
        }
        // Seller orders, one group per seller
        [$sql, $par] = aou_scope_sql('sellers');
        $q = $pdo->prepare("SELECT o.id, COALESCE(o.guest_name, u.name, 'Guest') customer, o.total, o.status, o.created_at FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE 1=1" . $sql . ' ORDER BY o.id DESC LIMIT 40');
        $q->execute($par);
        $sellerRows = $q->fetchAll(PDO::FETCH_ASSOC);
        $sIds = array_column($sellerRows, 'id');
        $sItems = aou_items($pdo, $sIds);
        $sAppr = aou_approvals($pdo, $sIds);
        $groups = aou_group_by_seller($sellerRows, $sItems);
        $sCounts = aou_seller_counts($pdo);
        $sellerGroups = [];
        foreach ($groups as $sid => $g) {
            $orders = [];
            foreach (array_slice($g['rows'], 0, 3) as [$o, $lines]) {
                $lines = array_map('apx_line', $lines);
                $a = $sAppr[(int) $o['id']][(int) $sid] ?? null;
                $orders[] = ['id' => (int) $o['id'], 'customer' => $o['customer'], 'status' => $o['status'], 'created_at' => $o['created_at'],
                    'total' => aou_lines_total($lines), 'thumbs' => apx_thumbs($lines), 'approved' => $a ? (bool) $a['at'] : null];
            }
            $sellerGroups[] = ['seller_id' => (int) $sid, 'name' => $g['name'], 'orders' => (int) ($sCounts[$sid]['orders'] ?? count($g['rows'])),
                'waiting' => (int) ($sCounts[$sid]['waiting'] ?? 0), 'recent' => $orders];
        }
        $recent = apx_rows($pdo, "SELECT p.id, p.name, p.price, p.stock, p.status, p.$img AS image, p.emoji, p.bg_color, COALESCE(s.business_name, p.seller_name, 'IANOVA') seller FROM products p LEFT JOIN sellers s ON s.id = p.seller_id ORDER BY p.id DESC LIMIT 6");
        $low = apx_rows($pdo, "SELECT id, name, stock, $img AS image, emoji, bg_color FROM products WHERE status = 'approved' AND stock <= 3 ORDER BY stock, id LIMIT 5");
        $cats = apx_rows($pdo, 'SELECT c.id, c.name, c.emoji, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) n FROM categories c ORDER BY n DESC, c.name LIMIT 12');
        $sellers = apx_rows($pdo, "SELECT s.id, s.business_name, s.status, (SELECT COUNT(*) FROM products p WHERE p.seller_id = s.id) products FROM sellers s ORDER BY (s.status = 'pending') DESC, s.id DESC LIMIT 6");
        foreach ($sellers as &$s0) { $c0 = $sCounts[(int) $s0['id']] ?? ['orders' => 0, 'waiting' => 0]; $s0['orders'] = (int) $c0['orders']; $s0['waiting'] = (int) $c0['waiting']; }
        unset($s0);
        $users = apx_rows($pdo, 'SELECT id, name, email FROM users ORDER BY id DESC LIMIT 5');
        out(['success' => true, 'counts' => $counts,
            'main' => ['count' => aou_scope_count($pdo, 'main'), 'orders' => $main],
            'sellers_orders' => ['count' => aou_scope_count($pdo, 'sellers'), 'groups' => $sellerGroups],
            'recent_products' => $recent, 'low_items' => $low, 'categories' => $cats, 'sellers' => $sellers, 'users' => $users]);

    case 'nav_counts':
        out(['success' => true,
            'alerts' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL'),
            'messages' => (int) apx_val($pdo, "SELECT COUNT(*) FROM seller_messages WHERE sender = 'seller' AND read_at IS NULL AND body NOT LIKE 'Review request:%'"),
            'suggestions' => (int) apx_val($pdo, "SELECT COUNT(*) FROM suggestions WHERE status = 'new'"),
            'pending_orders' => (int) apx_val($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'"),
            'review_products' => (int) apx_val($pdo, "SELECT COUNT(*) FROM products WHERE status = 'pending'"),
            'pending_sellers' => (int) apx_val($pdo, "SELECT COUNT(*) FROM sellers WHERE status = 'pending'"),
        ]);

    // ============================== ORDERS ==============================
    case 'orders_list':
        $tab = (($_GET['tab'] ?? '') === 'sellers') ? 'sellers' : 'main';
        $sellerId = $tab === 'sellers' ? (int) ($_GET['seller'] ?? 0) : 0;
        $filter = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
        $qtext = trim((string) ($_GET['q'] ?? ''));
        [$scopeSql, $scopePar] = aou_scope_sql($tab, $sellerId);
        $cnt = $pdo->prepare('SELECT o.status, COUNT(*) c FROM orders o WHERE 1=1' . $scopeSql . ' GROUP BY o.status');
        $cnt->execute($scopePar);
        $byStatus = [];
        foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $r) $byStatus[$r['status']] = (int) $r['c'];
        $sql = "SELECT o.id, o.total, o.status, o.created_at, o.guest_phone, o.address, COALESCE(o.guest_name, u.name, 'Guest') customer, COALESCE(o.guest_email, u.email) email
                FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE 1=1" . $scopeSql;
        $par = $scopePar;
        if ($filter !== '') { $sql .= ' AND o.status = ?'; $par[] = $filter; }
        if ($qtext !== '') {
            $sql .= " AND (o.id = ? OR COALESCE(o.guest_name, u.name, '') LIKE ? OR COALESCE(o.guest_phone, '') LIKE ? OR COALESCE(o.guest_email, u.email, '') LIKE ?)";
            $like = '%' . $qtext . '%';
            array_push($par, (int) ltrim($qtext, '#'), $like, $like, $like);
        }
        $q = $pdo->prepare($sql . ' ORDER BY o.id DESC LIMIT 200');
        $q->execute($par);
        $orders = $q->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_column($orders, 'id');
        $items = aou_items($pdo, $ids);
        $appr = aou_approvals($pdo, $ids);
        $list = [];
        foreach ($orders as $o) {
            $lines = array_map('apx_line', aou_scope_lines($items[(int) $o['id']] ?? [], $tab, $sellerId));
            $ap = [];
            foreach ($appr[(int) $o['id']] ?? [] as $sid => $a) {
                if ($sellerId > 0 && (int) $sid !== $sellerId) continue;
                $ap[] = ['seller_id' => (int) $sid, 'name' => (string) $a['name'], 'approved' => (bool) $a['at']];
            }
            $list[] = ['id' => (int) $o['id'], 'customer' => $o['customer'], 'email' => (string) $o['email'], 'phone' => (string) ($o['guest_phone'] ?? ''),
                'address' => (string) ($o['address'] ?? ''), 'total' => (float) $o['total'], 'status' => $o['status'], 'created_at' => $o['created_at'],
                'lines' => $lines, 'lines_total' => aou_lines_total($lines), 'approvals' => $ap];
        }
        $chips = [];
        foreach (aou_seller_counts($pdo) as $sid => $s0) $chips[] = ['id' => (int) $sid, 'name' => $s0['name'], 'orders' => $s0['orders'], 'waiting' => $s0['waiting']];
        out(['success' => true, 'tab' => $tab, 'n_main' => aou_scope_count($pdo, 'main'), 'n_sellers' => aou_scope_count($pdo, 'sellers'),
            'status_counts' => $byStatus, 'seller_chips' => $chips, 'orders' => $list]);

    case 'order_view':
        $id = (int) ($_GET['id'] ?? $b['id'] ?? 0);
        $s = $pdo->prepare("SELECT o.id, o.total, o.status, o.created_at, o.guest_phone, o.address, COALESCE(o.guest_name, u.name, 'Guest') customer, COALESCE(o.guest_email, u.email) email FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
        $s->execute([$id]);
        $o = $s->fetch(PDO::FETCH_ASSOC);
        if (!$o) fail('Order not found.', 404);
        $lines = aou_items($pdo, [$id])[$id] ?? [];
        $appr = aou_approvals($pdo, [$id])[$id] ?? [];
        $sub = aou_lines_total($lines);
        $groups = [];
        foreach ($lines as $l) {
            $sid = (int) ($l['seller_id'] ?? 0);
            if (!isset($groups[$sid])) $groups[$sid] = ['seller_id' => $sid, 'name' => $sid === 0 ? 'IANOVA (main)' : (string) $l['seller_name'], 'is_main' => $sid === 0, 'lines' => [], 'approved' => $sid === 0 ? null : (isset($appr[$sid]) ? (bool) $appr[$sid]['at'] : false)];
            $groups[$sid]['lines'][] = apx_line($l);
        }
        ksort($groups);
        $out = [];
        foreach ($groups as $g) { $g['total'] = aou_lines_total($g['lines']); $out[] = $g; }
        out(['success' => true, 'order' => ['id' => (int) $o['id'], 'status' => $o['status'], 'created_at' => $o['created_at'], 'customer' => $o['customer'], 'email' => (string) $o['email'],
            'phone' => (string) ($o['guest_phone'] ?? ''), 'address' => (string) ($o['address'] ?? ''), 'total' => (float) $o['total']],
            'groups' => $out, 'subtotal' => $sub, 'delivery' => round((float) $o['total'] - $sub, 2)]);

    // ============================== PRODUCTS ==============================
    case 'products_list':
        $qtext = trim((string) ($_GET['q'] ?? ''));
        $filter = (string) ($_GET['status'] ?? '');
        $cat = (int) ($_GET['category'] ?? 0);
        $sql = "SELECT p.id, p.name, p.price, p.original_price, p.stock, p.status, p.is_flash_deal, p.$img AS image, p.emoji, p.bg_color, p.color, p.size, c.name category,
                COALESCE(s.business_name, p.seller_name, 'IANOVA') seller
                FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN sellers s ON s.id = p.seller_id";
        $where = []; $par = [];
        if ($qtext !== '') { $where[] = '(p.name LIKE ? OR p.slug LIKE ? OR p.seller_name LIKE ?)'; $like = '%' . $qtext . '%'; array_push($par, $like, $like, $like); }
        if (in_array($filter, ['pending', 'approved', 'rejected', 'hidden'], true)) { $where[] = 'p.status = ?'; $par[] = $filter; }
        if ($cat > 0) { $where[] = 'p.category_id = ?'; $par[] = $cat; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY (p.status = 'pending') DESC, p.id DESC LIMIT 300";
        $rows = apx_rows($pdo, $sql, $par);
        $counts = ['all' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM products')];
        foreach (['pending', 'approved', 'rejected', 'hidden'] as $s1) $counts[$s1] = (int) apx_val($pdo, 'SELECT COUNT(*) FROM products WHERE status = ?', [$s1]);
        out(['success' => true, 'products' => $rows, 'counts' => $counts]);

    case 'product_view':
        $id = (int) ($_GET['id'] ?? $b['id'] ?? 0);
        $q = $pdo->prepare("SELECT p.*, p.$img AS image_path, c.name AS category, COALESCE(s.business_name, p.seller_name, 'IANOVA') AS seller FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN sellers s ON s.id = p.seller_id WHERE p.id = ?");
        $q->execute([$id]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p) fail('Product not found.', 404);
        $gal = gallery_rows($pdo, $id);
        $imgs = gallery_list((string) ($p['image_path'] ?? ''), array_column($gal, 'image'));
        $others = apx_rows($pdo, "SELECT id, color, size, stock, $img AS image FROM products WHERE category_id = ? AND COALESCE(seller_id, 0) = COALESCE(?, 0) AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? ORDER BY id", [$p['category_id'], $p['seller_id'], $p['name'], $id]);
        out(['success' => true, 'product' => [
            'id' => (int) $p['id'], 'name' => $p['name'], 'slug' => $p['slug'], 'description' => (string) ($p['description'] ?? ''), 'price' => (float) $p['price'],
            'original_price' => $p['original_price'] === null ? null : (float) $p['original_price'], 'stock' => (int) $p['stock'], 'status' => (string) ($p['status'] ?? 'approved'),
            'category_id' => (int) $p['category_id'], 'category' => (string) ($p['category'] ?? ''), 'subcategory' => (string) ($p['subcategory'] ?? ''), 'seller' => $p['seller'],
            'seller_name' => (string) ($p['seller_name'] ?? ''), 'color' => (string) ($p['color'] ?? ''), 'size' => (string) ($p['size'] ?? ''), 'is_flash_deal' => (int) ($p['is_flash_deal'] ?? 0),
            'rating' => (float) ($p['rating'] ?? 0), 'review_count' => (int) ($p['review_count'] ?? 0), 'emoji' => (string) ($p['emoji'] ?? ''), 'bg_color' => (string) ($p['bg_color'] ?? ''),
            'image' => (string) ($p['image_path'] ?? '')],
            'images' => $imgs, 'gallery' => $gal, 'others' => $others]);

    case 'product_form_data':
        out(['success' => true,
            'categories' => apx_rows($pdo, 'SELECT id, name, emoji FROM categories ORDER BY sort_order, name'),
            'subcategories' => array_column(apx_rows($pdo, "SELECT DISTINCT subcategory FROM products WHERE subcategory <> '' ORDER BY subcategory"), 'subcategory')]);

    case 'product_save':
        $id = (int) ($b['id'] ?? 0);
        $old = null;
        if ($id) { $s = $pdo->prepare('SELECT * FROM products WHERE id = ?'); $s->execute([$id]); $old = $s->fetch(PDO::FETCH_ASSOC); if (!$old) fail('Product not found.', 404); }
        $name = trim((string) ($b['name'] ?? ''));
        $slug = apx_slugify(trim((string) ($b['slug'] ?? '')) !== '' ? (string) $b['slug'] : $name);
        $cat = (int) ($b['category_id'] ?? 0);
        $price = (float) ($b['price'] ?? 0);
        $origRaw = trim((string) ($b['original_price'] ?? ''));
        $orig = $origRaw === '' ? null : (float) $origRaw;
        $stock = max(0, (int) ($b['stock'] ?? 0));
        $rating = min(5, max(0, (float) ($b['rating'] ?? 4.5)));
        $reviews = max(0, (int) ($b['review_count'] ?? 0));
        $seller = trim((string) ($b['seller_name'] ?? '')) ?: 'IANOVA Direct';
        $emoji = trim((string) ($b['emoji'] ?? '')) ?: '📦';
        $bg = trim((string) ($b['bg_color'] ?? '')) ?: '#EFF3EC';
        $desc = trim((string) ($b['description'] ?? ''));
        $sub = substr(trim((string) ($b['subcategory'] ?? '')), 0, 60);
        $color = substr(trim((string) ($b['color'] ?? '')), 0, 40);
        $size = substr(trim((string) ($b['size'] ?? '')), 0, 30);
        $flash = !empty($b['is_flash_deal']) && (string) $b['is_flash_deal'] !== '0' ? 1 : 0;
        if ($name === '' || $slug === '' || $cat < 1) fail('Name and category are required.');
        if ($price <= 0) fail('Price must be above 0.');
        $image = $old ? ($old[$img] ?? null) : null;
        $up = apx_image_upload('image', 'uploads/products', 'p');
        if ($up) $image = $up;
        $slugBase = $slug;
        $slug = apx_unique_slug($pdo, $slug, $id, $color, $size);
        try {
            if ($id) {
                $pdo->prepare("UPDATE products SET category_id=?,name=?,slug=?,description=?,price=?,original_price=?,emoji=?,bg_color=?,rating=?,review_count=?,stock=?,is_flash_deal=?,seller_name=?,$img=?,subcategory=?,color=?,size=? WHERE id=?")
                    ->execute([$cat, $name, $slug, $desc, $price, $orig, $emoji, $bg, $rating, $reviews, $stock, $flash, $seller, $image, $sub, $color, $size, $id]);
                $pid = $id;
            } else {
                $pdo->prepare("INSERT INTO products(category_id,name,slug,description,price,original_price,emoji,bg_color,rating,review_count,stock,is_flash_deal,seller_name,$img,subcategory,color,size) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$cat, $name, $slug, $desc, $price, $orig, $emoji, $bg, $rating, $reviews, $stock, $flash, $seller, $image, $sub, $color, $size]);
                $pid = (int) $pdo->lastInsertId();
            }
            if ($id && !empty($b['del_img'])) gallery_delete($pdo, $pid, array_map('intval', explode(',', (string) $b['del_img'])));
            $gf = apx_gallery_files('gallery');
            if ($gf['name']) gallery_save_files($pdo, $pid, $gf);
            // More colors: opt_color_N, opt_stock_N, opt_price_N, opt_image_N, opt_more_N_M
            $ins = $pdo->prepare("INSERT INTO products(category_id,name,slug,description,price,original_price,emoji,bg_color,rating,review_count,stock,is_flash_deal,seller_name,$img,subcategory,color,size) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $dup = $pdo->prepare('SELECT id FROM products WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND LOWER(TRIM(color))=LOWER(TRIM(?)) AND LOWER(TRIM(size))=LOWER(TRIM(?))');
            $added = 0;
            for ($i = 0; $i < 20; $i++) {
                $oc = substr(trim((string) ($b['opt_color_' . $i] ?? '')), 0, 40);
                if ($oc === '') continue;
                if ($color === '') fail('Enter a Color for the main product before adding more colors.');
                $dup->execute([$cat, $name, $oc, $size]);
                if ($dup->fetch()) continue;
                $os = max(0, (int) ($b['opt_stock_' . $i] ?? 0));
                $opRaw = trim((string) ($b['opt_price_' . $i] ?? ''));
                $op = $opRaw === '' ? $price : (float) $opRaw;
                $oi = apx_image_upload('opt_image_' . $i, 'uploads/products', 'o');
                $oslug = apx_unique_slug($pdo, $slugBase, 0, $oc, $size);
                $ins->execute([$cat, $name, $oslug, $desc, $op, $orig, $emoji, $bg, $rating, $reviews, $os, $flash, $seller, $oi, $sub, $oc, $size]);
                $oid = (int) $pdo->lastInsertId();
                $mf = apx_gallery_files('opt_more_' . $i, 10);
                if ($mf['name']) gallery_save_files($pdo, $oid, $mf);
                $added++;
            }
        } catch (Throwable $e) {
            fail('Could not save the product: ' . $e->getMessage());
        }
        out(['success' => true, 'id' => $pid, 'added_colors' => $added]);

    case 'product_action':
        $id = (int) ($b['id'] ?? 0);
        $do = (string) ($b['do'] ?? '');
        if ($id < 1) fail('Invalid product.');
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'hide' => 'hidden', 'unhide' => 'approved'];
        if ($do === 'delete') {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            out(['success' => true]);
        }
        if (!isset($map[$do])) fail('Unknown action.');
        $old = ops_product_status($pdo, $id);
        $pdo->prepare('UPDATE products SET status = ? WHERE id = ?')->execute([$map[$do], $id]);
        ops_product_status_changed($pdo, $id, $old, $map[$do]);
        out(['success' => true]);

    // ============================== CATEGORIES ==============================
    case 'categories_list':
        out(['success' => true, 'categories' => apx_rows($pdo, 'SELECT c.id, c.name, c.slug, c.emoji, c.sort_order, COALESCE(c.is_hidden,0) is_hidden, COUNT(p.id) products FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id ORDER BY c.sort_order, c.name')]);

    case 'category_save':
        $id = (int) ($b['id'] ?? 0);
        $name = trim((string) ($b['name'] ?? ''));
        $slug = apx_slugify(trim((string) ($b['slug'] ?? '')) !== '' ? (string) $b['slug'] : $name);
        $emoji = trim((string) ($b['emoji'] ?? '')) ?: '🛍️';
        $sort = (int) ($b['sort_order'] ?? 0);
        if ($name === '' || $slug === '') fail('Name is required.');
        try {
            if ($id) $pdo->prepare('UPDATE categories SET name=?, slug=?, emoji=?, sort_order=? WHERE id=?')->execute([$name, $slug, $emoji, $sort, $id]);
            else $pdo->prepare('INSERT INTO categories(name, slug, emoji, sort_order) VALUES(?,?,?,?)')->execute([$name, $slug, $emoji, $sort]);
        } catch (Throwable $e) { fail('Could not save category. The slug must be unique.'); }
        out(['success' => true]);

    case 'category_toggle':
        $pdo->prepare('UPDATE categories SET is_hidden = 1 - COALESCE(is_hidden,0) WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true]);

    case 'category_delete':
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true]);

    // ============================== USERS ==============================
    case 'users_list':
        out(['success' => true, 'users' => apx_rows($pdo, 'SELECT u.id, u.name, u.email, u.created_at, COUNT(o.id) orders FROM users u LEFT JOIN orders o ON o.user_id = u.id GROUP BY u.id ORDER BY u.id DESC LIMIT 500')]);

    case 'user_save':
        $id = (int) ($b['id'] ?? 0);
        $name = trim((string) ($b['name'] ?? ''));
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if ($id < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a name and a valid email.');
        try { $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]); }
        catch (Throwable $e) { fail('That email is already used by another user.'); }
        out(['success' => true]);

    case 'user_delete':
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true]);

    // ============================== SELLERS ==============================
    case 'sellers_full':
        $rows = apx_rows($pdo, 'SELECT s.id, s.business_name, s.business_description, s.email, s.phone, s.status, s.created_at, COUNT(p.id) products,
            (SELECT COUNT(*) FROM seller_messages m WHERE m.seller_id = s.id AND m.sender = "seller" AND m.read_at IS NULL) unread
            FROM sellers s LEFT JOIN products p ON p.seller_id = s.id GROUP BY s.id ORDER BY FIELD(s.status, "pending", "approved", "suspended", "rejected"), s.created_at DESC');
        $sc = aou_seller_counts($pdo);
        foreach ($rows as &$r) {
            $r['auto'] = ops_seller_auto($pdo, (int) $r['id']);
            $r['orders'] = (int) ($sc[(int) $r['id']]['orders'] ?? 0);
            $r['waiting'] = (int) ($sc[(int) $r['id']]['waiting'] ?? 0);
        }
        unset($r);
        out(['success' => true, 'sellers' => $rows, 'master_auto' => ops_setting($pdo, 'auto_approve', '1') === '1']);

    case 'seller_action':
        $id = (int) ($b['id'] ?? 0);
        $do = (string) ($b['do'] ?? '');
        $cur = $pdo->prepare('SELECT business_name, email, status FROM sellers WHERE id = ?');
        $cur->execute([$id]);
        $seller = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$seller) fail('Seller not found.', 404);
        $note = 'Saved.';
        if ($do === 'auto_on' || $do === 'auto_off') {
            $on = $do === 'auto_on';
            ops_setting_set($pdo, 'auto_seller_' . $id, $on ? '1' : '0');
            try { $pdo->prepare('UPDATE sellers SET auto_approve = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]); } catch (Throwable $e) {}
            $note = $on ? 'Auto-approve is ON for this seller.' : 'Auto-approve is OFF for this seller.';
        } elseif (in_array($do, ['approved', 'rejected', 'suspended'], true)) {
            $pdo->prepare('UPDATE sellers SET status = ? WHERE id = ?')->execute([$do, $id]);
            ops_seller_status_changed($pdo, $id, (string) $seller['status'], $do);
            if (($do === 'approved' && $seller['status'] !== 'approved') || ($do === 'rejected' && $seller['status'] === 'pending')) {
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $sent = function_exists('send_seller_status_email') ? send_seller_status_email($seller['email'], $seller['business_name'], $do) : false;
                    $note = $sent ? 'Saved. Email sent to ' . $seller['email'] . '.' : 'Saved, but the email could not be sent.';
                } catch (Throwable $e) { $note = 'Saved, but the email could not be sent.'; }
            }
        } else {
            fail('Unknown action.');
        }
        out(['success' => true, 'note' => $note]);

    case 'auto_master':
        if (array_key_exists('on', $b)) ops_setting_set($pdo, 'auto_approve', !empty($b['on']) && (string) $b['on'] !== '0' ? '1' : '0');
        out(['success' => true, 'on' => ops_setting($pdo, 'auto_approve', '1') === '1']);

    // ============================== SUBSCRIBERS / SUGGESTIONS ==============================
    case 'subscribers_list':
        out(['success' => true, 'subscribers' => apx_rows($pdo, 'SELECT id, email, subscribed_at FROM newsletter_subscribers ORDER BY id DESC LIMIT 1000')]);

    case 'subscriber_delete':
        $pdo->prepare('DELETE FROM newsletter_subscribers WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true]);

    case 'suggestions_list':
        out(['success' => true, 'suggestions' => apx_rows($pdo, 'SELECT s.id, s.message, s.status, s.created_at, u.name, u.email FROM suggestions s JOIN users u ON u.id = s.user_id ORDER BY FIELD(s.status, "new", "reviewed"), s.created_at DESC LIMIT 300')]);

    case 'suggestion_reviewed':
        $pdo->prepare("UPDATE suggestions SET status = 'reviewed' WHERE id = ?")->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true]);

    // ============================== BANNERS ==============================
    case 'banners_list':
        $pdo->exec("CREATE TABLE IF NOT EXISTS banners (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL, subtitle VARCHAR(160) NOT NULL DEFAULT '', button_label VARCHAR(40) NOT NULL DEFAULT 'Shop now', image VARCHAR(255) NOT NULL DEFAULT '', link_type VARCHAR(20) NOT NULL DEFAULT 'none', link_value VARCHAR(255) NOT NULL DEFAULT '', color VARCHAR(20) NOT NULL DEFAULT 'blush', sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        out(['success' => true,
            'banners' => apx_rows($pdo, 'SELECT * FROM banners ORDER BY sort_order, id'),
            'products' => apx_rows($pdo, "SELECT id, name FROM products WHERE status = 'approved' ORDER BY name LIMIT 500"),
            'categories' => apx_rows($pdo, 'SELECT id, name FROM categories ORDER BY name')]);

    case 'banner_save':
        $id = (int) ($b['id'] ?? 0);
        $title = substr(trim((string) ($b['title'] ?? '')), 0, 120);
        if ($title === '') fail('Give the banner a title.');
        $type = in_array($b['link_type'] ?? '', ['none', 'product', 'category', 'search'], true) ? $b['link_type'] : 'none';
        $value = '';
        if ($type === 'product' || $type === 'category') $value = (string) (int) ($b['link_value'] ?? 0);
        if ($type === 'search') $value = substr(trim((string) ($b['link_value'] ?? '')), 0, 200);
        $color = in_array($b['color'] ?? '', ['blush', 'sky', 'sand', 'rose'], true) ? $b['color'] : 'blush';
        $sub = substr(trim((string) ($b['subtitle'] ?? '')), 0, 160);
        $label = substr(trim((string) ($b['button_label'] ?? '')), 0, 40) ?: 'Shop now';
        $order = (int) ($b['sort_order'] ?? 0);
        $active = !empty($b['is_active']) && (string) $b['is_active'] !== '0' ? 1 : 0;
        $image = apx_image_upload('image', 'uploads/banners', 'b');
        if ($id) {
            $pdo->prepare('UPDATE banners SET title=?, subtitle=?, button_label=?, link_type=?, link_value=?, color=?, sort_order=?, is_active=? WHERE id=?')->execute([$title, $sub, $label, $type, $value, $color, $order, $active, $id]);
            if ($image) $pdo->prepare('UPDATE banners SET image=? WHERE id=?')->execute([$image, $id]);
        } else {
            $pdo->prepare('INSERT INTO banners (title, subtitle, button_label, image, link_type, link_value, color, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$title, $sub, $label, $image ?: '', $type, $value, $color, $order, $active]);
        }
        out(['success' => true]);

    case 'banner_toggle':
        $pdo->prepare('UPDATE banners SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true]);

    case 'banner_delete':
        $pdo->prepare('DELETE FROM banners WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true]);

    // ============================== FLASH DEALS ==============================
    case 'flash_get':
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB");
        $pdo->prepare("INSERT IGNORE INTO settings(setting_key, setting_value) VALUES('flash_ends_at', '')")->execute();
        $endsRaw = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key='flash_ends_at'")->fetchColumn();
        $ts = $endsRaw !== '' ? strtotime($endsRaw) : false;
        $qtext = trim((string) ($_GET['q'] ?? ''));
        $rows = apx_rows($pdo, "SELECT id, name, color, size, price, original_price, stock, is_flash_deal, $img AS image, emoji, bg_color FROM products WHERE status = 'approved' AND name LIKE ? ORDER BY is_flash_deal DESC, name, id LIMIT 300", ['%' . $qtext . '%']);
        out(['success' => true, 'ends_at' => $ts ? date('Y-m-d H:i', $ts) : '', 'ended' => (bool) ($ts && $ts < time()), 'now' => date('Y-m-d H:i'), 'products' => $rows]);

    case 'flash_save':
        $raw = trim((string) ($b['ends_at'] ?? ''));
        $ts = $raw === '' ? false : strtotime(str_replace('T', ' ', $raw));
        apx_put_setting($pdo, 'flash_ends_at', $ts ? date('Y-m-d H:i:s', $ts) : '');
        $upd = $pdo->prepare('UPDATE products SET is_flash_deal = ?, price = ?, original_price = ? WHERE id = ?');
        $items = $b['items'] ?? [];
        if (is_string($items)) $items = json_decode($items, true) ?: [];
        foreach ((array) $items as $it) {
            $id = (int) ($it['id'] ?? 0);
            $price = (float) ($it['price'] ?? 0);
            $orig = (float) ($it['orig'] ?? 0);
            if ($id < 1 || $price <= 0) continue;
            $upd->execute([!empty($it['flash']) ? 1 : 0, $price, $orig > 0 ? $orig : $price, $id]);
        }
        out(['success' => true]);

    case 'flash_clear':
        $pdo->exec('UPDATE products SET is_flash_deal = 0');
        out(['success' => true]);

    // ============================== SITE CONTENT & SETTINGS ==============================
    case 'settings_get':
    case 'settings_set':
        $fields = [
            ['site_name', 'Site name', 'Store settings'], ['site_slogan', 'Site slogan', 'Store settings'], ['currency', 'Currency', 'Store settings'],
            ['free_delivery_threshold', 'Free delivery threshold', 'Store settings'],
            ['delivery_banner', 'Free delivery banner ({currency} and {threshold} are filled in)', 'Website text'], ['search_placeholder', 'Search placeholder', 'Website text'],
            ['flash_deals_title', 'Flash deals title', 'Website text'], ['categories_title', 'Categories title', 'Website text'], ['cart_title', 'Cart title', 'Website text'],
            ['delivery_title', 'Delivery details title', 'Website text'],
            ['trust_delivery_title', 'Delivery block title', 'Trust blocks'], ['trust_delivery_text', 'Delivery block text', 'Trust blocks'],
            ['trust_secure_title', 'Secure checkout title', 'Trust blocks'], ['trust_secure_text', 'Secure checkout text', 'Trust blocks'],
            ['trust_returns_title', 'Returns title', 'Trust blocks'], ['trust_returns_text', 'Returns text', 'Trust blocks'],
            ['trust_verified_title', 'Verified sellers title', 'Trust blocks'], ['trust_verified_text', 'Verified sellers text', 'Trust blocks'],
            ['newsletter_title', 'Newsletter title', 'Newsletter'], ['newsletter_text', 'Newsletter text', 'Newsletter'], ['newsletter_placeholder', 'Newsletter placeholder', 'Newsletter'],
            ['newsletter_button', 'Newsletter button', 'Newsletter'],
            ['delivery_message', 'Delivery message', 'Messages'], ['returns_message', 'Returns message', 'Messages'], ['footer_message', 'Footer message', 'Messages'],
        ];
        $keys = array_column($fields, 0);
        if ($action === 'settings_set') {
            $vals = $b['values'] ?? [];
            if (is_string($vals)) $vals = json_decode($vals, true) ?: [];
            foreach ((array) $vals as $k => $v) {
                if (!in_array($k, $keys, true)) continue;
                apx_put_setting($pdo, (string) $k, substr(trim((string) $v), 0, 500));
            }
            out(['success' => true]);
        }
        $cur = [];
        foreach (apx_rows($pdo, 'SELECT setting_key, setting_value FROM settings') as $r) $cur[$r['setting_key']] = (string) $r['setting_value'];
        $defaults = ['site_name' => SITE_NAME, 'site_slogan' => SITE_SLOGAN, 'currency' => CURRENCY, 'free_delivery_threshold' => (string) FREE_DELIVERY_THRESHOLD];
        $out = [];
        foreach ($fields as $f) $out[] = ['key' => $f[0], 'label' => $f[1], 'group' => $f[2], 'value' => $cur[$f[0]] ?? ($defaults[$f[0]] ?? '')];
        out(['success' => true, 'fields' => $out]);

    default:
        fail('Unknown action.', 404);
}
