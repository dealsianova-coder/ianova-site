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
$ro = !empty($_GET['from']) || strpos((string) ($_SERVER['HTTP_REFERER'] ?? ''), 'orders.php') !== false;   // opened from an order: view only
seller_head($p['name'], $ro ? 'orders.php' : 'products.php');
?>
<style>
.pvx{display:grid;gap:16px;max-width:980px}
.pvx .blk{background:#fff;border:1px solid #E9EBEF;border-radius:22px;padding:18px;box-shadow:0 10px 24px -18px rgba(15,15,18,.25)}
.pvx .top{display:grid;grid-template-columns:minmax(0,380px) 1fr;gap:16px}
.pvx .big{width:100%;aspect-ratio:1;object-fit:cover;border-radius:18px;background:#EEF1F6;display:block}
.pvx .strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.pvx .strip img{width:64px;height:64px;object-fit:cover;border-radius:12px;cursor:pointer;border:2px solid transparent}
.pvx .strip img:hover,.pvx .strip img.on{border-color:#0F0F12}
.pvx h2{margin:0 0 6px;font-size:24px}
.pvx .price{font-size:30px;font-weight:900}.pvx .old{color:#8A90A0;text-decoration:line-through;margin-left:10px}
.pvx .pill{display:inline-block;padding:4px 11px;border-radius:999px;font-size:12px;font-weight:800;background:#E7F6EC;color:#0B6B37}
.pvx .pill.warn{background:#FFF1D6;color:#9A5B00}.pvx .pill.bad{background:#FDE7EB;color:#B00F2F}
.pvx .tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px}
.pvx .tile{background:#F5F6FA;border-radius:18px;padding:14px}.pvx .tile small{display:block;color:#5F6575;font-size:12px;font-weight:700}.pvx .tile b{font-size:22px}
.pvx h3{margin:0 0 10px;font-size:15px}
.pvx dl{display:grid;grid-template-columns:130px 1fr;gap:10px 14px;margin:0}.pvx dt{color:#5F6575;font-size:13px}.pvx dd{margin:0;font-weight:700}
.pvx .opts{display:flex;gap:10px;flex-wrap:wrap}.pvx .opt{display:block;text-align:center;font-size:12px;font-weight:700;color:#0F0F12;text-decoration:none;background:#F5F6FA;border-radius:16px;padding:8px;min-width:84px}
.pvx .opt img{width:68px;height:68px;object-fit:cover;border-radius:12px;display:block;margin:0 auto 6px}
@media(max-width:720px){.pvx .top{grid-template-columns:1fr}}
</style>
<div class="actions" style="margin-bottom:14px"><a class="btn gray" href="javascript:history.back()">← Back</a><?php if (!$locked && !$ro): ?><a class="btn" href="product_edit.php?id=<?= $id ?>">Edit product</a><?php endif; ?></div>
<?php $pk = $p['status'] === 'approved' ? '' : ($p['status'] === 'pending' ? 'warn' : 'bad'); ?>
<div class="pvx">
  <div class="top">
    <div class="blk">
      <?php if (!empty($p['image'])): ?><img class="big" id="big" src="../<?= ops_h($p['image']) ?>" alt=""><?php else: ?><div class="big" style="display:flex;align-items:center;justify-content:center;font-size:72px;background:<?= ops_h($p['bg_color'] ?? '#EEF1F6') ?>"><?= ops_h($p['emoji'] ?? '📦') ?></div><?php endif; ?>
      <?php $ims = gallery_list((string) ($p['image'] ?? ''), array_column($gal, 'image')); if (count($ims) > 1): ?><div class="strip"><?php foreach ($ims as $im): ?><img src="../<?= ops_h($im) ?>" alt="" onclick="document.getElementById('big').src=this.src;document.querySelectorAll('.pvx .strip img').forEach(function(x){x.classList.remove('on')});this.classList.add('on')"><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="blk">
      <span class="pill <?= $pk ?>"><?= ops_h($labels[$p['status']] ?? $p['status']) ?></span><?= $locked ? ' <span class="pill bad">🔒 Locked by admin</span>' : '' ?>
      <h2 style="margin-top:10px"><?= ops_h($p['name']) ?></h2>
      <div><span class="price"><?= CURRENCY . number_format((float) $p['price'], 2) ?></span><?php if (!empty($p['original_price']) && $p['original_price'] > $p['price']): ?><span class="old"><?= CURRENCY . number_format((float) $p['original_price'], 2) ?></span><?php endif; ?></div>
      <div class="tiles">
        <div class="tile"><small>In stock</small><b><?= (int) $p['stock'] ?></b><?= (int) $p['stock'] <= 0 ? '<small style="color:#B00F2F">Out of stock</small>' : '' ?></div>
        <div class="tile"><small>Options</small><b><?= count($others) + 1 ?></b></div>
        <div class="tile"><small>Flash deal</small><b><?= !empty($p['is_flash_deal']) ? 'Yes' : 'No' ?></b></div>
      </div>
    </div>
  </div>
  <div class="blk"><h3>Details</h3><dl>
    <dt>Category</dt><dd><?= ops_h($p['category'] ?? '') ?><?= !empty($p['subcategory']) ? ' · ' . ops_h($p['subcategory']) : '' ?></dd>
    <?php if ($opt !== ''): ?><dt>Color / size</dt><dd><?= ops_h($opt) ?></dd><?php endif; ?>
  </dl></div>
  <?php if (!empty($p['description'])): ?><div class="blk"><h3>Description</h3><p style="white-space:pre-line;margin:0;line-height:1.5"><?= ops_h($p['description']) ?></p></div><?php endif; ?>
  <?php if ($others): ?><div class="blk"><h3>Other colors &amp; sizes</h3><div class="opts"><?php foreach ($others as $o): ?><a class="opt" href="product_view.php?id=<?= (int) $o['id'] ?><?= $ro ? '&from=order' : '' ?>"><?php if (!empty($o['image'])): ?><img src="../<?= ops_h($o['image']) ?>" alt=""><?php endif; ?><?= ops_h(trim($o['color'] . ' ' . $o['size'])) ?><br><span style="color:#5F6575;font-weight:600"><?= (int) $o['stock'] ?> in stock</span></a><?php endforeach; ?></div></div><?php endif; ?>
</div>
<?php seller_foot();
