<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $id = (int) $_POST['id'];
    $action = $_POST['status_action'] ?? '';
    if (in_array($action, ['approved', 'rejected', 'suspended'], true)) {
        $cur = $pdo->prepare('SELECT business_name, email, status FROM sellers WHERE id = ?');
        $cur->execute([$id]);
        $seller = $cur->fetch();

        $pdo->prepare('UPDATE sellers SET status = ? WHERE id = ?')->execute([$action, $id]);

        // Email the seller when they are approved, or when a pending application is rejected.
        if ($seller && (($action === 'approved' && $seller['status'] !== 'approved') || ($action === 'rejected' && $seller['status'] === 'pending'))) {
            $sent = send_seller_status_email($seller['email'], $seller['business_name'], $action);
            $_SESSION['seller_mail_note'] = $sent
                ? 'Email sent to ' . $seller['email'] . '.'
                : 'Status saved, but the email to ' . $seller['email'] . ' could not be sent. Check the Gmail app password in config.local.php.';
        }
    }
    header('Location: sellers.php');
    exit;
}

$sellers = $pdo->query('SELECT s.*, COUNT(p.id) products FROM sellers s LEFT JOIN products p ON p.seller_id = s.id GROUP BY s.id ORDER BY FIELD(s.status, "pending", "approved", "suspended", "rejected"), s.created_at DESC')->fetchAll();

$page_title = 'Sellers';
require 'includes/header.php';
if (!empty($_SESSION['seller_mail_note'])): ?>
<div class="notice" style="margin-bottom:14px"><?= admin_h($_SESSION['seller_mail_note']) ?></div>
<?php unset($_SESSION['seller_mail_note']); endif; ?>
<div class="table-wrap">
<table class="table">
<tr><th>Business</th><th>Email</th><th>Phone</th><th>Products</th><th>Status</th><th>Joined</th><th>Action</th></tr>
<?php foreach ($sellers as $s): ?>
<tr>
<td><b><?= admin_h($s['business_name']) ?></b><?php if (!empty($s['business_description'])): ?><br><small style="color:#6b7973;"><?= admin_h($s['business_description']) ?></small><?php endif; ?></td>
<td><?= admin_h($s['email']) ?></td>
<td><?= admin_h($s['phone'] ?? '') ?></td>
<td><?= intval($s['products']) ?></td>
<td><span class="pill"><?= admin_h(ucfirst($s['status'])) ?></span></td>
<td><?= admin_h($s['created_at']) ?></td>
<td>
<?php if ($s['status'] === 'pending'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<input type="hidden" name="id" value="<?= intval($s['id']) ?>">
<input type="hidden" name="status_action" value="approved">
<button class="btn">Approve</button>
</form>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<input type="hidden" name="id" value="<?= intval($s['id']) ?>">
<input type="hidden" name="status_action" value="rejected">
<button class="btn red">Reject</button>
</form>
<?php elseif ($s['status'] === 'approved'): ?>
<form method="post" style="display:inline" onsubmit="return confirm('Suspend this seller? They will be unable to log in.');">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<input type="hidden" name="id" value="<?= intval($s['id']) ?>">
<input type="hidden" name="status_action" value="suspended">
<button class="btn red">Suspend</button>
</form>
<?php elseif ($s['status'] === 'suspended'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<input type="hidden" name="id" value="<?= intval($s['id']) ?>">
<input type="hidden" name="status_action" value="approved">
<button class="btn">Reactivate</button>
</form>
<?php else: ?>
<span class="muted">—</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php require 'includes/footer.php';
