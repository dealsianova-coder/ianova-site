<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_orders_ui.php';
require_once __DIR__ . '/../includes/gallery.php';
$id = (int) ($_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT p.*, c.name AS category, COALESCE(s.business_name, p.seller_name, \'IANOVA\') AS seller FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN sellers s ON s.id = p.seller_id WHERE p.id = ?');
$q->execute([$id]);
$p = $q->fetch();
$page_title = $p ? $p['name'] : 'Product';
require 'includes/header.php';
if (!$p) { echo '<div class="notice error">Product not found.</div>'; require 'includes/footer.php'; exit; }
echo aou_css();
$gal = gallery_rows($pdo, $id);
$imgs = gallery_list((string) ($p['image'] ?? ''), array_column($gal, 'image'));
$sq = $pdo->prepare('SELECT id, color, size, stock, image FROM products WHERE category_id = ? AND COALESCE(seller_id, 0) = COALESCE(?, 0) AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? ORDER BY id');
$sq->execute([$p['category_id'], $p['seller_id'], $p['name'], $id]);
$others = $sq->fetchAll();
$opt = trim(($p['color'] ?? '') . ' ' . ($p['size'] ?? ''));
?>
<style>.pv{display:grid;grid-template-columns:minmax(0,340px) 1fr;gap:22px}.pv .big{width:100%;aspect-ratio:1;object-fit:cover;border-radius:16px;background:#eef3ef;display:block}.pv .strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.pv .strip img{width:64px;height:64px;object-fit:cover;border-radius:10px;cursor:pointer}.pv dl{display:grid;grid-template-columns:130px 1fr;gap:8px 14px;margin:14px 0}.pv dt{color:#6b7973;font-size:13px}.pv dd{margin:0;font-weight:600}.pv .price{font-size:26px;font-weight:800}.pv .old{color:#6b7973;text-decoration:line-through;margin-left:8px}@media(max-width:700px){.pv{grid-template-columns:1fr}}</style>
<div class="actions" style="margin-bottom:14px"><a class="btn gray" href="javascript:history.back()">← Back</a><a class="btn" href="product_edit.php?id=<?= $id ?>">Edit product</a></div>
<div class="card pv">
  <div>
    <?php if ($imgs): ?><img class="big" id="big" src="../<?= admin_h($imgs[0]) ?>" alt=""><?php else: ?><div class="big" style="display:flex;align-items:center;justify-content:center;font-size:72px;background:<?= admin_h($p['bg_color'] ?: '#EFF3EC') ?>"><?= admin_h($p['emoji'] ?: '📦') ?></div><?php endif; ?>
    <?php if (count($imgs) > 1): ?><div class="strip"><?php foreach ($imgs as $im): ?><img src="../<?= admin_h($im) ?>" alt="" onclick="document.getElementById('big').src=this.src"><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div>
    <h2 style="margin:0 0 6px"><?= admin_h($p['name']) ?></h2>
    <div><span class="price"><?= CURRENCY . number_format((float) $p['price'], 2) ?></span><?php if (!empty($p['original_price']) && $p['original_price'] > $p['price']): ?><span class="old"><?= CURRENCY . number_format((float) $p['original_price'], 2) ?></span><?php endif; ?></div>
    <dl>
      <dt>Seller</dt><dd><?= admin_h($p['seller']) ?></dd>
      <dt>Status</dt><dd><span class="pill"><?= admin_h($p['status'] ?? '') ?></span></dd>
      <dt>Stock</dt><dd><?= (int) $p['stock'] ?><?= (int) $p['stock'] <= 0 ? ' · out of stock' : '' ?></dd>
      <dt>Category</dt><dd><?= admin_h($p['category'] ?? '') ?><?= !empty($p['subcategory']) ? ' · ' . admin_h($p['subcategory']) : '' ?></dd>
      <?php if ($opt !== ''): ?><dt>Color / size</dt><dd><?= admin_h($opt) ?></dd><?php endif; ?>
      <dt>Flash deal</dt><dd><?= !empty($p['is_flash_deal']) ? 'Yes' : 'No' ?></dd>
    </dl>
    <?php if (!empty($p['description'])): ?><p style="white-space:pre-line;margin:0"><?= admin_h($p['description']) ?></p><?php endif; ?>
    <?php if ($others): ?><h3 style="margin:20px 0 8px">Other colors</h3><div class="strip"><?php foreach ($others as $o): ?><a href="product_view.php?id=<?= (int) $o['id'] ?>" style="text-align:center;font-size:12px"><?= aou_thumb($o['image'] ?? '', '📦', '', 64) ?><br><?= admin_h(trim($o['color'] . ' ' . $o['size'])) ?> · <?= (int) $o['stock'] ?></a><?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<?php require 'includes/footer.php';
