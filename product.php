<?php
require 'config.php';
require 'includes/functions.php';

$slug = isset($_GET['slug']) ? $_GET['slug'] : '';
$stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p JOIN categories c ON c.id = p.category_id WHERE p.slug = ? AND " . display_sql('p'));
$stmt->execute([$slug]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    $page_title = 'Product not found';
    require 'includes/header.php';
    echo '<div class="wrap section"><div class="empty-state"><h3>Product not found</h3><p>This item may have sold out or been removed. <a href="index.php">Back to home</a></p></div></div>';
    require 'includes/footer.php';
    exit;
}

// A few related products from the same category
$stmt = $pdo->prepare("SELECT * FROM products WHERE category_id = ? AND id != ? AND status='approved' AND LOWER(TRIM(name)) <> LOWER(TRIM(?)) AND " . dedupe_sql() . " ORDER BY RAND() LIMIT 4");
$stmt->execute([$product['category_id'], $product['id'], $product['name']]);
$related = $stmt->fetchAll();

$discount = discount_percent($product['price'], $product['original_price']);
$page_title = $product['name'];
require 'includes/header.php';
?>

<div class="page-head">
  <div class="wrap">
    <div class="breadcrumb">
      <a href="index.php">Home</a> /
      <a href="category.php?slug=<?= h($product['category_slug']) ?>"><?= h($product['category_name']) ?></a> /
      <?= h($product['name']) ?>
    </div>
  </div>
</div>

