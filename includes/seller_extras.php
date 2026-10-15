<?php
/** Extra photos and extra colors for a seller's product (website form). Same rules as the admin form. */
require_once __DIR__ . '/gallery.php';

/** Saves gallery uploads, removals and extra colors for product $pid. Returns an error text or ''. */
function seller_extras_save(PDO $pdo, int $pid, array $seller, string $status): string {
    gallery_ensure($pdo);
    $sid = (int) $seller['id'];
    $q = $pdo->prepare('SELECT * FROM products WHERE id = ? AND seller_id = ?');
    $q->execute([$pid, $sid]);
    $p = $q->fetch();
    if (!$p) return '';
    if (!empty($_POST['del_img'])) gallery_delete($pdo, $pid, (array) $_POST['del_img']);
    if (!empty($_FILES['gallery']['name']) && is_array($_FILES['gallery']['name'])) {
        gallery_save_files($pdo, $pid, $_FILES['gallery']);
    }
    $oc = (array) ($_POST['opt_color'] ?? []);
    $any = false;
    foreach ($oc as $x) { if (trim((string) $x) !== '') $any = true; }
    if (!$any) return '';
    if (trim((string) ($p['color'] ?? '')) === '') return 'Product saved. Enter a Color for the main product, then add the extra colors.';
    $os = (array) ($_POST['opt_stock'] ?? []);
    $op = (array) ($_POST['opt_price'] ?? []);
    $of = $_FILES['opt_image'] ?? null;
    $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $dup = $pdo->prepare('SELECT id FROM products WHERE seller_id = ? AND category_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND LOWER(TRIM(color)) = LOWER(TRIM(?)) AND LOWER(TRIM(size)) = LOWER(TRIM(?))');
    $base = trim((string) ($_POST['slug'] ?? '')) ?: (string) $p['slug'];
    $ins = $pdo->prepare('INSERT INTO products(category_id,name,slug,description,price,original_price,emoji,bg_color,stock,is_flash_deal,image,subcategory,color,size,seller_id,seller_name,rating,review_count,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,4.5,0,?)');
    foreach ($oc as $i => $oColor) {
        $oColor = substr(trim((string) $oColor), 0, 40);
        if ($oColor === '') continue;
        $dup->execute([$sid, $p['category_id'], $p['name'], $oColor, $p['size'] ?? '']);
        if ($dup->fetch()) continue;
        $oStock = max(0, (int) ($os[$i] ?? 0));
        $oPrice = (isset($op[$i]) && trim((string) $op[$i]) !== '') ? (float) $op[$i] : (float) $p['price'];
        $oImage = null;
        if ($of && is_array($of['name']) && !empty($of['name'][$i]) && (int) $of['error'][$i] === UPLOAD_ERR_OK) {
            $mime = function_exists('mime_content_type') ? mime_content_type($of['tmp_name'][$i]) : $of['type'][$i];
            if (isset($ok[$mime]) && $of['size'][$i] <= 5 * 1024 * 1024) {
                $fn = 'uploads/products/' . bin2hex(random_bytes(10)) . '.' . $ok[$mime];
                if (move_uploaded_file($of['tmp_name'][$i], __DIR__ . '/../' . $fn)) $oImage = $fn;
            }
        }
        $oSlug = ianova_unique_slug($pdo, $base, 0, $oColor, (string) ($p['size'] ?? ''));
        $ins->execute([$p['category_id'], $p['name'], $oSlug, $p['description'], $oPrice, $p['original_price'], $p['emoji'], $p['bg_color'], $oStock, $p['is_flash_deal'], $oImage, $p['subcategory'] ?? '', $oColor, $p['size'] ?? '', $sid, $p['seller_name'], $status]);
        $oid = (int) $pdo->lastInsertId();
        $k = $_POST['opt_k'][$i] ?? null;
        $om = $_FILES['opt_more'] ?? null;
        if ($k !== null && $om && isset($om['name'][$k]) && is_array($om['name'][$k])) {
            gallery_save_files($pdo, $oid, ['name' => $om['name'][$k], 'tmp_name' => $om['tmp_name'][$k], 'error' => $om['error'][$k], 'size' => $om['size'][$k], 'type' => $om['type'][$k]]);
        }
    }
    return '';
}
