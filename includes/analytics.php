<?php
/** Numbers for the admin Analytics panel (website admin and Admin app share this). */
function analytics_trend(float $now, float $before): ?float {
    if ($before <= 0) return $now > 0 ? null : 0.0;
    return round(($now - $before) / $before * 100, 1);
}

function analytics_data(PDO $pdo, int $months = 6): array {
    $months = $months === 12 ? 12 : 6;
    $one = function (string $sql, array $par = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($par); $v = $q->fetchColumn(); return $v === false || $v === null ? 0 : $v; } catch (Throwable $e) { return 0; } };
    $rows = function (string $sql, array $par = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($par); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };
    $ok = "status <> 'cancelled'";

    $rev = (float) $one("SELECT COALESCE(SUM(total),0) FROM orders WHERE $ok");
    $rev -= (float) $one('SELECT COALESCE(SUM(amount),0) FROM order_refunds'); // refunds paid back to customers
    $rev30 = (float) $one("SELECT COALESCE(SUM(total),0) FROM orders WHERE $ok AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $revPrev = (float) $one("SELECT COALESCE(SUM(total),0) FROM orders WHERE $ok AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $ord = (int) $one('SELECT COUNT(*) FROM orders');
    $ord30 = (int) $one('SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $ordPrev = (int) $one('SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $cus = (int) $one('SELECT COUNT(*) FROM users');
    $cus30 = (int) $one('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $cusPrev = (int) $one('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $prod = (int) $one('SELECT COUNT(*) FROM products');
    $review = (int) $one("SELECT COUNT(*) FROM products WHERE status = 'pending'");

    // month by month
    $byMonth = [];
    foreach ($rows("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COALESCE(SUM(total),0) t FROM orders WHERE $ok AND created_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL " . ($months - 1) . " MONTH) GROUP BY m") as $r) $byMonth[$r['m']] = (float) $r['t'];
    $sales = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ts = strtotime(date('Y-m-01') . " -$i month");
        $sales[] = ['label' => date('M', $ts), 'value' => round($byMonth[date('Y-m', $ts)] ?? 0, 2)];
    }

    // order status
    $status = [];
    $total = 0;
    foreach ($rows('SELECT status, COUNT(*) c FROM orders GROUP BY status') as $r) { $status[$r['status']] = (int) $r['c']; $total += (int) $r['c']; }
    $statusList = [];
    foreach (['pending', 'approved', 'paid', 'shipped', 'delivered', 'cancelled'] as $k) {
        $c = $status[$k] ?? 0;
        $statusList[] = ['status' => $k, 'count' => $c, 'percent' => $total > 0 ? round($c / $total * 100, 1) : 0];
    }

    $recent = $rows("SELECT o.id, o.total, o.status, o.created_at, COALESCE(o.guest_name, u.name, 'Guest') customer,
        (SELECT p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id ORDER BY oi.id LIMIT 1) product,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) items
        FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 5");
    foreach ($recent as &$r) { $r['id'] = (int) $r['id']; $r['total'] = (float) $r['total']; $r['items'] = (int) $r['items']; $r['product'] = (string) ($r['product'] ?? ''); }
    unset($r);

    $top = $rows("SELECT MIN(p.name) name, MIN(p.id) id, SUM(oi.quantity) sold FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id WHERE o.status <> 'cancelled' GROUP BY LOWER(TRIM(p.name)) ORDER BY sold DESC LIMIT 5");
    foreach ($top as &$r) { $r['sold'] = (int) $r['sold']; $r['id'] = (int) $r['id']; }
    unset($r);

    return [
        'months' => $months,
        'cards' => [
            'revenue' => ['value' => $rev, 'last30' => $rev30, 'trend' => analytics_trend($rev30, $revPrev)],
            'orders' => ['value' => $ord, 'last30' => $ord30, 'trend' => analytics_trend($ord30, $ordPrev)],
            'customers' => ['value' => $cus, 'last30' => $cus30, 'trend' => analytics_trend($cus30, $cusPrev)],
            'products' => ['value' => $prod, 'review' => $review],
        ],
        'sales' => $sales,
        'status' => $statusList,
        'orders_total' => $total,
        'recent' => $recent,
        'top' => $top,
    ];
}