<section class="section">
  <div class="wrap pd-layout">
    <?php
    require_once 'includes/gallery.php';
    gallery_ensure($pdo);
    $galMain = (string) ($product['image'] ?? ($product['image_url'] ?? ''));
    $galExtra = gallery_for($pdo, [(int) $product['id']]);
    $galImgs = gallery_list($galMain, $galExtra[(int) $product['id']] ?? []);
    $galN = count($galImgs);
    ?>
    <?php if ($galN === 0): ?>
    <div class="pd-media" style="background:<?= h($product['bg_color']) ?>;">
      <?= h($product['emoji']) ?>
    </div>
    <?php else: ?>
    <style>
      .pd-gal{position:relative;border-radius:var(--r-xl);overflow:hidden;border:1px solid var(--line);background:<?= h($product['bg_color']) ?>}
      .pd-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;aspect-ratio:1/1}
      .pd-track::-webkit-scrollbar{display:none}
      .pd-track img{flex:0 0 100%;width:100%;height:100%;object-fit:cover;scroll-snap-align:center}
      .pd-nav{position:absolute;top:50%;transform:translateY(-50%);width:42px;height:42px;border-radius:50%;border:0;background:rgba(255,255,255,.92);box-shadow:0 2px 10px rgba(0,0,0,.18);font-size:24px;line-height:1;cursor:pointer}
      .pd-nav.prev{left:12px}.pd-nav.next{right:12px}
      .pd-count{position:absolute;right:12px;bottom:12px;background:rgba(0,0,0,.55);color:#fff;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:700}
      .pd-thumbs{display:flex;gap:10px;margin-top:12px;overflow-x:auto}
      .pd-thumb{flex:0 0 68px;height:68px;border-radius:12px;overflow:hidden;border:2px solid transparent;padding:0;background:none;cursor:pointer;opacity:.7}
      .pd-thumb img{width:100%;height:100%;object-fit:cover;display:block}
      .pd-thumb.on{border-color:var(--ink,#0f0f12);opacity:1}
    </style>
    <div class="pd-media-wrap">
      <div class="pd-gal">
        <div class="pd-track" id="pdTrack">
          <?php foreach ($galImgs as $gi): ?><img src="<?= h($gi) ?>" alt="<?= h($product['name']) ?>"><?php endforeach; ?>
        </div>
        <?php if ($galN > 1): ?>
        <button type="button" class="pd-nav prev" aria-label="Previous photo">&#8249;</button>
        <button type="button" class="pd-nav next" aria-label="Next photo">&#8250;</button>
        <div class="pd-count" id="pdCount">1 / <?= $galN ?></div>
        <?php endif; ?>
      </div>
      <?php if ($galN > 1): ?>
      <div class="pd-thumbs">
        <?php foreach ($galImgs as $gk => $gi): ?><button type="button" class="pd-thumb<?= $gk === 0 ? ' on' : '' ?>" aria-label="Photo <?= $gk + 1 ?>"><img src="<?= h($gi) ?>" alt=""></button><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <script>
    (function(){
      var t=document.getElementById('pdTrack'); if(!t) return;
      var n=t.children.length, c=document.getElementById('pdCount'), th=document.querySelectorAll('.pd-thumb');
      function cur(){return Math.round(t.scrollLeft/t.clientWidth);}
      function go(i){i=Math.max(0,Math.min(n-1,i));t.scrollTo({left:i*t.clientWidth,behavior:'smooth'});}
      t.addEventListener('scroll',function(){var i=cur(); if(c)c.textContent=(i+1)+' / '+n; th.forEach(function(e,k){e.classList.toggle('on',k===i);});});
      var p=document.querySelector('.pd-nav.prev'), x=document.querySelector('.pd-nav.next');
      if(p)p.onclick=function(){go(cur()-1);}; if(x)x.onclick=function(){go(cur()+1);};
      th.forEach(function(e,k){e.onclick=function(){go(k);};});
    })();
    </script>
    <?php endif; ?>
    <div class="pd-info">
      <span class="cat"><?= h($product['category_name']) ?> · sold by <?= h($product['seller_name']) ?></span>
      <h1><?= h($product['name']) ?></h1>
      <div class="stars"><?= star_string($product['rating']) ?> <span class="muted"><?= (float) $product['rating'] ?> · <?= (int) $product['review_count'] ?> reviews</span></div>

      <div class="pd-price">
        <span class="now"><?= CURRENCY . number_format($product['price'], 2) ?></span>
        <?php if ($product['original_price']): ?>
          <span class="was"><?= CURRENCY . number_format($product['original_price'], 2) ?></span>
          <span class="save">Save <?= $discount ?>%</span>
        <?php endif; ?>
      </div>

      <?php
      $options = [];
      try {
          $oq = $pdo->prepare("SELECT * FROM products WHERE status='approved' AND category_id = ? AND COALESCE(seller_id,0) = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND " . display_sql() . " ORDER BY color, size, id");
          $oq->execute([$product['category_id'], (int) ($product['seller_id'] ?? 0), $product['name']]);
          $options = $oq->fetchAll();
      } catch (Throwable $e) { $options = []; }
      $curColor = trim((string) ($product['color'] ?? ''));
      $curSize = trim((string) ($product['size'] ?? ''));
      $byColor = []; $bySize = [];
      foreach ($options as $o) {
          if (trim((string) ($o['color'] ?? '')) !== '') $byColor[trim($o['color'])][] = $o;
          if (trim((string) ($o['size'] ?? '')) !== '') $bySize[trim($o['size'])][] = $o;
      }
      ?>
      <?php if (count($byColor) > 1): ?>
      <div class="opt-group"><div class="opt-label">Color: <b><?= h($curColor) ?></b></div><div class="opt-chips">
        <?php foreach ($byColor as $name => $list): $name = (string) $name; $target = option_target($list, 'size', $curSize); $any = false; foreach ($list as $o) { if ((int) $o['stock'] > 0) $any = true; } $hex = color_hex($name); ?>
          <?php if ($any): ?><a class="opt-chip<?= $name === $curColor ? ' is-active' : '' ?>" href="product.php?slug=<?= h($target['slug']) ?>"><?php if ($hex): ?><i class="opt-dot" style="background:<?= h($hex) ?>"></i><?php endif; ?><?= h($name) ?></a>
          <?php else: ?><span class="opt-chip is-out" title="Out of stock"><?php if ($hex): ?><i class="opt-dot" style="background:<?= h($hex) ?>"></i><?php endif; ?><?= h($name) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </div></div>
      <?php endif; ?>
      <?php if (count($bySize) > 1): ?>
      <div class="opt-group"><div class="opt-label">Size: <b><?= h($curSize) ?></b></div><div class="opt-chips">
        <?php foreach ($bySize as $name => $list): $name = (string) $name; $target = option_target($list, 'color', $curColor); $any = false; foreach ($list as $o) { if ((int) $o['stock'] > 0) $any = true; } ?>
          <?php if ($any): ?><a class="opt-chip<?= $name === $curSize ? ' is-active' : '' ?>" href="product.php?slug=<?= h($target['slug']) ?>"><?= h($name) ?></a>
          <?php else: ?><span class="opt-chip is-out" title="Out of stock"><?= h($name) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </div></div>
      <?php endif; ?>

      <p class="desc"><?= h($product['description'] ?: 'No description provided for this product yet.') ?></p>

      <div class="pd-meta">
        <span><b><?= $product['stock'] > 0 ? $product['stock'] . ' in stock' : 'Out of stock' ?></b></span>
        <span>Free delivery over <?= CURRENCY . FREE_DELIVERY_THRESHOLD ?></span>
        <span>7-day returns</span>
      </div>

      <?php if ($product['stock'] > 0): ?>
        <form method="post" action="cart_action.php" class="pd-form">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
          <input type="hidden" name="redirect" value="product.php?slug=<?= h($product['slug']) ?>">
          <input type="number" name="qty" value="1" min="1" max="<?= (int) $product['stock'] ?>" style="width:70px;padding:12px;border:1px solid var(--line);border-radius:8px;">
          <button type="submit" class="btn btn-amber">Add to cart</button>
        </form>
      <?php else: ?>
        <button class="btn btn-ghost" style="color:var(--ink);border-color:var(--line);" disabled>Out of stock</button>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="section" style="padding-top:0;">
  <div class="wrap">
    <div class="section-head"><div><span class="tag">You might also like</span><h2>More from <?= h($product['category_name']) ?></h2></div></div>
    <div class="product-grid">
      <?php foreach ($related as $p) echo render_product_card($p); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
