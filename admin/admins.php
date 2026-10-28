<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_team.php';
team_ensure($pdo);
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$error = ''; $notice = '';
$sections = team_sections();
$permsIn = fn() => (array) ($_POST['perms'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $kind = $_POST['kind'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $sec = (string) ($_POST['security'] ?? '');
    if ($kind === 'admin') {            // edit my own account (security password)
        $m = $id === $meId ? team_edit_self($pdo, $meId, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['new_password'] ?? ''), $sec) : 'The admin account is protected. You can only edit your own admin account.';
        if ($m === '') $notice = 'Admin account saved.'; else $error = $m;
    } elseif ($kind === 'sec') {
        $m = team_set_security($pdo, $meId, (string) ($_POST['proof'] ?? ''), (string) ($_POST['new'] ?? ''));
        if ($m === '') $notice = 'Security password saved.'; else $error = $m;
    } elseif ($kind === 'sec_forgot') {
        $m = team_sec_reset_request($pdo, $meId);
        if ($m === '') $notice = 'We emailed you a link to set a new security password. It works for 30 minutes.'; else $error = $m;
    } elseif ($kind === 'team_add') {
        $m = team_add($pdo, $meId, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), $sec, $permsIn());
        if ($m === '') $notice = 'Admin added. They can sign in once you approve them.'; else $error = $m;
    } elseif ($kind === 'team_perms') {
        $m = team_set_perms($pdo, $meId, $id, $permsIn(), $sec);
        if ($m === '') $notice = 'Access updated.'; else $error = $m;
    } elseif ($kind === 'idle') {
        $m = team_idle_set($pdo, $meId, (int) ($_POST['minutes'] ?? -1), $sec);
        if ($m === '') $notice = 'Saved.'; else $error = $m;
    } elseif ($kind === 'team_do') {
        $m = team_do($pdo, $meId, $id, (string) ($_POST['do'] ?? ''), $sec);
        if ($m === '') $notice = 'Done.'; else $error = $m;
    }
}

