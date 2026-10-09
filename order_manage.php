<?php
require 'config.php';
require 'includes/functions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=account.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$order_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: account.php');
    exit;
}

if ($order['status'] !== 'pending') {
    // Order already being processed — no longer editable.
    header('Location: account.php');
    exit;
}

$items_stmt = $pdo->prepare(
    'SELECT oi.*, p.name, p.image, p.emoji, p.bg_color, p.stock
     FROM order_items oi JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id = ?'
);
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'")
            ->execute([$order_id, $user_id]);
        header('Location: account.php?cancelled=1');
        exit;
    }

    if ($action === 'save') {
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $qtys = $_POST['qty'] ?? [];

        if ($address === '') {
            $error = 'Delivery address is required.';
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare('UPDATE orders SET guest_phone = ?, address = ? WHERE id = ? AND user_id = ?')
                    ->execute([$phone, $address, $order_id, $user_id]);

                $new_total = 0;
                foreach ($items as $item) {
                    $q = max(0, (int) ($qtys[$item['id']] ?? $item['quantity']));
                    if ($q === 0) {
                        $pdo->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?')
                            ->execute([$item['id'], $order_id]);
                        continue;
                    }
                    $pdo->prepare('UPDATE order_items SET quantity = ? WHERE id = ? AND order_id = ?')
                        ->execute([$q, $item['id'], $order_id]);
                    $new_total += $q * $item['unit_price'];
                }

                // If every item was removed, cancel the whole order instead of leaving it empty.
                $remaining = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = ?');
                $remaining->execute([$order_id]);
                if ((int) $remaining->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$order_id]);
                    $pdo->commit();
                    header('Location: account.php?cancelled=1');
                    exit;
                }

                $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?')->execute([$new_total, $order_id]);
                $pdo->commit();
                header('Location: account.php?updated=1');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Could not update your order. Please try again.';
            }
        }

        // Refresh items after a failed attempt so the form shows current data.
        $items_stmt->execute([$order_id]);
        $items = $items_stmt->fetchAll();
    }
}

$page_title = 'Manage order #' . $order_id;
require 'includes/header.php';
?>
<section class="wrap" style="max-width:640px;margin:40px auto;">
  <h1 style="margin-top:0;">Manage order #<?= (int) $order['id'] ?></h1>
  <p style="color:var(--muted);margin-top:-8px;">You can edit or cancel this order while it's still pending.</p>

  <?php if ($error): ?>
    <p style="background:#fdeaea;color:#8a2424;padding:12px 14px;border-radius:9px;"><?= h($error) ?></p>
  <?php endif; ?>

  <form method="post" style="margin-top:24px;">
    <input type="hidden" name="action" value="save">

    <div style="margin-bottom:16px;">
      <label style="font-size:13px;font-weight:600;">Phone</label><br>
      <input type="tel" name="phone" value="<?= h($order['guest_phone'] ?? '') ?>"
             style="width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:6px;">
    </div>
    <div style="margin-bottom:20px;">
      <label style="font-size:13px;font-weight:600;">Delivery address</label><br>
      <textarea name="address" required rows="3"
                style="width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:6px;font-family:inherit;"><?= h($order['address']) ?></textarea>
    </div>

    <h3 style="font-size:16px;">Items</h3>
    <div style="border:1px solid var(--line);border-radius:12px;overflow:hidden;">
      <?php foreach ($items as $item): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid var(--line);">
          <div style="flex:1;">
            <div style="font-weight:700;"><?= h($item['name']) ?></div>
            <div style="color:var(--muted);font-size:13px;"><?= CURRENCY . number_format($item['unit_price'], 2) ?> each</div>
          </div>
          <input type="number" name="qty[<?= (int) $item['id'] ?>]" value="<?= (int) $item['quantity'] ?>" min="0" max="<?= max((int)$item['quantity'], (int)$item['stock']) ?>"
                 style="width:70px;padding:9px;border:1px solid var(--line);border-radius:8px;text-align:center;">
        </div>
      <?php endforeach; ?>
    </div>
    <p style="color:var(--muted);font-size:12.5px;margin-top:6px;">Set an item's quantity to 0 to remove it. Removing all items cancels the order.</p>

    <div style="display:flex;gap:12px;margin-top:24px;">
      <button type="submit" class="btn btn-amber">Save changes</button>
      <a href="account.php" class="btn" style="background:#eee;color:#333;">Back</a>
    </div>
  </form>

  <form method="post" onsubmit="return confirm('Cancel this entire order?');" style="margin-top:20px;">
    <input type="hidden" name="action" value="cancel">
    <button type="submit" style="background:none;border:0;color:#c0392b;font-weight:700;cursor:pointer;padding:0;">Cancel this order</button>
  </form>
</section>
<?php require 'includes/footer.php'; ?>
