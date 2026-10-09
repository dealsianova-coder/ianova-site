<?php
require 'config.php';
require 'includes/functions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=account.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$orders = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC');
$orders->execute([$user_id]);
$orders = $orders->fetchAll();

$status_labels = [
    'pending'   => 'Pending',
    'approved'  => 'Approved',
    'paid'      => 'Paid',
    'shipped'   => 'Shipped',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
];

$page_title = 'My account';
require 'includes/header.php';
?>

<section class="wrap" style="max-width:760px;margin:40px auto;">
  <h1 style="margin-top:0;">Hello, <?= h($user['name']) ?></h1>
  <p style="color:var(--muted);margin-top:-8px;"><?= h($user['email']) ?></p>

  <h2 style="margin-top:32px;font-size:19px;">My orders</h2>

  <?php if (!$orders): ?>
    <p style="color:var(--muted);">You haven't placed any orders yet.</p>
    <a class="btn btn-amber" href="index.php">Start shopping</a>
  <?php else: ?>
    <div class="table-wrap" style="overflow:auto;border:1px solid var(--line);border-radius:14px;">
      <table style="width:100%;border-collapse:collapse;min-width:560px;">
        <tr style="background:#F7F9F8;">
          <th style="text-align:left;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Order</th>
          <th style="text-align:left;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Date</th>
          <th style="text-align:left;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Total</th>
          <th style="text-align:left;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Status</th>
          <th style="text-align:left;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Manage</th>
        </tr>
        <?php foreach ($orders as $o): ?>
          <tr style="border-top:1px solid var(--line);">
            <td style="padding:12px 14px;">#<?= (int) $o['id'] ?></td>
            <td style="padding:12px 14px;"><?= h($o['created_at']) ?></td>
            <td style="padding:12px 14px;"><?= CURRENCY . number_format($o['total'], 2) ?></td>
            <td style="padding:12px 14px;">
              <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#E7F0EB;font-size:12.5px;font-weight:600;">
                <?= h($status_labels[$o['status']] ?? ucfirst($o['status'])) ?>
              </span>
            </td>
            <td style="padding:12px 14px;">
              <?php if ($o['status'] === 'pending'): ?>
                <a href="order_manage.php?id=<?= (int) $o['id'] ?>" style="color:var(--emerald);font-weight:700;">Manage</a>
              <?php else: ?>
                <span style="color:#9FB0A8;">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>

  <p style="margin-top:28px;"><a href="logout.php" style="color:var(--muted);">Sign out</a></p>
</section>

<?php require 'includes/footer.php'; ?>
