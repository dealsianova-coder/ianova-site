<?php
require 'config.php';
require 'includes/functions.php';

$slug = isset($_GET['slug']) ? $_GET['slug'] : '';
$stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p JOIN categories c ON c.id = p.category_id WHERE p.slug = ?");
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
    <div class="pd-media" style="background:<?= h($product['bg_color']) ?>;">
      <?= h($product['emoji']) ?>
    </div>
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
          $oq = $pdo->prepare("SELECT * FROM products WHERE status='approved' AND category_id = ? AND COALESCE(seller_id,0) = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY color, size, id");
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
