<?php
require_once 'includes/auth.php';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$q = $pdo->prepare("SELECT o.id, o.created_at, o.status, o.total, COALESCE(o.guest_name, u.name, 'Guest') customer, COALESCE(o.guest_email, u.email) email, o.guest_phone phone, o.address,
    (SELECT GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name) SEPARATOR '; ') FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id) items
    FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE DATE(o.created_at) BETWEEN ? AND ? ORDER BY o.id");
$q->execute([$from, $to]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="orders-' . $from . '-to-' . $to . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Order', 'Date', 'Status', 'Total', 'Customer', 'Email', 'Phone', 'Address', 'Items']);
$safe = fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : (string) $v;   // stop spreadsheet formula injection
foreach ($q as $r) fputcsv($out, [$r['id'], $r['created_at'], $r['status'], $r['total'], $safe($r['customer']), $safe($r['email']), $safe($r['phone']), $safe($r['address']), $safe($r['items'])]);
fclose($out);
