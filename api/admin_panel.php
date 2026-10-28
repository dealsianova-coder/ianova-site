<?php
/**
 * IANOVA Admin app: everything the website admin can do, as JSON.
 * Separate from api/admin.php on purpose (that file keeps login, store settings, alerts, seller chat).
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';
require_once __DIR__ . '/../includes/seller_ops.php';
require_once __DIR__ . '/../includes/gallery.php';
require_once __DIR__ . '/../includes/display.php';
require_once __DIR__ . '/../includes/category_images.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/analytics.php';
require_once __DIR__ . '/../includes/admin_team.php';
require_once __DIR__ . '/../includes/order_ops.php';
require_once __DIR__ . '/../includes/coupons.php';
require_once __DIR__ . '/../includes/payouts.php';
require_once __DIR__ . '/../includes/payments.php';
team_ensure($pdo);
oo_ensure($pdo);
date_default_timezone_set('Africa/Nairobi');

// ---- admin session (same tokens as api/admin.php) -------------------------------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_tokens (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$token = bearer();
if ($token === '') fail('Please sign in as admin.', 401);
$st0 = $pdo->prepare('SELECT a.id FROM admin_tokens t JOIN admins a ON a.id = t.admin_id WHERE t.token_hash = ? AND t.expires_at > NOW() AND a.status = \'active\' LIMIT 1');
$st0->execute([hash('sha256', $token)]);
$meAdmin = $st0->fetch(PDO::FETCH_ASSOC);
if ($meAdmin && !team_token_active($pdo, hash('sha256', $token))) fail('You were signed out after being inactive. Please sign in again.', 401);
if ($meAdmin) $GLOBALS['ORDER_ACTOR_ID'] = (int) $meAdmin['id'];
if (!$meAdmin) fail('Admin session expired. Please sign in again.', 401);

$b = body();
if (!$b) $b = $_POST;
$action = (string) ($_GET['action'] ?? $b['action'] ?? '');
ops_ensure($pdo);
gallery_ensure($pdo);
display_ensure($pdo);
cat_img_ensure($pdo);

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

// What this admin may see (set by the master admin). The master and legacy admins see everything.
$__sec = team_section_of_action('panel', (string) $action);
if ($__sec !== null && !team_can($pdo, (int) $meAdmin['id'], $__sec)) fail('You do not have access to this section. Ask the master admin.', 403);

if (preg_match('/(_save|_delete|_action|_toggle|_status|_set|_send|_import|_flush|_clear|_add|_do|_perms|_reviewed|_read)$|^(auto_master|bulk_import|mail_test|mail_newsletter|order_note_add|order_item_cancel|payout_rate)$/', (string) $action) && !in_array($action, ['alerts_tab_read', 'team_do', 'team_add', 'team_perms', 'team_set_security', 'admin_account_save', 'team_idle_set', 'order_tracking_save', 'order_refund_add', 'order_item_cancel'], true)) {
    $__d = []; foreach (['id', 'status', 'do', 'name', 'key'] as $__k) if (isset($b[$__k]) && is_scalar($b[$__k])) $__d[] = $__k . '=' . substr((string) $b[$__k], 0, 40);
    team_log($pdo, (int) $meAdmin['id'], (string) $action, implode(' ', $__d));
}

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
        require_once __DIR__ . '/../includes/attention.php';
        $counts += attn_counts($pdo);
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
        $nc = ['success' => true,
            'alerts' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL'),
            'messages' => (int) apx_val($pdo, "SELECT COUNT(*) FROM seller_messages WHERE sender = 'seller' AND read_at IS NULL AND body NOT LIKE 'Review request:%'"),
            'suggestions' => (int) apx_val($pdo, "SELECT COUNT(*) FROM suggestions WHERE status = 'new'"),
            'pending_orders' => (int) apx_val($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'"),
            'review_products' => (int) apx_val($pdo, "SELECT COUNT(*) FROM products WHERE status = 'pending'"),
            'pending_sellers' => (int) apx_val($pdo, "SELECT COUNT(*) FROM sellers WHERE status = 'pending'"),
            'master' => team_is_master($pdo, (int) $meAdmin['id']),
            'allowed' => team_allowed_for($pdo, (int) $meAdmin['id']),
        ];
        $__al = team_allowed_for($pdo, (int) $meAdmin['id']);
        foreach (['alerts' => 'alerts', 'messages' => 'sellers', 'suggestions' => 'suggestions', 'pending_orders' => 'orders', 'review_products' => 'products', 'pending_sellers' => 'sellers'] as $__k => $__sec) {
            if (!in_array($__sec, $__al, true)) $nc[$__k] = 0;
        }
        out($nc);

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
        $lim = max(20, min(600, (int) ($_GET['limit'] ?? 200)));
        $q = $pdo->prepare($sql . ' ORDER BY o.id DESC LIMIT ' . ($lim + 1));
        $q->execute($par);
        $orders = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($orders) > $lim;
        if ($more) $orders = array_slice($orders, 0, $lim);
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
            'status_counts' => $byStatus, 'seller_chips' => $chips, 'orders' => $list, 'more' => $more]);

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
        $ist = oo_item_statuses($pdo, $id);
        foreach ($lines as $l) {
            $sid = (int) ($l['seller_id'] ?? 0);
            if (!isset($groups[$sid])) $groups[$sid] = ['seller_id' => $sid, 'name' => $sid === 0 ? 'IANOVA (main)' : (string) $l['seller_name'], 'is_main' => $sid === 0, 'lines' => [], 'approved' => $sid === 0 ? null : (isset($appr[$sid]) ? (bool) $appr[$sid]['at'] : false)];
            $gl = apx_line($l); $gl['item_status'] = $ist[(int) ($l['product_id'] ?? 0)] ?? '';
            $groups[$sid]['lines'][] = $gl;
        }
        ksort($groups);
        $out = [];
        foreach ($groups as $g) { $g['total'] = aou_lines_total($g['lines']); $out[] = $g; }
        out(oo_extras($pdo, $id) + ['timeline' => oo_timeline($pdo, $id)] + ['pay' => (pay_order_fields($pdo, $id) + ['list' => pay_list($pdo, $id)])] + ['success' => true, 'order' => ['id' => (int) $o['id'], 'status' => $o['status'], 'created_at' => $o['created_at'], 'customer' => $o['customer'], 'email' => (string) $o['email'],
            'phone' => (string) ($o['guest_phone'] ?? ''), 'address' => (string) ($o['address'] ?? ''), 'total' => (float) $o['total']],
            'groups' => $out, 'subtotal' => $sub, 'delivery' => round((float) $o['total'] - $sub, 2),
            'notes' => apx_rows($pdo, 'SELECT id, admin_name, note, created_at FROM order_notes WHERE order_id = ? ORDER BY id DESC', [$id])]);

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
        $lim = max(20, min(600, (int) ($_GET['limit'] ?? 300)));
        $sql .= " ORDER BY (p.status = 'pending') DESC, p.id DESC LIMIT " . ($lim + 1);
        $rows = apx_rows($pdo, $sql, $par);
        $more = count($rows) > $lim;
        if ($more) $rows = array_slice($rows, 0, $lim);
        $counts = ['all' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM products')];
        foreach (['pending', 'approved', 'rejected', 'hidden'] as $s1) $counts[$s1] = (int) apx_val($pdo, 'SELECT COUNT(*) FROM products WHERE status = ?', [$s1]);
        out(['success' => true, 'products' => $rows, 'counts' => $counts, 'more' => $more]);

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
            'show_on' => display_clean($p['show_on'] ?? 'both'), 'rating' => (float) ($p['rating'] ?? 0), 'review_count' => (int) ($p['review_count'] ?? 0), 'emoji' => (string) ($p['emoji'] ?? ''), 'bg_color' => (string) ($p['bg_color'] ?? ''),
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
        try {
            $g = $pdo->prepare('SELECT category_id, COALESCE(seller_id,0) AS sid, name FROM products WHERE id = ?');
            $g->execute([$pid]);
            $gr = $g->fetch(PDO::FETCH_ASSOC);
            if ($gr) $pdo->prepare('UPDATE products SET show_on = ? WHERE category_id = ? AND COALESCE(seller_id,0) = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))')->execute([display_clean($b['show_on'] ?? 'both'), $gr['category_id'], $gr['sid'], $gr['name']]);
        } catch (Throwable $e) {}
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
        out(['success' => true, 'categories' => apx_rows($pdo, "SELECT c.id, c.name, c.slug, c.emoji, COALESCE(c.image,'') image, c.sort_order, COALESCE(c.is_hidden,0) is_hidden, COALESCE(c.show_on,'both') show_on, COUNT(p.id) products FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id ORDER BY c.sort_order, c.name")]);

    case 'category_save':
        $id = (int) ($b['id'] ?? 0);
        $name = trim((string) ($b['name'] ?? ''));
        $slug = apx_slugify(trim((string) ($b['slug'] ?? '')) !== '' ? (string) $b['slug'] : $name);
        $emoji = trim((string) ($b['emoji'] ?? '')) ?: '🛍️';
        $sort = (int) ($b['sort_order'] ?? 0);
        $showOn = display_clean($b['show_on'] ?? 'both');
        if ($name === '' || $slug === '') fail('Name is required.');
        $oldImg = null;
        if ($id) { $q = $pdo->prepare('SELECT image FROM categories WHERE id = ?'); $q->execute([$id]); $oldImg = $q->fetchColumn() ?: null; }
        $imgPath = $oldImg;
        if (!empty($b['remove_image']) && (string) $b['remove_image'] !== '0') { cat_img_delete($oldImg); $imgPath = null; }
        $up = isset($_FILES['image']) ? cat_img_save($_FILES['image']) : null;
        if ($up) { if ($oldImg) cat_img_delete($oldImg); $imgPath = $up; }
        try {
            if ($id) $pdo->prepare('UPDATE categories SET name=?, slug=?, emoji=?, image=?, sort_order=?, show_on=? WHERE id=?')->execute([$name, $slug, $emoji, $imgPath, $sort, $showOn, $id]);
            else $pdo->prepare('INSERT INTO categories(name, slug, emoji, image, sort_order, show_on) VALUES(?,?,?,?,?,?)')->execute([$name, $slug, $emoji, $imgPath, $sort, $showOn]);
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
        $uq = trim((string) ($_GET['q'] ?? ''));
        $ulim = max(20, min(1000, (int) ($_GET['limit'] ?? 500)));
        $uw = ''; $up = []; $sw = ''; $sp = [];
        if ($uq !== '') { $lk = '%' . $uq . '%'; $uw = ' WHERE (u.name LIKE ? OR u.email LIKE ?)'; $up = [$lk, $lk]; $sw = ' WHERE (s.business_name LIKE ? OR s.email LIKE ?)'; $sp = [$lk, $lk]; }
        $ur = apx_rows($pdo, 'SELECT u.id, u.name, u.email, u.created_at, COUNT(o.id) orders FROM users u LEFT JOIN orders o ON o.user_id = u.id' . $uw . ' GROUP BY u.id ORDER BY u.id DESC LIMIT ' . ($ulim + 1), $up);
        $umore = count($ur) > $ulim;
        if ($umore) $ur = array_slice($ur, 0, $ulim);
        out(['success' => true,
            'me' => (int) $meAdmin['id'],
            'sellers' => apx_rows($pdo, 'SELECT s.id, s.business_name, s.email, s.status, s.created_at, COUNT(p.id) products FROM sellers s LEFT JOIN products p ON p.seller_id = s.id' . $sw . ' GROUP BY s.id ORDER BY s.id DESC LIMIT 500', $sp),
            'users' => $ur, 'more' => $umore]);

    // ============================== ADMIN TEAM (separate from Users) ==============================
    case 'team_get':
        $meId = (int) $meAdmin['id'];
        $isM = team_is_master($pdo, $meId);
        $rows = team_list($pdo);
        if (!$isM) $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $meId || (int) $r['is_master'] === 1));
        $secs = []; foreach (team_sections() as $k => $d) $secs[] = ['key' => $k, 'label' => $d[0]];
        out(['success' => true, 'me' => $meId, 'master' => $isM, 'has_security' => team_has_security($pdo, $meId), 'idle_min' => team_idle_minutes($pdo), 'idle_choices' => array_map(fn($k, $v) => ['min' => $k, 'label' => $v], array_keys(team_idle_choices()), array_values(team_idle_choices())), 'admins' => $rows, 'sections' => $secs]);

    case 'team_sec_reset_request':
        $m = team_sec_reset_request($pdo, (int) $meAdmin['id']);
        if ($m !== '') fail($m, 429);
        out(['success' => true]);

    case 'activity_list':
        if (!team_is_master($pdo, (int) $meAdmin['id'])) fail('Only the master admin can see the activity log.', 403);
        out(['success' => true, 'rows' => team_log_list($pdo, (int) ($_GET['limit'] ?? 200), (int) ($_GET['admin'] ?? 0))]);

    case 'order_notes':
        $oid = (int) ($_GET['id'] ?? $b['id'] ?? 0);
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        out(['success' => true, 'notes' => apx_rows($pdo, 'SELECT id, admin_name, note, created_at FROM order_notes WHERE order_id = ? ORDER BY id DESC', [$oid])]);

    case 'order_tracking_save':
        $m = oo_tracking_save($pdo, (int) $meAdmin['id'], (int) ($b['id'] ?? 0), (string) ($b['courier'] ?? ''), (string) ($b['number'] ?? ''), (string) ($b['note'] ?? ''));
        if ($m !== '') fail($m, 409);
        out(['success' => true]);

    case 'order_refund_add':
        $m = oo_refund_add($pdo, (int) $meAdmin['id'], (int) ($b['id'] ?? 0), (float) ($b['amount'] ?? 0), (string) ($b['reason'] ?? ''), (string) ($b['method'] ?? ''));
        if ($m !== '') fail($m, 409);
        out(['success' => true]);

    case 'order_item_cancel':
        $m = oo_item_cancel($pdo, (int) $meAdmin['id'], (int) ($b['id'] ?? 0), (int) ($b['product_id'] ?? 0));
        if ($m !== '') fail($m, 409);
        out(['success' => true]);

    case 'order_item_status':
        $m = oo_item_status($pdo, (int) $meAdmin['id'], (int) ($b['id'] ?? 0), (int) ($b['product_id'] ?? 0), (string) ($b['status'] ?? ''));
        if ($m !== '') fail($m, 409);
        out(['success' => true]);

    case 'orders_bulk_status':
        $ids = is_array($b['ids'] ?? null) ? $b['ids'] : [];
        if (!in_array((string) ($b['status'] ?? ''), ['approved', 'shipped', 'delivered'], true)) fail('Choose approved, shipped or delivered.');
        if (!$ids) fail('Select at least one order.');
        [$okN, $skipN] = oo_bulk_status($pdo, (int) $meAdmin['id'], $ids, (string) $b['status']);
        out(['success' => true, 'updated' => $okN, 'skipped' => $skipN]);

    case 'order_note_add':
        $oid = (int) ($b['id'] ?? 0);
        $note = trim((string) ($b['note'] ?? ''));
        if ($oid < 1 || $note === '') fail('Write a note first.');
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        $pdo->prepare('INSERT INTO order_notes (order_id, admin_id, admin_name, note) SELECT ?, id, name, ? FROM admins WHERE id = ?')->execute([$oid, mb_substr($note, 0, 1000), (int) $meAdmin['id']]);
        out(['success' => true]);

    case 'team_idle_set':
        $m = team_idle_set($pdo, (int) $meAdmin['id'], (int) ($b['minutes'] ?? -1), (string) ($b['security_password'] ?? ''));
        if ($m !== '') fail($m, 403);
        out(['success' => true]);

    case 'team_perms':
        $perms = $b['perms'] ?? [];
        $m = team_set_perms($pdo, (int) $meAdmin['id'], (int) ($b['id'] ?? 0), is_array($perms) ? $perms : [], (string) ($b['security_password'] ?? ''));
        if ($m !== '') fail($m, 403);
        out(['success' => true]);

    case 'admin_account_save':
        // Editing your own admin account needs the security password (separate from the sign-in password).
        if ((int) ($b['id'] ?? 0) !== (int) $meAdmin['id']) fail('The admin account is protected. You can only edit your own admin account.', 403);
        $m = team_edit_self($pdo, (int) $meAdmin['id'], (string) ($b['name'] ?? ''), (string) ($b['email'] ?? ''), (string) ($b['new_password'] ?? ''), (string) ($b['security_password'] ?? ''), hash('sha256', $token));
        if ($m !== '') fail($m, 403);
        out(['success' => true]);

    case 'team_set_security':
        $m = team_set_security($pdo, (int) $meAdmin['id'], (string) ($b['proof'] ?? ''), (string) ($b['new'] ?? ''));
        if ($m !== '') fail($m, 403);
        out(['success' => true]);

    case 'team_add':
        $m = team_add($pdo, (int) $meAdmin['id'], (string) ($b['name'] ?? ''), (string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''), (string) ($b['security_password'] ?? ''), is_array($b['perms'] ?? null) ? $b['perms'] : []);
        if ($m !== '') fail($m, 403);
        out(['success' => true]);

    case 'team_do':
        $m = team_do($pdo, (int) $meAdmin['id'], (int) ($b['id'] ?? 0), (string) ($b['do'] ?? ''), (string) ($b['security_password'] ?? ''));
        if ($m !== '') fail($m, 403);
        out(['success' => true]);

    case 'user_save':
        $id = (int) ($b['id'] ?? 0);
        $name = trim((string) ($b['name'] ?? ''));
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if ($id < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a name and a valid email.');
        try { $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]); }
        catch (Throwable $e) { fail('That email is already used by another user.'); }
        out(['success' => true]);

    case 'user_delete':
        require_once __DIR__ . '/../includes/user_remove.php';
        $r = user_remove($pdo, (int) ($b['id'] ?? 0));
        if ($r['mode'] === 'missing') fail($r['message'], 404);
        out(['success' => true, 'mode' => $r['mode'], 'message' => $r['message']]);

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
            $pdo->prepare('UPDATE banners SET title=?, subtitle=?, button_label=?, link_type=?, link_value=?, color=?, sort_order=?, is_active=?, show_on=? WHERE id=?')->execute([$title, $sub, $label, $type, $value, $color, $order, $active, display_clean($b['show_on'] ?? 'both'), $id]);
            if ($image) $pdo->prepare('UPDATE banners SET image=? WHERE id=?')->execute([$image, $id]);
        } else {
            $pdo->prepare('INSERT INTO banners (title, subtitle, button_label, image, link_type, link_value, color, sort_order, is_active, show_on) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$title, $sub, $label, $image ?: '', $type, $value, $color, $order, $active, display_clean($b['show_on'] ?? 'both')]);
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


    // ============================== DISPLAY CONTROL ==============================
    case 'display_get':
        $tab = (string) ($_GET['tab'] ?? 'sections');
        $cur = [];
        foreach (apx_rows($pdo, 'SELECT setting_key, setting_value FROM settings') as $r) $cur[$r['setting_key']] = (string) $r['setting_value'];
        $defs = [
            ['Flash deals', 'The countdown and deal products on the home page.', 'show_flash', 'disp_flash', '1', true],
            ['Announcement bar', 'The message across the top (write it in Store control).', 'announcement_enabled', 'disp_announcement', '0', true],
            ['Video ad', 'The video pop-up (set the video in Video ad).', 'video_ad_enabled', 'disp_video_ad', '0', true],
            ['Sale shortcut', 'App home screen only.', 'show_sale', '', '1', false],
            ['Category shortcuts', 'App home screen only.', 'show_categories', '', '1', false],
            ['Recently viewed', 'App home screen only.', 'show_recent', '', '1', false],
            ['Popular', 'App home screen only.', 'show_popular', '', '1', false],
        ];
        $res = ['success' => true, 'tab' => $tab];
        if ($tab === 'sections') {
            $secs = [];
            foreach ($defs as $d) {
                $shown = trim($cur[$d[2]] ?? $d[4]) !== '0';
                if ($d[5]) $val = $shown ? display_clean($cur[$d[3]] ?? 'both') : 'off';
                else $val = $shown ? 'on' : 'off';
                $secs[] = ['key' => $d[2], 'label' => $d[0], 'note' => $d[1], 'both' => $d[5], 'value' => $val];
            }
            $res['sections'] = $secs;
        } elseif ($tab === 'banners') {
            $res['rows'] = apx_rows($pdo, "SELECT id, title, subtitle, is_active, COALESCE(show_on,'both') show_on FROM banners ORDER BY sort_order, id");
        } elseif ($tab === 'categories') {
            $res['rows'] = apx_rows($pdo, "SELECT id, name, emoji, COALESCE(image,'') image, is_hidden, COALESCE(show_on,'both') show_on FROM categories ORDER BY sort_order, name");
        } else {
            $qtext = trim((string) ($_GET['q'] ?? ''));
            $v = in_array($_GET['v'] ?? '', ['both', 'web', 'app'], true) ? $_GET['v'] : '';
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $per = 40;
            $where = "p.status = 'approved' AND p.id IN (SELECT MIN(g.id) FROM products g WHERE g.status = 'approved' GROUP BY g.category_id, COALESCE(g.seller_id, 0), LOWER(TRIM(g.name)))";
            $args = [];
            if ($qtext !== '') { $where .= ' AND p.name LIKE ?'; $args[] = '%' . $qtext . '%'; }
            if ($v !== '') { $where .= " AND COALESCE(p.show_on, 'both') = ?"; $args[] = $v; }
            $total = (int) apx_val($pdo, "SELECT COUNT(*) FROM products p WHERE $where", $args);
            $pages = max(1, (int) ceil($total / $per));
            $page = min($page, $pages);
            $off = ($page - 1) * $per;
            $res['rows'] = apx_rows($pdo, "SELECT p.id, p.name, p.price, p.seller_name, COALESCE(p.show_on,'both') show_on,
                (SELECT COUNT(DISTINCT COALESCE(g2.show_on,'both')) FROM products g2 WHERE g2.category_id = p.category_id AND COALESCE(g2.seller_id,0) = COALESCE(p.seller_id,0) AND LOWER(TRIM(g2.name)) = LOWER(TRIM(p.name))) kinds
                FROM products p WHERE $where ORDER BY p.id DESC LIMIT $per OFFSET $off", $args);
            $res['total'] = $total; $res['pages'] = $pages; $res['page'] = $page;
        }
        out($res);

    case 'display_save':
        $tab = (string) ($b['tab'] ?? 'sections');
        $vals = $b['values'] ?? [];
        if (is_string($vals)) $vals = json_decode($vals, true) ?: [];
        if ($tab === 'sections') {
            $secKeys = ['show_flash' => 'disp_flash', 'announcement_enabled' => 'disp_announcement', 'video_ad_enabled' => 'disp_video_ad', 'show_sale' => '', 'show_categories' => '', 'show_recent' => '', 'show_popular' => ''];
            foreach ($secKeys as $key => $disp) {
                $v = (string) ($vals[$key] ?? '');
                if ($v === 'off') apx_put_setting($pdo, $key, '0');
                elseif ($v === 'on' || in_array($v, ['both', 'web', 'app'], true)) {
                    apx_put_setting($pdo, $key, '1');
                    if ($disp !== '' && $v !== 'on') apx_put_setting($pdo, $disp, $v);
                }
            }
        } elseif ($tab === 'banners' || $tab === 'categories') {
            $table = $tab === 'banners' ? 'banners' : 'categories';
            $upd = $pdo->prepare("UPDATE `$table` SET show_on = ? WHERE id = ?");
            foreach ((array) $vals as $id => $v) if (in_array($v, ['both', 'web', 'app'], true)) $upd->execute([$v, (int) $id]);
        } else {
            $prev = $b['prev'] ?? [];
            if (is_string($prev)) $prev = json_decode($prev, true) ?: [];
            $get = $pdo->prepare('SELECT category_id, COALESCE(seller_id,0) AS sid, name FROM products WHERE id = ?');
            $upd = $pdo->prepare('UPDATE products SET show_on = ? WHERE category_id = ? AND COALESCE(seller_id,0) = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))');
            foreach ((array) $vals as $id => $v) {
                $id = (int) $id;
                if (!in_array($v, ['both', 'web', 'app'], true)) continue;
                if ((string) (((array) $prev)[$id] ?? '') === $v) continue;
                $get->execute([$id]);
                $row = $get->fetch(PDO::FETCH_ASSOC);
                if ($row) $upd->execute([$v, (int) $row['category_id'], (int) $row['sid'], $row['name']]);
            }
        }
        out(['success' => true]);

    // ============================== VIDEO AD ==============================
    case 'video_ad_get':
    case 'video_ad_save':
        $keys = ['video_ad_enabled' => '0', 'video_ad_src' => '', 'video_ad_interval_minutes' => '3', 'video_ad_min_seconds' => '30', 'video_ad_link_type' => 'none', 'video_ad_link_value' => ''];
        foreach ($keys as $k => $d) $pdo->prepare('INSERT IGNORE INTO settings(setting_key, setting_value) VALUES(?, ?)')->execute([$k, $d]);
        if ($action === 'video_ad_save') {
            apx_put_setting($pdo, 'video_ad_enabled', !empty($b['enabled']) && (string) $b['enabled'] !== '0' ? '1' : '0');
            apx_put_setting($pdo, 'video_ad_interval_minutes', (string) max(1, (int) ($b['interval'] ?? 3)));
            apx_put_setting($pdo, 'video_ad_min_seconds', (string) max(5, (int) ($b['min_seconds'] ?? 30)));
            $lt = in_array($b['link_type'] ?? '', ['none', 'product', 'category', 'url'], true) ? $b['link_type'] : 'none';
            $lv = '';
            if ($lt === 'product' || $lt === 'category') $lv = (string) (int) ($b['link_value'] ?? 0);
            if ($lt === 'url') $lv = substr(trim((string) ($b['link_value'] ?? '')), 0, 255);
            apx_put_setting($pdo, 'video_ad_link_type', $lt);
            apx_put_setting($pdo, 'video_ad_link_value', $lv);
            $saved = null;
            if (!empty($_FILES['video']['name']) && ($_FILES['video']['error'] ?? 1) === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['mp4', 'webm', 'mov'], true)) fail('Video must be MP4, WebM or MOV.');
                if ($_FILES['video']['size'] > 40 * 1024 * 1024) fail('Video must be 40MB or smaller.');
                $dir = __DIR__ . '/../uploads/ads';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $name = bin2hex(random_bytes(10)) . '.' . $ext;
                if (move_uploaded_file($_FILES['video']['tmp_name'], "$dir/$name")) $saved = "uploads/ads/$name";
                else fail('Could not save the video.');
            }
            if ($saved) apx_put_setting($pdo, 'video_ad_src', $saved);
            elseif (!empty($b['clear_video']) && (string) $b['clear_video'] !== '0') apx_put_setting($pdo, 'video_ad_src', '');
            out(['success' => true]);
        }
        $cur = [];
        foreach (apx_rows($pdo, 'SELECT setting_key, setting_value FROM settings') as $r) $cur[$r['setting_key']] = (string) $r['setting_value'];
        out(['success' => true, 'settings' => $cur,
            'products' => apx_rows($pdo, "SELECT id, name FROM products WHERE status = 'approved' ORDER BY name LIMIT 500"),
            'categories' => apx_rows($pdo, 'SELECT id, name FROM categories ORDER BY name')]);

    // ============================== ALERTS (IANOVA / Sellers) ==============================
    case 'alerts_tab':
        $tab = (($_GET['tab'] ?? '') === 'sellers') ? 'sellers' : 'main';
        $scope = $tab === 'sellers' ? 'n.seller_id IS NOT NULL' : 'n.seller_id IS NULL';
        out(['success' => true, 'tab' => $tab,
            'unread_main' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL AND seller_id IS NULL'),
            'unread_sellers' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL AND seller_id IS NOT NULL'),
            'auto' => ops_setting($pdo, 'auto_approve', '1') === '1',
            'alerts' => apx_rows($pdo, "SELECT n.id, n.kind, n.title, n.body, n.link, n.seller_id, n.created_at, n.read_at, COALESCE(s.business_name,'') seller_name FROM admin_notifications n LEFT JOIN sellers s ON s.id = n.seller_id WHERE $scope ORDER BY n.id DESC LIMIT 100")]);

    case 'alerts_tab_read':
        $tab = (($b['tab'] ?? '') === 'sellers') ? 'sellers' : 'main';
        $scope = $tab === 'sellers' ? 'seller_id IS NOT NULL' : 'seller_id IS NULL';
        if (!empty($b['id'])) $pdo->prepare('UPDATE admin_notifications SET read_at = NOW() WHERE id = ?')->execute([(int) $b['id']]);
        else $pdo->exec("UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL AND $scope");
        out(['success' => true]);

    // ============================== BULK PRODUCT UPLOAD ==============================
    case 'bulk_import':
        $catId = (int) ($b['category_id'] ?? 0);
        $csv = (string) ($b['csv'] ?? '');
        if ($catId < 1) fail('Please choose a category.');
        if (trim($csv) === '') fail('Paste your CSV rows first.');
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $header = array_map(function ($x) { return strtolower(trim((string) $x)); }, fgetcsv($h, 0, ',', '"', '\\') ?: []);
        $missing = array_diff(['name', 'slug', 'price'], $header);
        if ($missing) fail('The first line must be the column names. Missing: ' . implode(', ', $missing) . '. Required: name, slug, price. Optional: description, original_price, stock, emoji, bg_color, is_flash_deal, image_url.');
        $col = array_flip($header);
        $okCount = 0; $errs = []; $line = 1;
        $insert = $pdo->prepare("INSERT INTO products (category_id,name,slug,description,price,original_price,emoji,bg_color,stock,is_flash_deal,$img,seller_name,rating,review_count,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'IANOVA Direct',4.5,0,'approved')");
        while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            $line++;
            if (implode('', array_map('trim', $row)) === '') continue;
            $g = function (string $k) use ($row, $col): string { return isset($col[$k]) ? trim((string) ($row[$col[$k]] ?? '')) : ''; };
            $name = $g('name'); $slug = $g('slug'); $price = (float) $g('price');
            if ($name === '' || $slug === '' || $price <= 0) { $errs[] = "Row $line: name, slug and a price above 0 are required."; continue; }
            $orig = $g('original_price') !== '' ? (float) $g('original_price') : null;
            $stock = $g('stock') !== '' ? (int) $g('stock') : 100;
            $emoji = $g('emoji') !== '' ? $g('emoji') : '📦';
            $bg = $g('bg_color') !== '' ? $g('bg_color') : '#EFF3EC';
            $flash = in_array(strtolower($g('is_flash_deal')), ['1', 'yes', 'true'], true) ? 1 : 0;
            $image = null;
            $url = $g('image_url');
            if ($url !== '') {
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                $okExt = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];
                if (isset($okExt[$ext]) && preg_match('#^https?://#i', $url)) {
                    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10], 'https' => ['timeout' => 10]]));
                    if ($data !== false && strlen($data) <= 5 * 1024 * 1024) {
                        $fn = 'uploads/products/' . bin2hex(random_bytes(10)) . '.' . $okExt[$ext];
                        if (@file_put_contents(__DIR__ . '/../' . $fn, $data) !== false) $image = $fn;
                    }
                }
            }
            try {
                $q = $pdo->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
                $q->execute([$slug]);
                $ex = $q->fetch(PDO::FETCH_ASSOC);
                if ($ex) {
                    $pdo->prepare("UPDATE products SET category_id=?,name=?,description=?,price=?,original_price=?,emoji=?,bg_color=?,stock=?,is_flash_deal=?,$img=COALESCE(NULLIF(?,''),$img),status='approved' WHERE id=?")
                        ->execute([$catId, $name, $g('description'), $price, $orig, $emoji, $bg, $stock, $flash, $image ?? '', (int) $ex['id']]);
                } else {
                    $insert->execute([$catId, $name, $slug, $g('description'), $price, $orig, $emoji, $bg, $stock, $flash, $image]);
                }
                $okCount++;
            } catch (Throwable $e) { $errs[] = "Row $line: could not save '$name'."; }
        }
        fclose($h);
        out(['success' => true, 'saved' => $okCount, 'errors' => $errs]);

    // ============================== COUPONS ==============================
    case 'coupons_list':
        out(['success' => true, 'coupons' => cp_list($pdo)]);

    case 'coupon_save':
        $m = cp_save($pdo, (int) ($b['id'] ?? 0), $b);
        if ($m !== '') fail($m, 422);
        out(['success' => true, 'coupons' => cp_list($pdo)]);

    case 'coupon_toggle':
        $pdo->prepare('UPDATE coupons SET active = 1 - active WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true, 'coupons' => cp_list($pdo)]);

    case 'coupon_delete':
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        out(['success' => true, 'coupons' => cp_list($pdo)]);

    // ============================== SELLER PAYOUTS ==============================
    case 'payouts_list':
        $rows = po_overview($pdo);
        $owed = 0.0; $paid = 0.0; foreach ($rows as $r) { $owed += max(0, $r['balance']); $paid += $r['paid']; }
        out(['success' => true, 'default_rate' => po_default_rate($pdo), 'owed' => round($owed, 2), 'paid' => round($paid, 2), 'sellers' => $rows]);

    case 'payout_history':
        out(['success' => true, 'payouts' => po_history($pdo, (int) ($b['seller_id'] ?? $_GET['seller_id'] ?? 0), 60)]);

    case 'payout_add':
        $m = po_add($pdo, (int) $meAdmin['id'], (int) ($b['seller_id'] ?? 0), (float) ($b['amount'] ?? 0), (string) ($b['method'] ?? ''), (string) ($b['reference'] ?? ''), (string) ($b['note'] ?? ''));
        if ($m !== '') fail($m, 422);
        out(['success' => true]);

    case 'payout_rate':
        $raw = $b['rate'] ?? null;
        $m = po_set_rate($pdo, (int) ($b['seller_id'] ?? 0), ($raw === null || $raw === '') ? null : $raw);
        if ($m !== '') fail($m, 422);
        out(['success' => true]);

    // ============================== DEPOSITS + PAYMENTS ==============================
    case 'payment_settings_get':
        out(['success' => true] + pay_settings_get($pdo));

    case 'payment_settings_set':
        $m = pay_settings_save($pdo, $b['deposit_pct'] ?? '', (string) ($b['instructions'] ?? ''));
        if ($m !== '') fail($m, 422);
        out(['success' => true] + pay_settings_get($pdo));

    case 'payments_list':
        $f = (string) ($_GET['filter'] ?? $b['filter'] ?? 'part');
        if (!in_array($f, ['deposit', 'part', 'unpaid', 'all'], true)) $f = 'part';
        $rows = pay_balances($pdo, $f, 100);
        $due = 0.0; foreach ($rows as $r) $due += $r['balance'];
        out(['success' => true, 'filter' => $f, 'orders' => $rows, 'due' => round($due, 2)] + pay_settings_get($pdo));

    case 'order_payment_add':
        $m = pay_add($pdo, (int) $meAdmin['id'], (int) ($b['id'] ?? 0), (float) ($b['amount'] ?? 0), (string) ($b['method'] ?? ''), (string) ($b['reference'] ?? ''), (string) ($b['note'] ?? ''));
        if ($m !== '') fail($m, 422);
        out(['success' => true]);

    case 'order_payment_delete':
        $m = pay_delete($pdo, (int) $meAdmin['id'], (int) ($b['payment_id'] ?? 0));
        if ($m !== '') fail($m, 422);
        out(['success' => true]);

    // ============================== ANALYTICS ==============================
    case 'analytics':
        out(['success' => true] + analytics_data($pdo, (int) ($_GET['months'] ?? $b['months'] ?? 6)));

    // ============================== EMAIL ALERTS ==============================
    case 'mail_get':
        notify_ensure($pdo);
        $flags = [];
        foreach (['on', 'admin', 'seller', 'customer', 'subs'] as $k) $flags[$k] = ops_setting($pdo, 'mail_' . $k, '1') === '1';
        out(['success' => true, 'flags' => $flags, 'admin_to' => trim(ops_setting($pdo, 'mail_admin_to', '')), 'default_to' => defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : '',
            'stats' => notify_stats($pdo), 'recent' => notify_recent($pdo, 30),
            'subscribers' => (int) apx_val($pdo, 'SELECT COUNT(*) FROM newsletter_subscribers'),
            'newsletters' => apx_rows($pdo, 'SELECT id, subject, recipients, created_at FROM notify_newsletters ORDER BY id DESC LIMIT 10')]);

    case 'mail_save':
        foreach (['on', 'admin', 'seller', 'customer', 'subs'] as $k) {
            if (array_key_exists($k, $b)) ops_setting_set($pdo, 'mail_' . $k, !empty($b[$k]) && (string) $b[$k] !== '0' ? '1' : '0');
        }
        if (array_key_exists('admin_to', $b)) {
            $to = trim((string) $b['admin_to']);
            if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) fail('That admin email address is not valid.');
            ops_setting_set($pdo, 'mail_admin_to', $to);
        }
        out(['success' => true]);

    case 'mail_test':
        $to = notify_admin_address($pdo);
        if ($to === '') fail('Set an admin email address first.');
        if (!notify_stats($pdo)['smtp_ready']) fail('Email sending is not set up yet: SMTP_PASS is empty in config.local.php.');
        notify_queue($pdo, 'test:' . bin2hex(random_bytes(4)), 'admin', $to, 'Admin', 'Test email from ' . SITE_NAME,
            notify_wrap('It works', '<p>This is a test email from your ' . notify_esc(SITE_NAME) . ' admin.</p>'), true);
        $sent = notify_deliver($pdo, 3);
        out(['success' => true, 'sent' => $sent, 'to' => $to, 'message' => $sent > 0 ? 'Test email sent to ' . $to . '.' : 'The email was queued but the mail server did not accept it yet. Check the log below.']);

    case 'mail_newsletter':
        $subject = trim((string) ($b['subject'] ?? ''));
        $text = trim((string) ($b['body'] ?? ''));
        if ($subject === '' || $text === '') fail('Write a subject and a message.');
        $test = !empty($b['test']) && (string) $b['test'] !== '0';
        if ($test && notify_admin_address($pdo) === '') fail('Set an admin email address first.');
        $n = notify_newsletter($pdo, $subject, $text, $test);
        $sent = notify_deliver($pdo, 8);
        out(['success' => true, 'queued' => $n, 'sent' => $sent, 'message' => $test ? 'Test sent to the admin address.' : $n . ' email(s) queued. They are sent in small batches in the background.']);

    case 'mail_flush':
        notify_scan($pdo);
        $sent = notify_deliver($pdo, 15);
        out(['success' => true, 'sent' => $sent, 'stats' => notify_stats($pdo)]);


    default:
        fail('Unknown action.', 404);
}
