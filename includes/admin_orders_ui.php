<?php
/** Shared helpers for the admin Orders list, Order details and Product view. */

function aou_thumb(?string $image, ?string $emoji, ?string $bg, int $size = 44): string {
    $s = (int) $size;
    $bg = preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string) $bg) ? $bg : '#EFF3EC';
    $base = 'width:' . $s . 'px;height:' . $s . 'px;border-radius:10px;flex:none;';
    if ($image !== null && $image !== '') {
        return '<img src="../' . htmlspecialchars($image, ENT_QUOTES) . '" alt="" loading="lazy" style="' . $base . 'object-fit:cover;background:' . $bg . '">';
    }
    return '<span style="' . $base . 'display:inline-flex;align-items:center;justify-content:center;background:' . $bg . ';font-size:' . (int) ($s * 0.5) . 'px">' . htmlspecialchars(($emoji !== null && $emoji !== '') ? $emoji : '📦', ENT_QUOTES) . '</span>';
}

/** [order_id => [lines]] for the given orders (one query). */
function aou_items(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $q = $pdo->prepare(
            "SELECT oi.order_id, oi.product_id, oi.quantity, oi.unit_price,
                    p.name, p.image, p.emoji, p.bg_color, p.status AS product_status,
                    COALESCE(p.color, '') AS color, COALESCE(p.size, '') AS size,
                    p.seller_id, COALESCE(s.business_name, p.seller_name, 'IANOVA') AS seller_name
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             LEFT JOIN sellers s ON s.id = p.seller_id
             WHERE oi.order_id IN ($in) ORDER BY oi.id"
        );
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) $out[(int) $r['order_id']][] = $r;
    } catch (Throwable $e) {
    }
    return $out;
}

/** [order_id => [seller_id => ['name' => ..., 'at' => approved_at|null]]] for seller-owned items. */
function aou_approvals(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $q = $pdo->prepare(
            "SELECT DISTINCT oi.order_id, p.seller_id, COALESCE(s.business_name, p.seller_name) AS name, a.approved_at
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id AND p.seller_id IS NOT NULL
             LEFT JOIN sellers s ON s.id = p.seller_id
             LEFT JOIN seller_order_approvals a ON a.order_id = oi.order_id AND a.seller_id = p.seller_id
             WHERE oi.order_id IN ($in)"
        );
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) $out[(int) $r['order_id']][(int) $r['seller_id']] = ['name' => $r['name'], 'at' => $r['approved_at']];
    } catch (Throwable $e) {
    }
    return $out;
}

function aou_css(): string {
    return '<style>.chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.chips a{padding:7px 12px;border-radius:999px;background:#fff;border:1px solid #dce4df;font-size:13px;font-weight:700}.chips a.on{background:#1d6b52;color:#fff;border-color:#1d6b52}.chips b{opacity:.7;font-weight:600;margin-left:4px}'
        . '.ol{display:flex;flex-direction:column;gap:6px;min-width:230px}.ol a{display:flex;align-items:center;gap:9px;color:inherit}.ol small{display:block;color:#6b7973}.ol .more{font-size:12px;color:#6b7973}'
        . '.tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700;margin:2px 4px 2px 0}.tag.ok{background:#e1f3e8;color:#166534}.tag.wait{background:#fff3d6;color:#92600a}.tag.na{background:#eef1f0;color:#52615a}'
        . '.otbl td{vertical-align:top}.otbl select{padding:7px;border:1px solid #ccd7d1;border-radius:8px}.stbar{display:flex;gap:6px;align-items:center}</style>';
}

function aou_status_tag(string $st): string {
    $m = ['pending' => 'wait', 'approved' => 'ok', 'paid' => 'ok', 'shipped' => 'ok', 'delivered' => 'ok', 'cancelled' => 'na'];
    return '<span class="tag ' . ($m[$st] ?? 'na') . '">' . htmlspecialchars($st, ENT_QUOTES) . '</span>';
}

