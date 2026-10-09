<?php
require 'config.php';
require 'includes/functions.php';
$page_title = 'Flash Deals';
require 'includes/header.php';

$flash_deals = $pdo->query("SELECT * FROM products WHERE is_flash_deal = 1 AND status='approved' AND " . dedupe_sql() . " ORDER BY created_at DESC")->fetchAll();
?>
<section class="wrap section">
  <div class="section-head">
    <div><span class="tag">⚡ Flash Deals</span><h2>All selected offers</h2></div>
  </div>
  <?php if ($flash_deals): ?>
    <div class="product-grid"><?php foreach ($flash_deals as $p): ?><?= render_product_card($p) ?><?php endforeach; ?></div>
  <?php else: ?>
    <p style="color:#9FB0A8;">No selected offers right now — check back soon.</p>
  <?php endif; ?>
</section>
<?php require 'includes/footer.php'; ?>
