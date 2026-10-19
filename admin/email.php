<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
notify_ensure($pdo);
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        foreach (['on', 'admin', 'seller', 'customer', 'subs'] as $k) ops_setting_set($pdo, 'mail_' . $k, isset($_POST['f_' . $k]) ? '1' : '0');
        $to = trim((string) ($_POST['admin_to'] ?? ''));
        if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) $err = 'That admin email address is not valid.';
        else { ops_setting_set($pdo, 'mail_admin_to', $to); $msg = 'Saved.'; }
    } elseif ($do === 'test') {
        $to = notify_admin_address($pdo);
        if (!notify_stats($pdo)['smtp_ready']) $err = 'Email sending is not set up yet: SMTP_PASS is empty in config.local.php.';
        elseif ($to === '') $err = 'Set an admin email address first.';
        else {
            notify_queue($pdo, 'test:' . bin2hex(random_bytes(4)), 'admin', $to, 'Admin', 'Test email from ' . SITE_NAME, notify_wrap('It works', '<p>This is a test email from your admin.</p>'), true);
            $msg = notify_deliver($pdo, 3) > 0 ? 'Test email sent to ' . $to . '.' : 'Queued, but the mail server did not accept it yet. See the log below.';
        }
    } elseif ($do === 'flush') {
        notify_scan($pdo);
        $msg = notify_deliver($pdo, 15) . ' email(s) sent.';
    } elseif ($do === 'news' || $do === 'news_test') {
        $subject = trim((string) ($_POST['subject'] ?? '')); $text = trim((string) ($_POST['body'] ?? ''));
        if ($subject === '' || $text === '') $err = 'Write a subject and a message.';
        else { $n = notify_newsletter($pdo, $subject, $text, $do === 'news_test'); notify_deliver($pdo, 8); $msg = $do === 'news_test' ? 'Test sent to the admin address.' : $n . ' email(s) queued. They go out in small batches in the background.'; }
    }
}

$flags = [];
foreach (['on', 'admin', 'seller', 'customer', 'subs'] as $k) $flags[$k] = ops_setting($pdo, 'mail_' . $k, '1') === '1';
$st = notify_stats($pdo);
$recent = notify_recent($pdo, 30);
$subs = (int) $pdo->query('SELECT COUNT(*) FROM newsletter_subscribers')->fetchColumn();
$page_title = 'Email alerts';
require 'includes/header.php';
if ($msg): ?><div class="notice"><?= admin_h($msg) ?></div><?php endif; if ($err): ?><div class="notice error"><?= admin_h($err) ?></div><?php endif;
if (!$st['smtp_ready']): ?><div class="notice error">Email sending is not set up: SMTP_PASS is empty in config.local.php, so emails wait in the queue.</div><?php endif; ?>
<div class="card form-card" style="margin-bottom:16px"><form method="post"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="save">
<h3 style="margin-top:0">Who gets emails</h3>
<label style="display:block;margin:8px 0"><input type="checkbox" name="f_on" <?= $flags['on'] ? 'checked' : '' ?>> <strong>Email alerts on</strong> (master switch)</label>
<label style="display:block;margin:8px 0"><input type="checkbox" name="f_admin" <?= $flags['admin'] ? 'checked' : '' ?>> Admin: new orders, new sellers, products waiting for review</label>
<label style="display:block;margin:8px 0"><input type="checkbox" name="f_seller" <?= $flags['seller'] ? 'checked' : '' ?>> Sellers: new orders for their products, cancelled orders, product approved or rejected</label>
<label style="display:block;margin:8px 0"><input type="checkbox" name="f_customer" <?= $flags['customer'] ? 'checked' : '' ?>> Customers: order confirmation and status updates (approved, paid, shipped, delivered, cancelled)</label>
<label style="display:block;margin:8px 0"><input type="checkbox" name="f_subs" <?= $flags['subs'] ? 'checked' : '' ?>> Subscribers: welcome email for new subscribers</label>
<div class="field" style="margin-top:14px"><label>Admin email address (leave empty to use <?= admin_h(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '') ?>)</label><input type="email" name="admin_to" value="<?= admin_h(ops_setting($pdo, 'mail_admin_to', '')) ?>"></div>
<div class="actions"><button class="btn">Save</button></div></form>
<form method="post" class="actions" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><button class="btn gray" name="do" value="test">Send me a test email</button><button class="btn gray" name="do" value="flush">Send waiting emails now</button></form>
<p class="muted">Queue: <?= (int) $st['queued'] ?> waiting, <?= (int) $st['sent'] ?> sent, <?= (int) $st['failed'] ?> failed.</p></div>

<div class="card form-card" style="margin-bottom:16px"><h3 style="margin-top:0">Send a newsletter (<?= $subs ?> subscriber<?= $subs === 1 ? '' : 's' ?>)</h3>
<form method="post"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><div class="field"><label>Subject</label><input name="subject" maxlength="150"></div>
<div class="field"><label>Message</label><textarea name="body" placeholder="Write your message. Leave an empty line to start a new paragraph."></textarea></div>
<div class="actions"><button class="btn gray" name="do" value="news_test">Send test to me</button><button class="btn" name="do" value="news" onclick="return confirm('Send this to all subscribers?')">Send to all subscribers</button></div></form></div>

<h3>Latest emails</h3>
<div class="table-wrap"><table class="table"><tr><th>When</th><th>To</th><th>Subject</th><th>Status</th></tr>
<?php foreach ($recent as $r): ?><tr><td><?= admin_h($r['created_at']) ?></td><td><?= admin_h($r['to_email']) ?> <span class="pill"><?= admin_h($r['audience']) ?></span></td><td><?= admin_h($r['subject']) ?></td>
<td><?= $r['sent_at'] ? 'Sent' : ((int) $r['tries'] >= 3 ? 'Failed: ' . admin_h($r['last_error']) : 'Waiting') ?></td></tr><?php endforeach; ?>
<?php if (!$recent): ?><tr><td colspan="4" class="muted">No emails yet.</td></tr><?php endif; ?></table></div>
<?php require 'includes/footer.php';
