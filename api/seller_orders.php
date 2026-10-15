<?php
/**
 * Orders with this seller's products, using the website's approval system
 * (seller_order_approvals). GET: list. POST {action: approve, order_id} or {action: auto, enabled}.
 */
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/seller_ops.php';
$seller = require_seller($pdo);
$sid = (int) $seller['id'];
$img = image_column($pdo);

if (method() === 'POST') {
    $b = body();
    $action = (string) ($b['action'] ?? '');
    if ($action === 'approve') {
        [$ok, $msg] = ops_approve_order($pdo, $seller, (int) ($b['order_id'] ?? 0));
        if (!$ok) fail($msg, 409);
    } elseif ($action === 'auto') {
        ops_setting_set($pdo, 'auto_seller_' . $sid, !empty($b['enabled']) ? '1' : '0');
    } else {
        fail('Unknown action.');
    }
} elseif (method() !== 'GET') {
    fail('Method not allowed.', 405);
}

$orders = ops_seller_orders($pdo, $seller);

// Add product photos to every line.
if ($orders) {
    $ids = array_map(static fn($o) => (int) $o['id'], $orders);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT oi.order_id, p.name, COALESCE(p.emoji, '') AS emoji, COALESCE(p.bg_color, '#EFF3EC') AS bg_color, COALESCE(p.$img, '') AS image
                        FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id IN ($ph) AND p.seller_id = ?");
    $q->execute(array_merge($ids, [$sid]));
    $photo = [];
    foreach ($q->fetchAll() as $r) $photo[(int) $r['order_id'] . '|' . $r['name']] = $r;
    foreach ($orders as &$o) {
        foreach ($o['items'] as &$it) {
            $r = $photo[(int) $o['id'] . '|' . $it['name']] ?? null;
            $it['image'] = $r['image'] ?? '';
            $it['emoji'] = $r['emoji'] ?? '';
            $it['bg_color'] = $r['bg_color'] ?? '#EFF3EC';
        }
        unset($it);
    }
    unset($o);
}

$master = ops_setting($pdo, 'auto_approve', '1') === '1';
out(['success' => true, 'auto_master' => $master, 'auto_mine' => ops_seller_auto($pdo, $sid), 'orders' => $orders]);
