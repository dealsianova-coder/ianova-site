<?php
require __DIR__ . '/_bootstrap.php';
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) fail('Invalid product ID.');
$s = $pdo->prepare('SELECT ' . product_cols($pdo) . ' FROM products p WHERE p.id = ? AND p.status = "approved" AND ' . display_sql('p'));
$s->execute([$id]);
$p = $s->fetch();
if (!$p) fail('Product not found.', 404);
$v = $pdo->prepare('SELECT id, product_id, color, size, sku, price, original_price, stock, image, created_at FROM product_variants WHERE product_id = ? ORDER BY id');
$v->execute([$id]);
$variants = $v->fetchAll();

// Colors / sizes: other approved listings with the same name, category and seller.
$sid = $pdo->prepare('SELECT COALESCE(seller_id, 0) FROM products WHERE id = ?');
$sid->execute([$id]);
$sellerId = (int) $sid->fetchColumn();
$ord = has_option_columns($pdo) ? 'p.color, p.size, p.id' : 'p.id';
$o = $pdo->prepare('SELECT ' . product_cols($pdo) . ' FROM products p WHERE p.status = "approved" AND p.category_id = ? AND COALESCE(p.seller_id, 0) = ? AND LOWER(TRIM(p.name)) = LOWER(TRIM(?)) AND ' . display_sql('p') . ' ORDER BY ' . $ord);
$o->execute([(int) $p['category_id'], $sellerId, $p['name']]);
$options = $o->fetchAll();

require_once __DIR__ . '/../includes/gallery.php';
gallery_ensure($pdo);
$galleryIds = array_merge([$id], array_map('intval', array_column($options, 'id')));
$galleries = gallery_for($pdo, $galleryIds);
$p['images'] = gallery_list((string) ($p['image'] ?? ''), $galleries[$id] ?? []);
foreach ($options as &$galleryOpt) {
    $galleryOpt['images'] = gallery_list((string) ($galleryOpt['image'] ?? ''), $galleries[(int) $galleryOpt['id']] ?? []);
}
unset($galleryOpt);

// Suggestions: same category first (same subcategory ahead of the rest), then other products.
$nameKey = strtolower(trim((string) $p['name']));
$order = has_subcategory($pdo) ? 'CASE WHEN p.subcategory <> "" AND p.subcategory = ? THEN 0 ELSE 1 END, ' : '';
$args = [$id, (int) $p['category_id'], $nameKey];
if ($order !== '') $args[] = (string) $p['subcategory'];
$r = $pdo->prepare('SELECT ' . product_cols($pdo) . ' FROM products p WHERE p.status = "approved" AND p.id <> ? AND p.category_id = ? AND LOWER(TRIM(p.name)) <> ? AND ' . representative_filter('p') . ' ORDER BY ' . $order . 'p.is_flash_deal DESC, p.id DESC LIMIT 8');
$r->execute($args);
$related = $r->fetchAll();
if (count($related) < 8) {
    $ids = array_merge([$id], array_map('intval', array_column($related, 'id')));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $more = $pdo->prepare('SELECT ' . product_cols($pdo) . " FROM products p WHERE p.status = \"approved\" AND p.id NOT IN ($in) AND LOWER(TRIM(p.name)) <> ? AND " . representative_filter('p') . ' ORDER BY p.is_flash_deal DESC, p.id DESC LIMIT ' . (8 - count($related)));
    $more->execute(array_merge($ids, [$nameKey]));
    $related = array_merge($related, $more->fetchAll());
}

out(['success' => true, 'product' => $p, 'variants' => $variants, 'variant_count' => count($variants), 'options' => $options, 'related' => $related]);
