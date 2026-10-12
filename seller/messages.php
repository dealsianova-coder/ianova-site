<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/seller_messaging.php';
ensure_seller_messaging($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 2000);
    if (hash_equals($_SESSION['ops_csrf'], (string) ($_POST['csrf'] ?? '')) && $text !== '') {
        $rc = $pdo->prepare("SELECT COUNT(*) FROM seller_messages WHERE seller_id = ? AND sender = 'seller' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $rc->execute([$seller_id]);
        if ((int) $rc->fetchColumn() < 20) seller_message_add($pdo, $seller_id, 'seller', $text);
        else $_SESSION['ops_flash'] = 'You are sending messages too quickly. Please wait a little.';
    }
    header('Location: messages.php');
    exit;
}
$thread = seller_thread($pdo, $seller_id);
// Opening the page marks the admin's replies as read (the sidebar count is worked out first, so it shows 0 next time).
$pdo->prepare("UPDATE seller_messages SET read_at = NOW() WHERE seller_id = ? AND sender = 'admin' AND read_at IS NULL")->execute([$seller_id]);
seller_head('Messages', 'messages.php');
?>
<style>.chat{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}.msg{max-width:82%;padding:12px 14px;border-radius:18px;background:#F3F4F7;white-space:pre-wrap;word-break:break-word}.msg.me{align-self:flex-end;background:#0F0F12;color:#fff}.msg small{display:block;opacity:.6;margin-top:4px;font-size:11px}</style>
<div class="blk">
  <div class="blk-head"><h2>Chat with IANOVA admin</h2></div>
  <?php if (!$thread): ?><p class="muted">No messages yet. Write to the admin below.</p><?php endif; ?>
  <div class="chat">
  <?php foreach ($thread as $m): $me = $m['sender'] === 'seller'; ?>
    <div class="msg <?= $me ? 'me' : '' ?>"><?= ops_h($m['body']) ?><small><?= $me ? 'You' : 'IANOVA' ?> · <?= ops_h($m['created_at']) ?></small></div>
  <?php endforeach; ?>
  </div>
  <form method="post"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>">
    <div class="field"><textarea name="message" maxlength="2000" placeholder="Write a message to the admin..." required style="min-height:90px"></textarea></div>
    <button class="btn" type="submit">Send</button></form>
</div>
<?php seller_foot();
