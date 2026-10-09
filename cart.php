<?php
require 'config.php';
require 'includes/functions.php';

$items = cart_items($pdo);
$total = cart_total($pdo);
$delivery = ($total > 0 && $total < free_delivery_value($pdo)) ? delivery_fee_value($pdo) : 0;
$grand_total = round($total + $delivery, 2);

$page_title = 'Your cart';
require 'includes/header.php';
?>

<div class="page-head">
  <div class="wrap">
    <div class="breadcrumb"><a href="index.php">Home</a> / Cart</div>
    <span class="tag">Cart</span>
    <h1>Your cart<?= $items ? ' (' . cart_count() . ' item' . (cart_count() == 1 ? '' : 's') . ')' : '' ?></h1>
  </div>
</div>

<section class="section">
  <div class="wrap">
    <?php if (!$items): ?>
      <div class="empty-state">
        <h3>Your cart is empty</h3>
        <p>Browse categories or today's flash deals to find something you'll love.</p>
        <br>
        <a href="index.php" class="btn btn-amber">Start shopping</a>
      </div>
    <?php else: ?>
      <div class="cart-layout">
        <div class="cart-list">
          <?php foreach ($items as $item): $p = $item['product']; ?>
            <div class="cart-row">
              <div class="thumb" style="background:<?= h($p['bg_color']) ?>;"><?= h($p['emoji']) ?></div>
              <div>
                <h4><a href="product.php?slug=<?= h($p['slug']) ?>"><?= h($p['name']) ?></a></h4>
                <span class="muted"><?= CURRENCY . number_format($p['price'], 2) ?> each</span>
              </div>
              <form method="post" action="cart_action.php" class="qty-form">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="redirect" value="cart.php">
                <input type="number" name="qty" value="<?= (int) $item['qty'] ?>" min="1" max="<?= (int) $p['stock'] ?>">
                <button type="submit">Update</button>
              </form>
              <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                <span class="line-total"><?= CURRENCY . number_format($item['subtotal'], 2) ?></span>
                <form method="post" action="cart_action.php">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                  <input type="hidden" name="redirect" value="cart.php">
                  <button type="submit" class="remove-btn">Remove</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="summary-card">
          <h3>Order summary</h3>
          <div class="summary-row"><span>Subtotal</span><span class="mono"><?= CURRENCY . number_format($total, 2) ?></span></div>
          <div class="summary-row">
            <span>Delivery</span>
            <span class="mono"><?= $delivery > 0 ? CURRENCY . number_format($delivery, 2) : 'Free' ?></span>
          </div>
          <?php if ($delivery > 0): ?>
            <p class="muted" style="font-size:12px;">Add <?= CURRENCY . number_format(free_delivery_value($pdo) - $total, 2) ?> more for free delivery.</p>
          <?php endif; ?>
          <div class="summary-row total"><span>Total</span><span><?= CURRENCY . number_format($grand_total, 2) ?></span></div>
          <a href="checkout.php" class="btn btn-amber">Proceed to checkout</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
