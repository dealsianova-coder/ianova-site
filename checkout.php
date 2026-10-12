<?php
require 'config.php';
require 'includes/functions.php';
require_once __DIR__ . '/includes/seller_orders.php';
ensure_seller_orders($pdo);

$items = cart_items($pdo);
$error = null;
$success_order_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $items) {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '' || $email === '' || $address === '') {
        $error = 'Please fill in your name, email, and delivery address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $total = cart_total($pdo);
        $delivery = ($total > 0 && $total < free_delivery_value($pdo)) ? delivery_fee_value($pdo) : 0;
        $grand_total = round($total + $delivery, 2);

        try {
            $pdo->beginTransaction();

            // Lock the products and make sure everything is still in stock and approved.
            $ids = array_map(static fn($i) => (int) $i['product']['id'], $items);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $lock = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE id IN ($ph) AND status = 'approved' FOR UPDATE");
            $lock->execute($ids);
            $fresh = [];
            foreach ($lock->fetchAll() as $r) $fresh[(int) $r['id']] = $r;

            $total = 0;
            foreach ($items as $item) {
                $pid = (int) $item['product']['id'];
                if (!isset($fresh[$pid])) {
                    throw new OutOfStockException('"' . $item['product']['name'] . '" is no longer available.');
                }
                if ((int) $item['qty'] > (int) $fresh[$pid]['stock']) {
                    throw new OutOfStockException('Not enough stock for "' . $fresh[$pid]['name'] . '" (' . (int) $fresh[$pid]['stock'] . ' left). Please lower the quantity in your cart.');
                }
                $total += (float) $fresh[$pid]['price'] * (int) $item['qty'];
            }
            // Use the locked, current prices for the order total.
            $total = round($total, 2);
            $delivery = ($total > 0 && $total < free_delivery_value($pdo)) ? delivery_fee_value($pdo) : 0;
            $grand_total = round($total + $delivery, 2);

            $stmt = $pdo->prepare(
                "INSERT INTO orders (guest_name, guest_email, guest_phone, address, total, status)
                 VALUES (?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([$name, $email, $phone, $address, $grand_total]);
            $order_id = $pdo->lastInsertId();

            $item_stmt = $pdo->prepare(
                "INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)"
            );
            foreach ($items as $item) {
                $pid = (int) $item['product']['id'];
                $item_stmt->execute([$order_id, $pid, $item['qty'], $fresh[$pid]['price']]);
                $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$item['qty'], $pid]);
            }

            $pdo->commit();
            $success_order_id = $order_id;
            unset($_SESSION['cart']); // empty the cart after a successful order
            // Seller approval: auto-approve where sellers allow it, and notify sellers/admin.
            try {
                require_once __DIR__ . '/includes/seller_orders.php';
                seller_orders_process($pdo, (int) $order_id, true);
            } catch (Throwable $hookError) {
                error_log('seller order hook: ' . $hookError->getMessage());
            }
        } catch (OutOfStockException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Something went wrong placing your order. Please try again.';
        }
    }
}

$page_title = 'Checkout';
require 'includes/header.php';
?>

<div class="page-head">
  <div class="wrap">
    <div class="breadcrumb"><a href="index.php">Home</a> / <a href="cart.php">Cart</a> / Checkout</div>
    <span class="tag">Checkout</span>
    <h1>Delivery details</h1>
  </div>
</div>

<section class="section">
  <div class="wrap">
    <?php if ($success_order_id): ?>
      <div class="empty-state">
        <h3>✓ Order placed — thank you!</h3>
        <p>Your order <strong>#<?= (int) $success_order_id ?></strong> has been received and is being prepared. A confirmation has been sent to your email.</p>
        <br>
        <a href="index.php" class="btn btn-amber">Continue shopping</a>
      </div>
    <?php elseif (!$items): ?>
      <div class="empty-state">
        <h3>Your cart is empty</h3>
        <p>Add something to your cart before checking out.</p>
        <br>
        <a href="index.php" class="btn btn-amber">Start shopping</a>
      </div>
    <?php else: ?>
      <div class="cart-layout">
        <div>
          <?php if ($error): ?>
            <p class="flash-msg" style="color:var(--coral);margin-bottom:16px;"><?= h($error) ?></p>
          <?php endif; ?>
          <form method="post" style="display:flex;flex-direction:column;gap:14px;max-width:480px;">
            <div>
              <label style="font-size:13px;font-weight:600;">Full name</label><br>
              <input type="text" name="name" required value="<?= h($_POST['name'] ?? '') ?>"
                     style="width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:6px;">
            </div>
            <div>
              <label style="font-size:13px;font-weight:600;">Email</label><br>
              <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"
                     style="width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:6px;">
            </div>
            <div>
              <label style="font-size:13px;font-weight:600;">Phone</label><br>
              <input type="tel" name="phone" value="<?= h($_POST['phone'] ?? '') ?>"
                     style="width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:6px;">
            </div>
            <div>
              <label style="font-size:13px;font-weight:600;">Delivery address</label><br>
              <textarea name="address" required rows="3"
                        style="width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:6px;font-family:inherit;"><?= h($_POST['address'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-amber" style="justify-content:center;">Place order</button>
          </form>
        </div>

        <div class="summary-card">
          <h3>Order summary</h3>
          <?php foreach ($items as $item): ?>
            <div class="summary-row"><span><?= h($item['product']['name']) ?> × <?= (int) $item['qty'] ?></span><span class="mono"><?= CURRENCY . number_format($item['subtotal'], 2) ?></span></div>
          <?php endforeach; ?>
          <div class="summary-row total"><span>Total</span><span><?= CURRENCY . number_format(cart_total($pdo), 2) ?></span></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
