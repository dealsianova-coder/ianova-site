<?php
require 'config.php';
require 'includes/functions.php';

$slug = isset($_GET['slug']) ? $_GET['slug'] : '';
require_once __DIR__.'/includes/category_images.php'; cat_img_ensure($pdo);
$stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = ? AND " . display_sql());
$stmt->execute([$slug]);
$category = $stmt->fetch();

if (!$category) {
    http_response_code(404);
    $page_title = 'Category not found';
    require 'includes/header.php';
    echo '<div class="wrap section"><div class="empty-state"><h3>Category not found</h3><p>That category doesn\'t exist. <a href="index.php">Back to home</a></p></div></div>';
    require 'includes/footer.php';
    exit;
}

$sort = $_GET['sort'] ?? 'newest';
$order_sql = match ($sort) {
    'price_low'  => 'price ASC',
    'price_high' => 'price DESC',
    'rating'     => 'rating DESC',
    default      => 'created_at DESC',
};

$stmt = $pdo->prepare("SELECT * FROM products WHERE category_id = ? AND status='approved' AND " . dedupe_sql() . " ORDER BY $order_sql");
$stmt->execute([$category['id']]);
$products = $stmt->fetchAll();

$page_title = $category['name'];
require 'includes/header.php';
?>

<div class="page-head">
  <div class="wrap">
    <div class="breadcrumb"><a href="index.php">Home</a> / <?= h($category['name']) ?></div>
    <span class="tag">Category</span>
    <h1><?php if(!empty($category['image'])):?><img src="<?= h($category['image']) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:12px;vertical-align:middle"><?php else:?><?= h($category['emoji']) ?><?php endif;?> <?= h($category['name']) ?></h1>
  </div>
</div>

<section class="section">
  <div class="wrap">
    <div class="section-head">
      <span class="muted"><?= count($products) ?> product<?= count($products) == 1 ? '' : 's' ?></span>
      <form method="get" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="slug" value="<?= h($slug) ?>">
        <label for="sort" class="muted" style="font-size:13px;">Sort:</label>
        <select id="sort" name="sort" onchange="this.form.submit()" style="padding:9px 12px;border-radius:8px;border:1px solid var(--line);font-size:13px;">
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
          <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: low to high</option>
          <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: high to low</option>
          <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Top rated</option>
        </select>
      </form>
    </div>

    <?php if ($products): ?>
      <div class="product-grid">
        <?php foreach ($products as $p) echo render_product_card($p); ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <h3>Nothing here yet</h3>
        <p>New stock lands in this category soon — try browsing another category in the meantime.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
