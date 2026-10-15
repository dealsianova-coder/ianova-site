<?php
/**
 * Extra photos and extra colors for a seller's own product (same as the admin form).
 * GET  ?id=N                      -> { gallery: [{id, image}] }
 * POST multipart: id, del_img[] (photo ids to remove), gallery[] (new photos),
 *      opt_color[], opt_stock[], opt_price[], opt_image[] (one entry per extra color).
 */
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/gallery.php';
$seller = require_approved_seller($pdo);
$sid = (int) $seller['id'];
$img = image_column($pdo);
gallery_ensure($pdo);

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT * FROM products WHERE id = ? AND seller_id = ?');
$q->execute([$id, $sid]);
$p = $q->fetch();
if (!$p) fail('Product not found.', 404);

if (method() === 'POST') {
    if (!empty($_POST['del_img'])) gallery_delete($pdo, $id, (array) $_POST['del_img']);
    if (!empty($_FILES['gallery']['name']) && is_array($_FILES['gallery']['name'])) {
        gallery_save_files($pdo, $id, $_FILES['gallery']);
    }

    $oc = (array) ($_POST['opt_color'] ?? []);
    if ($oc && has_option_columns($pdo)) {
        $os = (array) ($_POST['opt_stock'] ?? []);
        $op = (array) ($_POST['opt_price'] ?? []);
        $of = $_FILES['opt_image'] ?? null;
        $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $color = trim((string) ($p['color'] ?? ''));
        if ($color === '') fail('Enter a Color for the main product before adding more colors.');
        $dup = $pdo->prepare('SELECT id FROM products WHERE seller_id = ? AND category_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND LOWER(TRIM(color)) = LOWER(TRIM(?)) AND LOWER(TRIM(size)) = LOWER(TRIM(?))');
        $hasSub = has_subcategory($pdo);
        foreach ($oc as $i => $oColor) {
            $oColor = mb_substr(trim((string) $oColor), 0, 40);
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
            $cols = ['category_id', 'name', 'slug', 'description', 'price', 'original_price', 'emoji', 'bg_color', 'stock', 'is_flash_deal', 'seller_id', 'seller_name', 'rating', 'review_count', 'status', 'color', 'size'];
            $par = [$p['category_id'], $p['name'], seller_slug($pdo, $p['name'] . '-' . $oColor), $p['description'], $oPrice, $p['original_price'], $p['emoji'], $p['bg_color'], $oStock, $p['is_flash_deal'], $sid, $p['seller_name'], 4.5, 0, $p['status'], $oColor, $p['size'] ?? ''];
            if ($hasSub) { $cols[] = 'subcategory'; $par[] = $p['subcategory'] ?? ''; }
            if ($oImage !== null) { $cols[] = $img; $par[] = $oImage; }
            try {
                $pdo->prepare('INSERT INTO products (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($par);
            } catch (Throwable $e) {
                fail('Could not add the color ' . $oColor . '.', 500);
            }
            $nid = (int) $pdo->lastInsertId();
            $k = $_POST['opt_k'][$i] ?? null;
            $om = $_FILES['opt_more'] ?? null;
            if ($k !== null && $om && isset($om['name'][$k]) && is_array($om['name'][$k])) {
                gallery_save_files($pdo, $nid, ['name' => $om['name'][$k], 'tmp_name' => $om['tmp_name'][$k], 'error' => $om['error'][$k], 'size' => $om['size'][$k], 'type' => $om['type'][$k]]);
            }
        }
    }
}

out(['success' => true, 'gallery' => array_map(static fn($r) => ['id' => (int) $r['id'], 'image' => $r['image']], gallery_rows($pdo, $id))]);
