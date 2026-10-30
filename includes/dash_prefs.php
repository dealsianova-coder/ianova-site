<?php
/** What each admin sees on their dashboard (sections and quick actions). Shared by the app and the website. */
require_once __DIR__ . '/seller_ops.php';

const DPF_SECTIONS = [
    'welcome' => 'Welcome card', 'kpi_revenue' => 'Revenue card', 'kpi_orders' => 'Orders card', 'kpi_products' => 'Products card', 'kpi_customers' => 'Customers card',
    'sales' => 'Sales chart', 'quick' => 'Quick actions', 'recent' => 'Recent orders', 'attention' => 'Needs your attention',
    'more_orders' => 'Panel: Orders', 'more_products' => 'Panel: Products', 'more_categories' => 'Panel: Categories', 'more_sellers' => 'Panel: Sellers', 'more_users' => 'Panel: Users',
];
/** id => [label, sub, emoji, app page, website page] */
const DPF_QUICK = [
    'add_product' => ['Add Product', 'Create new product', '➕', 'add_product', 'product_edit.php'],
    'orders' => ['Manage Orders', 'View and process orders', '🛒', 'orders', 'orders.php'],
    'sellers' => ['Manage Sellers', 'Control seller accounts', '🏪', 'sellers', 'sellers.php'],
    'settings' => ['Store Settings', 'Configure your store', '⚙️', 'settings', 'settings.php'],
    'revenue' => ['Revenue', 'Where money came from', '💰', 'revenue', 'revenue.php'],
    'analytics' => ['Analytics', 'Sales and trends', '📈', 'analytics', 'analytics.php'],
    'products' => ['Products', 'Manage the catalogue', '📦', 'products', 'products.php'],
    'categories' => ['Categories', 'Organise products', '🏷️', 'categories', 'categories.php'],
    'users' => ['Customers', 'See your customers', '👥', 'users', 'users.php'],
    'coupons' => ['Coupons', 'Discount codes', '🎟️', 'coupons', 'coupons.php'],
    'flash' => ['Flash deals', 'Limited-time offers', '⚡', 'flash', 'flash.php'],
    'banners' => ['Banners', 'Home page banners', '🖼️', 'banners', 'banners.php'],
    'payouts' => ['Seller payouts', 'Pay your sellers', '💸', 'payouts', 'payouts.php'],
    'payments' => ['Payments', 'Balances to collect', '💳', 'payments', 'payments.php'],
    'broadcast' => ['Broadcast', 'Message customers', '📢', 'broadcast', 'broadcast.php'],
];
const DPF_QUICK_DEFAULT = ['add_product', 'orders', 'sellers', 'settings'];

function dpf_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS admin_dash_prefs (admin_id INT NOT NULL, k VARCHAR(40) NOT NULL, v TEXT NOT NULL, PRIMARY KEY (admin_id, k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { /* ignore */ }
}

/** ['hide' => [section ids], 'quick' => [quick action ids]] */
function dpf_get(PDO $pdo, int $adminId): array {
    dpf_ensure($pdo);
    $out = ['hide' => [], 'quick' => DPF_QUICK_DEFAULT];
    try {
        $q = $pdo->prepare("SELECT v FROM admin_dash_prefs WHERE admin_id = ? AND k = 'dash'"); $q->execute([$adminId]);
        $v = $q->fetchColumn();
        if ($v) {
            $j = json_decode((string) $v, true);
            if (is_array($j)) {
                $out['hide'] = array_values(array_filter((array) ($j['hide'] ?? []), fn($x) => isset(DPF_SECTIONS[$x])));
                $qk = array_values(array_filter((array) ($j['quick'] ?? []), fn($x) => isset(DPF_QUICK[$x])));
                if ($qk) $out['quick'] = array_slice($qk, 0, 8);
            }
        }
    } catch (Throwable $e) { /* defaults */ }
    return $out;
}

function dpf_save(PDO $pdo, int $adminId, array $hide, array $quick): void {
    dpf_ensure($pdo);
    $hide = array_values(array_filter(array_unique($hide), fn($x) => is_string($x) && isset(DPF_SECTIONS[$x])));
    $quick = array_slice(array_values(array_filter(array_unique($quick), fn($x) => is_string($x) && isset(DPF_QUICK[$x]))), 0, 8);
    if (!$quick) $quick = DPF_QUICK_DEFAULT;
    $json = json_encode(['hide' => $hide, 'quick' => $quick]);
    $pdo->prepare("INSERT INTO admin_dash_prefs (admin_id, k, v) VALUES (?, 'dash', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$adminId, $json]);
}
