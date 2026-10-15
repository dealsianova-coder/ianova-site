<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/broadcast.php';
bc_ensure($pdo);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $message = trim((string) ($_POST['message'] ?? ''));
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    $status = (string) ($_POST['status_change'] ?? '');
    $r = bc_send($pdo, $message, $ids, $status, !empty($_POST['mention']), !empty($_POST['email']));
    if (isset($r['error'])) {
        $error = $r['error'];
    } else {
        $_SESSION['bc_note'] = 'Sent to ' . $r['count'] . ' seller' . ($r['count'] === 1 ? '' : 's')
            . ($r['changed'] ? ' (status changed for ' . $r['changed'] . ')' : '')
            . (($r['emailed'] + $r['email_failed']) > 0 ? ' - emailed ' . $r['emailed'] . ($r['email_failed'] ? ', ' . $r['email_failed'] . ' email(s) could not be sent (check the Gmail app password in config.local.php)' : '') : '')
            . ': ' . implode(', ', $r['names']);
        header('Location: broadcast.php');
        exit;
    }
}

$sellers = bc_sellers($pdo);
$history = bc_history($pdo, 15);
$page_title = 'Message sellers';
require 'includes/header.php';
if (!empty($_SESSION['bc_note'])): ?>
<div class="notice" style="margin-bottom:14px"><?= admin_h($_SESSION['bc_note']) ?></div>
<?php unset($_SESSION['bc_note']); endif;
if ($error): ?><div class="notice error" style="margin-bottom:14px"><?= admin_h($error) ?></div><?php endif; ?>

<div class="card form-card">
<h2>Message sellers</h2>
<p class="muted">Every selected seller gets their own message in their Messages inbox (website and app). It starts with
"Hello &lt;their business name&gt;," unless you turn that off. You can also type <b>{name}</b> anywhere in the message.</p>
<form method="post" id="bcForm">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">

<div class="field"><label>Message</label>
<textarea name="message" rows="6" maxlength="2000" placeholder="Write your message to the sellers..."><?= admin_h($_POST['message'] ?? '') ?></textarea></div>

<div class="field"><label><input type="checkbox" name="mention" value="1" <?= (!isset($_POST['message']) || !empty($_POST['mention'])) ? 'checked' : '' ?>> Greet each seller by their business name</label></div>

<div class="field"><label><input type="checkbox" name="email" value="1" <?= !empty($_POST['email']) ? 'checked' : '' ?>> Also email each seller a copy <span class="muted">(slower: about a second or two per seller)</span></label></div>

<div class="field"><label>Recipients <span id="bcCount" class="muted"></span></label>
<div style="margin-bottom:8px">
<button type="button" class="btn gray" data-pick="all">All (not rejected)</button>
<button type="button" class="btn gray" data-pick="approved">Approved</button>
<button type="button" class="btn gray" data-pick="pending">Pending</button>
<button type="button" class="btn gray" data-pick="suspended">Suspended</button>
<button type="button" class="btn gray" data-pick="none">None</button>
</div>
<div style="max-height:260px;overflow:auto;border:1px solid #e3e8e5;border-radius:12px;padding:8px 12px">
<?php if (!$sellers): ?><span class="muted">No sellers yet.</span><?php endif; ?>
<?php foreach ($sellers as $s):
    $sel = isset($_POST['ids']) ? in_array((int) $s['id'], array_map('intval', (array) $_POST['ids']), true) : ($s['status'] !== 'rejected'); ?>
<label style="display:block;padding:5px 0;font-weight:400">
<input type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>" data-status="<?= admin_h($s['status']) ?>" <?= $sel ? 'checked' : '' ?>>
<b><?= admin_h($s['business_name']) ?></b> <span class="pill"><?= admin_h(ucfirst($s['status'])) ?></span>
</label>
<?php endforeach; ?>
</div></div>

<div class="field"><label>Also change the status of the selected sellers</label>
<select name="status_change">
<option value="">No change</option>
<option value="approved">Approve (reactivate)</option>
<option value="suspended">Suspend</option>
<option value="rejected">Reject</option>
</select>
<small class="muted">Sellers whose status really changes are told in their message. Suspended sellers cannot log in.</small></div>

<button class="btn">Send</button>
</form>
</div>

<div class="card" style="margin-top:16px">
<h2>Recent broadcasts</h2>
<?php if (!$history): ?><p class="muted">Nothing sent yet.</p><?php endif; ?>
<?php foreach ($history as $h): ?>
<details style="border-bottom:1px solid #eef0f2;padding:8px 0">
<summary><b><?= admin_h($h['created_at']) ?></b> &middot; <?= (int) $h['recipient_count'] ?> seller<?= (int) $h['recipient_count'] === 1 ? '' : 's' ?>
<?= $h['status_change'] !== '' ? ' &middot; status: ' . admin_h($h['status_change']) : '' ?></summary>
<p style="white-space:pre-wrap;margin:8px 0"><?= admin_h($h['message']) ?></p>
<p class="muted" style="margin:0"><b>Sent to:</b> <?= admin_h(str_replace("\n", ', ', (string) $h['recipients'])) ?></p>
</details>
<?php endforeach; ?>
</div>

<script>
(function () {
  var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="ids[]"]'));
  var out = document.getElementById('bcCount');
  function count() { var n = boxes.filter(function (b) { return b.checked; }).length; out.textContent = '(' + n + ' selected)'; return n; }
  boxes.forEach(function (b) { b.addEventListener('change', count); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-pick]'), function (btn) {
    btn.addEventListener('click', function () {
      var k = btn.getAttribute('data-pick');
      boxes.forEach(function (b) {
        var st = b.getAttribute('data-status');
        b.checked = k === 'all' ? st !== 'rejected' : k === 'none' ? false : st === k;
      });
      count();
    });
  });
  document.getElementById('bcForm').addEventListener('submit', function (e) {
    var n = count();
    if (n === 0) { alert('Select at least one seller.'); e.preventDefault(); return; }
    var st = document.querySelector('select[name="status_change"]').value;
    var msg = 'Send to ' + n + ' seller' + (n === 1 ? '' : 's') + '?' + (st ? '\nTheir status will also be set to: ' + st + '.' : '');
    if (!confirm(msg)) e.preventDefault();
  });
  count();
})();
</script>
<?php require 'includes/footer.php';
