<?php
require 'config.php';
require 'includes/functions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=saved.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT p.* FROM products p
     INNER JOIN wishlist w ON w.product_id = p.id
     WHERE w.user_id = ?
     ORDER BY w.created_at DESC'
);
$stmt->execute([$user_id]);
$saved_products = $stmt->fetchAll();

$page_title = 'Saved items';
require 'includes/header.php';
?>

<section class="wrap" style="max-width:1240px;margin:32px auto;padding:0 16px;">
  <h1 style="margin-top:0;">Saved items</h1>

  <?php if (!$saved_products): ?>
    <p style="color:var(--muted);">You haven't saved anything yet. Tap the heart on any product to save it here.</p>
    <a class="btn btn-amber" href="index.php">Start browsing</a>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($saved_products as $p): ?>
        <?= render_product_card($p) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>
