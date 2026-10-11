<?php
/** Seller tools for the app: badges, products with restrictions, orders and approval. */
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/seller_ops.php';
ops_ensure($pdo);
$seller = require_seller($pdo);
$sid = (int) $seller['id'];
$b = body();
$action = (string) ($_GET['action'] ?? $b['action'] ?? '');

switch ($action) {
    case 'summary':
        out(['success' => true, 'status' => $seller['status']] + ops_seller_counts($pdo, $sid));

    case 'products':
        $opt = has_option_columns($pdo) ? "COALESCE(color, '') AS color, COALESCE(size, '') AS size" : "'' AS color, '' AS size";
        $q = $pdo->prepare("SELECT id, name, price, stock, status, $opt FROM products WHERE seller_id = ? ORDER BY id DESC LIMIT 200");
        $q->execute([$sid]);
        $rows = array_map(static function ($p) {
            return [
                'id' => (int) $p['id'], 'name' => $p['name'], 'price' => (float) $p['price'], 'stock' => (int) $p['stock'],
                'status' => $p['status'], 'color' => $p['color'], 'size' => $p['size'], 'locked' => ops_product_locked($p),
            ];
        }, $q->fetchAll());
        out(['success' => true, 'products' => $rows]);

    case 'request_review':
        [$ok, $msg] = ops_request_review($pdo, $seller, (int) ($b['product_id'] ?? 0), (string) ($b['note'] ?? ''));
        if (!$ok) fail($msg);
        out(['success' => true, 'message' => $msg]);

    case 'orders':
        out(['success' => true, 'orders' => ops_seller_orders($pdo, $seller)]);

    case 'approve':
        [$ok, $msg] = ops_approve_order($pdo, $seller, (int) ($b['order_id'] ?? 0));
        if (!$ok) fail($msg);
        out(['success' => true, 'message' => $msg]);

    default:
        fail('Unknown action.', 404);
}
