<?php
require 'config.php';
require 'includes/functions.php';

$q = trim($_GET['q'] ?? '');
$products = [];

if ($q !== '') {
    // Try a natural-language fulltext search first.
    $stmt = $pdo->prepare(
        "SELECT * FROM products
         WHERE MATCH(name, description) AGAINST (? IN NATURAL LANGUAGE MODE)
         AND status='approved' AND " . dedupe_sql() . " ORDER BY created_at DESC"
    );
    $stmt->execute([$q]);
    $products = $stmt->fetchAll();

    // Fulltext needs a few characters of real word match; fall back to a
    // simple LIKE search for short or partial queries so results still show.
    if (!$products) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE (name LIKE ? OR description LIKE ?) AND status='approved' AND " . dedupe_sql() . " ORDER BY created_at DESC");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        $products = $stmt->fetchAll();
    }
}

$page_title = $q !== '' ? "Results for \"$q\"" : 'Search';
require 'includes/header.php';
?>

<div class="page-head">
  <div class="wrap">
    <div class="breadcrumb"><a href="index.php">Home</a> / Search</div>
    <span class="tag">Search</span>
    <h1><?= $q !== '' ? 'Results for "' . h($q) . '"' : 'Search IANOVA' ?></h1>
  </div>
</div>

<section class="section">
  <div class="wrap">
    <?php if ($q === ''): ?>
      <div class="empty-state">
        <h3>What are you looking for?</h3>
        <p>Use the search bar above to find products across every category.</p>
      </div>
    <?php elseif ($products): ?>
      <p class="muted" style="margin-bottom:20px;"><?= count($products) ?> result<?= count($products) == 1 ? '' : 's' ?></p>
      <div class="product-grid">
        <?php foreach ($products as $p) echo render_product_card($p); ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <h3>No results for "<?= h($q) ?>"</h3>
        <p>Try a different search term, or browse categories from the home page.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
