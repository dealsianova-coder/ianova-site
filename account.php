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

require_once __DIR__ . '/includes/auth_security.php';
$pw_msg = ''; $pw_ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $wait = auth_locked($pdo, 'user', $user['email']);
    if (!auth_csrf_ok()) $pw_msg = 'Your page expired. Please try again.';
    elseif ($wait) $pw_msg = auth_lock_message($wait);
    elseif (!password_verify((string) ($_POST['current'] ?? ''), (string) $user['password_hash'])) { auth_record($pdo, 'user', $user['email']); $pw_msg = 'Your current password is not correct.'; }
    elseif (($pe = auth_password_error((string) ($_POST['password'] ?? ''))) !== null) $pw_msg = $pe;
    elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) $pw_msg = 'The two new passwords do not match.';
    else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $user_id]);
        try { $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$user_id]); } catch (Throwable $e) {}
        session_regenerate_id(true); auth_clear($pdo, 'user', $user['email']);
        $pw_ok = true; $pw_msg = 'Password changed.';
    }
}

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

  <details style="margin-top:22px;border:1px solid var(--line);border-radius:18px;background:#fff;padding:14px 18px;"<?= $pw_msg ? ' open' : '' ?>>
    <summary style="cursor:pointer;font-weight:800;">Change password</summary>
    <?php if ($pw_msg): ?><div style="margin:12px 0;padding:11px 14px;border-radius:14px;background:<?= $pw_ok ? '#EAF8F0' : '#FFF1F4' ?>;color:<?= $pw_ok ? '#136B3A' : '#B0102F' ?>;"><?= h($pw_msg) ?></div><?php endif; ?>
    <form method="post" style="margin-top:12px;max-width:380px;">
      <?= auth_csrf_field() ?><input type="hidden" name="action" value="password">
      <label style="display:block;font-size:13px;font-weight:700;margin:0 0 6px;">Current password</label>
      <input type="password" name="current" required autocomplete="current-password" style="width:100%;padding:11px;margin-bottom:12px;border:1px solid #ccd7d1;border-radius:12px;font:inherit;">
      <label style="display:block;font-size:13px;font-weight:700;margin:0 0 6px;">New password</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password" style="width:100%;padding:11px;margin-bottom:4px;border:1px solid #ccd7d1;border-radius:12px;font:inherit;">
      <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">At least 8 characters, with a letter and a number.</div>
      <label style="display:block;font-size:13px;font-weight:700;margin:0 0 6px;">Confirm new password</label>
      <input type="password" name="password2" required autocomplete="new-password" style="width:100%;padding:11px;margin-bottom:14px;border:1px solid #ccd7d1;border-radius:12px;font:inherit;">
      <button type="submit" style="padding:12px 22px;border:0;border-radius:999px;background:#0B1B3F;color:#fff;font-weight:800;cursor:pointer;">Save new password</button>
    </form>
  </details>

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
