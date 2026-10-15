<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/gallery.php';
$id = (int) ($_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.seller_id = ?');
$q->execute([$id, $seller_id]);
$p = $q->fetch();
if (!$p) { header('Location: products.php'); exit; }
$gal = gallery_rows($pdo, $id);
$sq = $pdo->prepare('SELECT id, color, size, stock, price, image FROM products WHERE seller_id = ? AND category_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? ORDER BY id');
$sq->execute([$seller_id, $p['category_id'], $p['name'], $id]);
$others = $sq->fetchAll();
$locked = ops_product_locked($p);
$opt = trim(($p['color'] ?? '') . ' ' . ($p['size'] ?? ''));
$labels = ['approved' => 'Approved', 'pending' => 'Waiting for review', 'rejected' => 'Rejected', 'hidden' => 'Hidden by admin'];
seller_head($p['name'], 'products.php');
?>
<style>.pv{display:grid;grid-template-columns:minmax(0,340px) 1fr;gap:22px}.pv .big{width:100%;aspect-ratio:1;object-fit:cover;border-radius:16px;background:#eef3ef;display:block}.pv .strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.pv .strip img{width:64px;height:64px;object-fit:cover;border-radius:10px;cursor:pointer;border:2px solid transparent}.pv dl{display:grid;grid-template-columns:130px 1fr;gap:8px 14px;margin:14px 0}.pv dt{color:#6b7973;font-size:13px}.pv dd{margin:0;font-weight:600}.pv .price{font-size:26px;font-weight:800}.pv .old{color:#6b7973;text-decoration:line-through;font-size:15px;margin-left:8px}@media(max-width:700px){.pv{grid-template-columns:1fr}}</style>
<div class="actions" style="margin-bottom:14px"><a class="btn gray" href="javascript:history.back()">← Back</a><?php if (!$locked): ?><a class="btn" href="product_edit.php?id=<?= $id ?>">Edit product</a><?php endif; ?></div>
<div class="card pv">
  <div>
    <?php if (!empty($p['image'])): ?><img class="big" id="big" src="../<?= ops_h($p['image']) ?>" alt=""><?php else: ?><div class="big" style="display:flex;align-items:center;justify-content:center;font-size:72px;background:<?= ops_h($p['bg_color'] ?: '#EFF3EC') ?>"><?= ops_h($p['emoji'] ?: '📦') ?></div><?php endif; ?>
    <?php if (!empty($p['image']) || $gal): ?><div class="strip"><?php foreach (gallery_list((string) ($p['image'] ?? ''), array_column($gal, 'image')) as $im): ?><img src="../<?= ops_h($im) ?>" alt="" onclick="document.getElementById('big').src=this.src"><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div>
    <h2 style="margin:0 0 6px"><?= ops_h($p['name']) ?></h2>
    <div><span class="price"><?= CURRENCY . number_format((float) $p['price'], 2) ?></span><?php if (!empty($p['original_price']) && $p['original_price'] > $p['price']): ?><span class="old"><?= CURRENCY . number_format((float) $p['original_price'], 2) ?></span><?php endif; ?></div>
    <dl>
      <dt>Status</dt><dd><span class="pill"><?= ops_h($labels[$p['status']] ?? $p['status']) ?></span><?= $locked ? ' 🔒 Locked by admin' : '' ?></dd>
      <dt>Stock</dt><dd><?= (int) $p['stock'] ?><?= (int) $p['stock'] <= 0 ? ' · out of stock' : '' ?></dd>
      <dt>Category</dt><dd><?= ops_h($p['category'] ?? '') ?><?= !empty($p['subcategory']) ? ' · ' . ops_h($p['subcategory']) : '' ?></dd>
      <?php if ($opt !== ''): ?><dt>Color / size</dt><dd><?= ops_h($opt) ?></dd><?php endif; ?>
      <dt>Flash deal</dt><dd><?= !empty($p['is_flash_deal']) ? 'Yes' : 'No' ?></dd>
    </dl>
    <?php if (!empty($p['description'])): ?><p style="white-space:pre-line;margin:0"><?= ops_h($p['description']) ?></p><?php endif; ?>
    <?php if ($others): ?><h3 style="margin:20px 0 8px">Other colors</h3><div class="strip"><?php foreach ($others as $o): ?><a href="product_view.php?id=<?= (int) $o['id'] ?>" style="text-align:center;font-size:12px"><?php if (!empty($o['image'])): ?><img src="../<?= ops_h($o['image']) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:10px;display:block"><?php endif; ?><?= ops_h(trim($o['color'] . ' ' . $o['size'])) ?> · <?= (int) $o['stock'] ?></a><?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<?php seller_foot();