$iAmMaster = team_is_master($pdo, $meId); $iHaveSec = team_has_security($pdo, $meId);
$admins = team_list($pdo);
if (!$iAmMaster) $admins = array_values(array_filter($admins, fn($r) => (int) $r['id'] === $meId || (int) $r['is_master'] === 1));
$page_title = 'Admin team';
require 'includes/header.php';
$checks = function (array $on, string $name = 'perms[]') use ($sections) {
    $h = '<div class="pm">';
    foreach ($sections as $k => $d) $h .= '<label><input type="checkbox" name="' . $name . '" value="' . $k . '"' . (in_array($k, $on, true) ? ' checked' : '') . '> ' . admin_h($d[0]) . '</label>';
    return $h . '</div>';
};
if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<style>
.tm{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-top:14px}
.tm-card{border:1px solid #e3ebe6;border-radius:16px;padding:16px;background:#fff;display:flex;flex-direction:column;gap:10px;box-shadow:0 1px 2px rgba(20,45,35,.04)}
.tm-card.master{background:linear-gradient(135deg,#17352c,#1d6b52);color:#fff;border-color:#17352c}
.tm-top{display:flex;gap:12px;align-items:center}
.tm-av{width:46px;height:46px;border-radius:14px;background:#e9f1ec;color:#1d6b52;font-weight:800;font-size:18px;display:flex;align-items:center;justify-content:center;flex:none}
.tm-card.master .tm-av{background:#e9b949;color:#17352c}
.tm-name{font-weight:800;font-size:16px}.tm-mail{opacity:.75;font-size:13px;word-break:break-all}
.tm-b{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;letter-spacing:.05em}
.tm-acts{display:flex;gap:8px;flex-wrap:wrap}
.tm details{border:1px solid #e3ebe6;border-radius:12px;padding:10px 12px;background:#f8faf9}
.tm-card.master details{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.25)}
.tm summary{cursor:pointer;font-weight:700}
.tm form.f{display:grid;gap:10px;margin-top:10px}.tm form.f label{font-size:12px;font-weight:700;opacity:.8}.tm form.f input{width:100%;box-sizing:border-box}
.tm-panel{border:1px solid #e3ebe6;border-radius:16px;padding:16px;background:#fff;margin-top:14px}
.pm{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px;margin:10px 0}.pm label{display:flex;gap:8px;align-items:center;font-size:13px;font-weight:600;padding:6px 8px;border:1px solid #e3ebe6;border-radius:10px;background:#fff;opacity:1;color:#17231e}.tm-card.master .pm label{color:#17231e}
</style>
<h2 style="margin:0 0 4px">🛡️ Admin team</h2><p class="muted" style="margin-top:0">The master admin adds admins, approves them and chooses what each one can see. Sellers and customers are in <a href="users.php">Users</a>.</p>
<?php $stBadge = ['active' => ['ACTIVE', '#e1f3e8', '#166534'], 'pending' => ['PENDING APPROVAL', '#fff3d6', '#8a5a00'], 'suspended' => ['SUSPENDED', '#fde8e8', '#a32626']]; ?>
<div class="tm">
<?php foreach ($admins as $a): $mine = (int) $a['id'] === $meId; $isM = (int) $a['is_master'] === 1; $st = $stBadge[$a['status']] ?? $stBadge['active']; ?>
<div class="tm-card<?= $isM ? ' master' : '' ?>">
  <div class="tm-top"><div class="tm-av"><?= admin_h(strtoupper(mb_substr($a['name'], 0, 1))) ?></div>
    <div style="min-width:0"><div class="tm-name"><?= admin_h($a['name']) ?></div><div class="tm-mail"><?= admin_h($a['email']) ?></div></div></div>
  <div><?php if ($isM) echo '<span class="tm-b" style="background:#e9b949;color:#17352c">👑 MASTER ADMIN</span> '; ?><?php if (!$isM) echo '<span class="tm-b" style="background:' . $st[1] . ';color:' . $st[2] . '">' . $st[0] . '</span> '; ?><?php if ($mine) echo '<span class="tm-b" style="background:#e1f3e8;color:#166534">YOU</span>'; ?></div>
  <div style="font-size:12px;opacity:.75">Last sign-in: <?= admin_h($a['last_login'] ?: 'never') ?></div>
  <?php if ($mine): ?>
    <details><summary>✏️ Edit my account 🔒</summary>
      <?php if (!$iHaveSec): ?><p style="font-size:13px;margin:8px 0 0">Set your security password first (below).</p><?php else: ?>
      <form method="post" class="f"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="admin"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
        <div><label>Name</label><input name="name" value="<?= admin_h($a['name']) ?>"></div>
        <div><label>Email</label><input type="email" name="email" value="<?= admin_h($a['email']) ?>"></div>
        <div><label>New sign-in password (optional)</label><input type="password" name="new_password" autocomplete="new-password" placeholder="leave empty to keep"></div>
        <div><label>🔒 Security password</label><input type="password" name="security" required autocomplete="off"></div>
        <button class="btn">Save changes</button></form><?php endif; ?></details>
  <?php elseif ($iAmMaster && !$isM): ?>
    <details><summary>🔑 What they can see</summary>
      <form method="post" class="f"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="team_perms"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
        <?= $checks($a['allowed']) ?>
        <input type="password" name="security" placeholder="🔒 Your security password" required autocomplete="off"><button class="btn">Save access</button></form></details>
    <form method="post" class="f" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="team_do"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
      <input type="password" name="security" placeholder="🔒 Your security password" required autocomplete="off">
      <div class="tm-acts">
      <?php if ($a['status'] === 'pending'): ?><button class="btn" name="do" value="approve">✅ Approve</button><button class="btn red" name="do" value="reject" onclick="return confirm('Reject and delete this admin request?')">Reject</button>
      <?php elseif ($a['status'] === 'suspended'): ?><button class="btn" name="do" value="restore">Restore</button><button class="btn red" name="do" value="remove" onclick="return confirm('Remove this admin?')">Remove</button>
      <?php else: ?><button class="btn gray" name="do" value="make_master" onclick="return confirm('Make this admin the master admin? You will become a normal admin.')">Make master</button><button class="btn gray" name="do" value="suspend">Suspend</button><button class="btn red" name="do" value="remove" onclick="return confirm('Remove this admin?')">Remove</button><?php endif; ?>
      </div></form>
  <?php else: ?><div style="font-size:12px;opacity:.75">🔒 Protected: only this admin can edit this account.</div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div class="tm"><div class="tm-panel" style="grid-column:1/-1"><strong>🔒 Security password</strong>
  <p class="muted" style="margin:6px 0 10px">A second password, different from your sign-in password. It protects editing your account and adding or approving admins.</p>
  <form method="post" class="f" style="display:grid;gap:10px;max-width:640px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="sec">
    <input type="password" name="proof" required autocomplete="off" placeholder="<?= $iHaveSec ? 'Current security password' : 'Your sign-in password (to confirm it is you)' ?>">
    <input type="password" name="new" required minlength="8" autocomplete="new-password" placeholder="<?= $iHaveSec ? 'New security password' : 'Choose a security password (8+ characters)' ?>">
    <button class="btn"><?= $iHaveSec ? 'Change security password' : 'Set security password' ?></button></form>
  <?php if ($iHaveSec): ?><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="sec_forgot"><button class="btn gray">Forgot it? Email me a reset link</button></form><?php endif; ?></div>
<?php if ($iAmMaster): ?>
<div class="tm-panel" style="grid-column:1/-1"><strong>⏱️ Automatic sign-out</strong>
  <p class="muted" style="margin:6px 0 10px">Admins are signed out (website and Admin app) after this long without activity. Applies to every admin.</p>
  <?php if (!$iHaveSec): ?><p class="muted">Set your security password above first.</p><?php else: ?>
  <form method="post" class="f" style="display:grid;gap:10px;max-width:640px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="idle">
    <select name="minutes"><?php foreach (team_idle_choices() as $k => $lab): ?><option value="<?= (int) $k ?>"<?= $k === team_idle_minutes($pdo) ? ' selected' : '' ?>><?= admin_h($lab) ?></option><?php endforeach; ?></select>
    <input type="password" name="security" required autocomplete="off" placeholder="🔒 Your security password">
    <button class="btn">Save</button></form><?php endif; ?></div>
<div class="tm-panel" style="grid-column:1/-1"><strong>➕ Add another admin</strong>
  <p class="muted" style="margin:6px 0 10px">The new admin is created as <b>pending</b> and cannot sign in until you approve them. Give them their own sign-in password.</p>
  <?php if (!$iHaveSec): ?><p class="muted">Set your security password above first.</p><?php else: ?>
  <form method="post" class="f" style="display:grid;gap:10px;max-width:640px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="team_add">
    <input name="name" required placeholder="Full name"><input type="email" name="email" required placeholder="Email">
    <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Sign-in password for the new admin (8+)">
    <div style="font-size:13px;font-weight:700">What can they see?</div><?= $checks(['dashboard', 'products', 'orders']) ?>
    <input type="password" name="security" required autocomplete="off" placeholder="🔒 Your security password">
    <button class="btn">Add admin (pending approval)</button></form><?php endif; ?></div>
<?php endif; ?></div>
<?php if (!$admins): ?><p class="muted">No admin accounts found.</p><?php endif; ?>
<?php require 'includes/footer.php'; ?>
