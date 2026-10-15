<?php
/** Category pictures: replaces the emoji icon when an image is uploaded. */

function cat_img_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM categories LIKE 'image'")->fetch();
        if (!$has) $pdo->exec("ALTER TABLE categories ADD COLUMN image VARCHAR(255) NULL DEFAULT NULL");
    } catch (Throwable $e) { /* ignore */ }
    $dir = dirname(__DIR__) . '/uploads/categories';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

/** Saves an uploaded category picture; returns its path ("uploads/categories/x.jpg") or null. */
function cat_img_save(array $file): ?string {
    if (empty($file['name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) return null;
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
    if (!isset($types[$mime])) return null;
    $dir = dirname(__DIR__) . '/uploads/categories';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rel = 'uploads/categories/' . bin2hex(random_bytes(10)) . '.' . $types[$mime];
    return move_uploaded_file($file['tmp_name'], dirname(__DIR__) . '/' . $rel) ? $rel : null;
}

function cat_img_delete(?string $rel): void {
    if (!$rel || strpos($rel, 'uploads/categories/') !== 0 || strpos($rel, '..') !== false) return;
    $p = dirname(__DIR__) . '/' . $rel;
    if (is_file($p)) @unlink($p);
}

/** Icon markup: the picture if there is one, otherwise the emoji. $prefix is '' (site) or '../' (admin). */
function cat_icon_html(array $c, string $class = 'emoji', string $prefix = ''): string {
    $img = trim((string) ($c['image'] ?? ''));
    $cls = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
    if ($img !== '') {
        return '<span class="' . $cls . '" style="overflow:hidden"><img src="' . htmlspecialchars($prefix . $img, ENT_QUOTES, 'UTF-8')
            . '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block"></span>';
    }
    return '<span class="' . $cls . '">' . htmlspecialchars((string) ($c['emoji'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
}