function aou_approval_tags(array $rows): string {
    if (!$rows) return '<span class="tag na">IANOVA direct</span>';
    $h = '';
    foreach ($rows as $r) {
        $h .= '<span class="tag ' . ($r['at'] ? 'ok' : 'wait') . '">' . htmlspecialchars((string) $r['name'], ENT_QUOTES) . ' · ' . ($r['at'] ? 'approved' : 'waiting') . '</span>';
    }
    return $h;
}

/** SQL condition (on alias o) for the order scope: 'main' = IANOVA's own products, 'sellers' = seller products. */
function aou_scope_sql(string $scope, int $sellerId = 0): array {
    if ($scope === 'sellers') {
        $sql = ' AND EXISTS (SELECT 1 FROM order_items xi JOIN products xp ON xp.id = xi.product_id WHERE xi.order_id = o.id AND xp.seller_id IS NOT NULL' . ($sellerId > 0 ? ' AND xp.seller_id = ?' : '') . ')';
        return [$sql, $sellerId > 0 ? [$sellerId] : []];
    }
    return [' AND EXISTS (SELECT 1 FROM order_items xi LEFT JOIN products xp ON xp.id = xi.product_id WHERE xi.order_id = o.id AND xp.seller_id IS NULL)', []];
}

/** Keeps only the lines that belong to the scope. */
function aou_scope_lines(array $lines, string $scope, int $sellerId = 0): array {
    return array_values(array_filter($lines, function ($l) use ($scope, $sellerId) {
        $sid = (int) ($l['seller_id'] ?? 0);
        if ($scope === 'sellers') return $sid > 0 && ($sellerId <= 0 || $sid === $sellerId);
        return $sid === 0;
    }));
}

function aou_lines_total(array $lines): float {
    $t = 0.0;
    foreach ($lines as $l) $t += (float) $l['unit_price'] * (int) $l['quantity'];
    return $t;
}

function aou_scope_count(PDO $pdo, string $scope, int $sellerId = 0): int {
    [$sql, $par] = aou_scope_sql($scope, $sellerId);
    try {
        $q = $pdo->prepare('SELECT COUNT(*) FROM orders o WHERE 1=1' . $sql);
        $q->execute($par);
        return (int) $q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** [seller_id => ['name' => ..., 'orders' => n, 'waiting' => n]] for every seller that has orders. */
function aou_seller_counts(PDO $pdo): array {
    $out = [];
    try {
        $q = $pdo->query("SELECT p.seller_id, COALESCE(s.business_name, p.seller_name, 'Seller') name, COUNT(DISTINCT oi.order_id) n,
                COUNT(DISTINCT CASE WHEN a.approved_at IS NULL THEN oi.order_id END) waiting
            FROM order_items oi JOIN products p ON p.id = oi.product_id AND p.seller_id IS NOT NULL
            LEFT JOIN sellers s ON s.id = p.seller_id
            LEFT JOIN seller_order_approvals a ON a.order_id = oi.order_id AND a.seller_id = p.seller_id
            GROUP BY p.seller_id, name ORDER BY name");
        foreach ($q->fetchAll() as $r) $out[(int) $r['seller_id']] = ['name' => $r['name'], 'orders' => (int) $r['n'], 'waiting' => (int) $r['waiting']];
    } catch (Throwable $e) {
    }
    return $out;
}

/** Groups order rows by seller: [seller_id => ['name' => ..., 'rows' => [[order, lines], ...]]]. */
function aou_group_by_seller(array $orders, array $items): array {
    $g = [];
    foreach ($orders as $o) {
        $bySeller = [];
        foreach ($items[(int) $o['id']] ?? [] as $l) {
            $sid = (int) ($l['seller_id'] ?? 0);
            if ($sid > 0) $bySeller[$sid][] = $l;
        }
        foreach ($bySeller as $sid => $lines) {
            if (!isset($g[$sid])) $g[$sid] = ['name' => $lines[0]['seller_name'], 'rows' => []];
            $g[$sid]['rows'][] = [$o, $lines];
        }
    }
    uasort($g, function ($a, $b) { return strcasecmp((string) $a['name'], (string) $b['name']); });
    return $g;
}
