<?php
/** Small helpers shared by the admin pages. */

function adm_img(?string $path): string {
    $path = trim((string) $path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return '../' . ltrim($path, '/');
}

/** Photo block with a neutral fallback when there is no picture. */
function adm_photo(?string $path, string $alt = '', string $class = 'ph', string $fallback = '🛍️', bool $zoom = false): string {
    $src = adm_img($path);
    if ($src === '') {
        return '<div class="' . $class . ' ph-empty"><span>' . htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') . '</span></div>';
    }
    $s = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
    return '<div class="' . $class . '"><img src="' . $s . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" loading="lazy"' . ($zoom ? ' data-zoom="' . $s . '"' : '') . '></div>';
}

function adm_status_class(string $st): string {
    $st = strtolower($st);
    if (in_array($st, ['approved', 'paid', 'delivered', 'active', 'visible'], true)) return 'ok';
    if (in_array($st, ['pending', 'shipped', 'new'], true)) return 'warn';
    return 'bad';
}

function adm_pill(string $st): string {
    return '<span class="spill ' . adm_status_class($st) . '">' . htmlspecialchars(ucfirst($st), ENT_QUOTES, 'UTF-8') . '</span>';
}

function adm_has_column(PDO $pdo, string $table, string $col): bool {
    try {
        $q = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
        $q->execute([$col]);
        return (bool) $q->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function adm_ensure_category_image(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!adm_has_column($pdo, 'categories', 'image')) {
        try { $pdo->exec("ALTER TABLE categories ADD COLUMN image VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    }
}

/** Counts of everything the admin has not dealt with yet. */
function adm_counts(PDO $pdo): array {
    $c = ['alerts' => 0, 'orders' => 0, 'products' => 0, 'sellers' => 0, 'messages' => 0, 'suggestions' => 0];
    $one = function (string $sql) use ($pdo): int {
        try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    require_once __DIR__ . '/../../includes/seller_ops.php';
    $c['alerts'] = ops_unread_count($pdo);
    $c['orders'] = $one("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
    $c['products'] = $one("SELECT COUNT(*) FROM products WHERE status = 'pending'");
    $c['sellers'] = $one("SELECT COUNT(*) FROM sellers WHERE status = 'pending'");
    $c['messages'] = $one("SELECT COUNT(*) FROM seller_messages WHERE sender = 'seller' AND read_at IS NULL");
    $c['suggestions'] = $one("SELECT COUNT(*) FROM suggestions WHERE status = 'new'");
    return $c;
}
