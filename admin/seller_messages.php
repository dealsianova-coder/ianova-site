<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/seller_messaging.php';
ensure_seller_messaging($pdo);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT id, business_name, email, phone, business_description, status FROM sellers WHERE id = ?');
$st->execute([$id]);
$seller = $st->fetch();
if (!$seller) { header('Location: sellers.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $text = trim((string) ($_POST['message'] ?? ''));
    if ($text !== '' && mb_strlen($text) <= 2000) {
        seller_message_add($pdo, $id, 'admin', $text);
        $sent = send_seller_reply_email($seller['email'], $seller['business_name'], $text);
        $_SESSION['seller_mail_note'] = $sent
            ? 'Reply sent in the app and by email to ' . $seller['email'] . '.'
            : 'Reply saved and visible in the app, but the email could not be sent. Check the Gmail app password in config.local.php.';
    }
    header('Location: seller_messages.php?id=' . $id);
    exit;
}

// Opening the thread marks the seller's messages as read.
$pdo->prepare("UPDATE seller_messages SET read_at = NOW() WHERE seller_id = ? AND sender = 'seller' AND read_at IS NULL")->execute([$id]);
$messages = seller_thread($pdo, $id);

$page_title = 'Messages · ' . $seller['business_name'];
require 'includes/header.php';
?>
<p><a href="sellers.php">← Back to sellers</a></p>
<div style="background:#fff;border:1px solid #E9EBEF;border-radius:22px;padding:16px;margin-bottom:16px">
  <b><?= admin_h($seller['business_name']) ?></b> · <span class="pill"><?= admin_h(ucfirst($seller['status'])) ?></span><br>
  <small style="color:#5F6575"><?= admin_h($seller['email']) ?><?= $seller['phone'] ? ' · ' . admin_h($seller['phone']) : '' ?></small>
</div>
<?php if (!empty($_SESSION['seller_mail_note'])): ?>
<div class="notice" style="margin-bottom:14px"><?= admin_h($_SESSION['seller_mail_note']) ?></div>
<?php unset($_SESSION['seller_mail_note']); endif; ?>

<div style="display:grid;gap:10px;max-width:680px">
<?php if (!$messages): ?>
  <p style="color:#5F6575">No messages yet.</p>
<?php endif; ?>
<?php foreach ($messages as $m): $mine = $m['sender'] === 'admin'; ?>
  <div style="justify-self:<?= $mine ? 'end' : 'start' ?>;max-width:85%;background:<?= $mine ? '#0F0F12' : '#F3F4F7' ?>;color:<?= $mine ? '#fff' : '#0F0F12' ?>;border-radius:18px;padding:10px 14px">
    <div style="white-space:pre-wrap"><?= admin_h($m['body']) ?></div>
    <div style="font-size:11px;opacity:.6;margin-top:4px"><?= $mine ? 'You' : admin_h($seller['business_name']) ?> · <?= admin_h($m['created_at']) ?></div>
  </div>
<?php endforeach; ?>
</div>

<form method="post" style="max-width:680px;margin-top:18px;display:grid;gap:10px">
  <input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <textarea name="message" rows="4" maxlength="2000" required placeholder="Write a reply. The seller sees it in the app and gets it by email." style="width:100%;border:1px solid #E9EBEF;border-radius:16px;padding:12px;font:inherit"></textarea>
  <div><button class="btn">Send reply</button></div>
</form>
<?php require 'includes/footer.php';
