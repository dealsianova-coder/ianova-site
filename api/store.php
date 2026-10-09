<?php
require __DIR__ . '/_bootstrap.php';
date_default_timezone_set('Africa/Nairobi');

function store_flag(PDO $pdo, string $key, bool $default = true): bool {
    $v = store_setting($pdo, $key);
    return $v === null ? $default : trim($v) !== '0';
}

$active = store_flag($pdo, 'show_flash');
$ends = null;
$raw = trim((string) store_setting($pdo, 'flash_ends_at'));
if ($raw !== '') {
    $ts = strtotime($raw);
    if ($ts !== false) {
        $ends = date('c', $ts);
        if ($ts < time()) $active = false;
    }
}
$title = trim((string) store_setting($pdo, 'flash_deals_title'));

$banners = [];
try {
    $img = image_column($pdo);
    foreach ($pdo->query('SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll() as $bn) {
        $image = (string) $bn['image'];
        if ($image === '' && $bn['link_type'] === 'product') {
            $q = $pdo->prepare("SELECT COALESCE($img, '') FROM products WHERE id = ?");
            $q->execute([(int) $bn['link_value']]);
            $image = (string) $q->fetchColumn();
        }
        $banners[] = ['id' => (int) $bn['id'], 'title' => $bn['title'], 'subtitle' => $bn['subtitle'], 'button_label' => $bn['button_label'], 'image' => $image, 'link_type' => $bn['link_type'], 'link_value' => $bn['link_value'], 'color' => $bn['color']];
    }
} catch (Throwable $e) { $banners = []; }

out(['success' => true, 'store' => [
    'flash' => ['active' => $active, 'ends_at' => $ends, 'title' => $title !== '' ? $title : 'Flash deals'],
    'sections' => [
        'sale' => store_flag($pdo, 'show_sale'),
        'categories' => store_flag($pdo, 'show_categories'),
        'recent' => store_flag($pdo, 'show_recent'),
        'popular' => store_flag($pdo, 'show_popular'),
    ],
    'announcement' => [
        'enabled' => store_flag($pdo, 'announcement_enabled', false),
        'text' => trim((string) store_setting($pdo, 'announcement_text')),
    ],
    'banners' => $banners,
    'delivery' => ['fee' => store_delivery_fee($pdo), 'free_over' => store_free_delivery_threshold($pdo)],
    'support' => [
        'phone' => trim((string) store_setting($pdo, 'support_phone')),
        'email' => trim((string) store_setting($pdo, 'support_email')),
    ],
]]);
