<?php
/** Extra product photos (front, back, sides...). Table is created on first use. */
function gallery_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS product_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            image VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) {
    }
}

/** Rows (id, image) of the extra photos of one product. */
function gallery_rows(PDO $pdo, int $productId): array {
    try {
        $q = $pdo->prepare('SELECT id, image FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $q->execute([$productId]);
        return $q->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** [product_id => [image paths]] for several products at once. */
function gallery_for(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $out = [];
    if (!$ids) return $out;
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT product_id, image FROM product_images WHERE product_id IN ($in) ORDER BY sort_order, id");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) {
            $out[(int) $r['product_id']][] = (string) $r['image'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Main photo first, then the extra photos, no empties or duplicates. */
function gallery_list(string $main, array $extra): array {
    $list = [];
    foreach (array_merge([$main], $extra) as $img) {
        $img = trim((string) $img);
        if ($img !== '' && !in_array($img, $list, true)) $list[] = $img;
    }
    return $list;
}

/** Save uploaded photos. $f = ['name'=>[], 'tmp_name'=>[], 'error'=>[], 'size'=>[], 'type'=>[]]. */
function gallery_save_files(PDO $pdo, int $productId, array $f): int {
    gallery_ensure($pdo);
    if ($productId < 1 || empty($f['name']) || !is_array($f['name'])) return 0;
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $dir = __DIR__ . '/../uploads/products/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $q = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = ?');
    $q->execute([$productId]);
    $order = (int) $q->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)');
    $saved = 0;
    foreach ($f['name'] as $i => $name) {
        if ($name === '' || (int) ($f['error'][$i] ?? 4) !== UPLOAD_ERR_OK) continue;
        if ($saved >= 10) break;
        $tmp = $f['tmp_name'][$i];
        $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : ($f['type'][$i] ?? '');
        if (!isset($allowed[$mime]) || (int) $f['size'][$i] > 5 * 1024 * 1024) continue;
        $file = 'uploads/products/' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, __DIR__ . '/../' . $file)) continue;
        $ins->execute([$productId, $file, ++$order]);
        $saved++;
    }
    return $saved;
}

function gallery_delete(PDO $pdo, int $productId, array $imageIds): void {
    $imageIds = array_values(array_filter(array_map('intval', $imageIds)));
    if (!$imageIds) return;
    $in = implode(',', array_fill(0, count($imageIds), '?'));
    try {
        $pdo->prepare("DELETE FROM product_images WHERE product_id = ? AND id IN ($in)")
            ->execute(array_merge([$productId], $imageIds));
    } catch (Throwable $e) {
    }
}
