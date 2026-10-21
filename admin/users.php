<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_team.php';
team_ensure($pdo);
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$error = ''; $notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $kind = $_POST['kind'] ?? 'customer';
    $id = (int) ($_POST['id'] ?? 0);
    if ($kind === 'admin') {            // edit my own account (security password)
        $m = $id === $meId ? team_edit_self($pdo, $meId, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['new_password'] ?? ''), (string) ($_POST['security'] ?? '')) : 'The admin account is protected. You can only edit your own admin account.';
        if ($m === '') $notice = 'Admin account saved.'; else $error = $m;
    } elseif ($kind === 'sec') {        // set / change the security password
        $m = team_set_security($pdo, $meId, (string) ($_POST['proof'] ?? ''), (string) ($_POST['new'] ?? ''));
        if ($m === '') $notice = 'Security password saved.'; else $error = $m;
    } elseif ($kind === 'team_add') {   // master adds an admin (pending approval)
        $m = team_add($pdo, $meId, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['security'] ?? ''));
        if ($m === '') $notice = 'Admin added. They can sign in once you approve them.'; else $error = $m;
    } elseif ($kind === 'team_do') {    // master approves / suspends / removes
        $m = team_do($pdo, $meId, $id, (string) ($_POST['do'] ?? ''), (string) ($_POST['security'] ?? ''));
        if ($m === '') $notice = 'Done.'; else $error = $m;
    } elseif (isset($_POST['delete'])) {
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    } else {
        $name = trim((string) ($_POST['name'] ?? '')); $email = trim((string) ($_POST['email'] ?? ''));
        if ($id) $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name, $email, $id]);
    }
}

$role = in_array($_GET['role'] ?? '', ['admin', 'seller', 'customer'], true) ? $_GET['role'] : 'all';
$q = trim((string) ($_GET['q'] ?? ''));
$admins = team_list($pdo); $iAmMaster = team_is_master($pdo, $meId); $iHaveSec = team_has_security($pdo, $meId);
$sellers = $pdo->query('SELECT s.id, s.business_name, s.email, s.status, s.created_at, COUNT(p.id) products FROM sellers s LEFT JOIN products p ON p.seller_id = s.id GROUP BY s.id ORDER BY s.id DESC')->fetchAll();
$users = $pdo->query('SELECT u.*, COUNT(o.id) orders FROM users u LEFT JOIN orders o ON o.user_id = u.id GROUP BY u.id ORDER BY u.id DESC')->fetchAll();
$hit = function (array $vals) use ($q): bool { if ($q === '') return true; foreach ($vals as $v) if (stripos((string) $v, $q) !== false) return true; return false; };
$admins = array_values(array_filter($admins, fn($r) => $hit([$r['name'], $r['email']])));
$sellers = array_values(array_filter($sellers, fn($r) => $hit([$r['business_name'], $r['email']])));
$users = array_values(array_filter($users, fn($r) => $hit([$r['name'], $r['email']])));
$tab = function (string $key, string $label, int $n) use ($role, $q) {
    return '<a class="btn ' . ($role === $key ? '' : 'gray') . '" href="users.php?role=' . $key . ($q !== '' ? '&q=' . urlencode($q) : '') . '">' . $label . ' (' . $n . ')</a>';
};
$badge = function (string $text, string $bg, string $fg) { return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.06em;background:' . $bg . ';color:' . $fg . ';">' . $text . '</span>'; };
$page_title = 'Users';
require 'includes/header.php';
if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<div class="actions" style="margin-bottom:14px"><?= $tab('all', 'All', count($admins) + count($sellers) + count($users)) ?><?= $tab('admin', '👑 Admin', count($admins)) ?><?= $tab('seller', '🏪 Sellers', count($sellers)) ?><?= $tab('customer', '🛍️ Customers', count($users)) ?>
<form method="get" style="margin-left:auto"><input type="hidden" name="role" value="<?= admin_h($role) ?>"><input class="search" name="q" value="<?= admin_h($q) ?>" placeholder="Search name or email"></form></div>

<?php if ($role === 'all' || $role === 'admin'): ?>
<div class="card" style="border-left:6px solid #e9b949;margin-bottom:18px"><h2 style="margin:0 0 4px">👑 Admin <?= $badge('FULL CONTROL', '#17352c', '#e9b949') ?></h2>
<p class="muted" style="margin-top:0">The store owner accounts. Only these can sign in to this admin and the Admin app. Everything here is separate from sellers and customers.</p>
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
.sec-ico{font-size:12px}
</style>
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
    <form method="post" class="f" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="team_do"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
      <input type="password" name="security" placeholder="🔒 Your security password" required autocomplete="off">
      <div class="tm-acts">
      <?php if ($a['status'] === 'pending'): ?><button class="btn" name="do" value="approve">✅ Approve</button><button class="btn red" name="do" value="reject" onclick="return confirm('Reject and delete this admin request?')">Reject</button>
      <?php elseif ($a['status'] === 'suspended'): ?><button class="btn" name="do" value="restore">Restore</button><button class="btn red" name="do" value="remove" onclick="return confirm('Remove this admin?')">Remove</button>
      <?php else: ?><button class="btn gray" name="do" value="suspend">Suspend</button><button class="btn red" name="do" value="remove" onclick="return confirm('Remove this admin?')">Remove</button><?php endif; ?>
      </div></form>
  <?php else: ?><div style="font-size:12px;opacity:.75">🔒 Protected: only this admin can edit this account.</div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div class="tm"><div class="tm-panel" style="grid-column:1/-1"><strong>🔒 Security password</strong>
  <p class="muted" style="margin:6px 0 10px">A second password, different from your sign-in password. It protects editing your account and adding or approving admins.</p>
  <form method="post" class="f" style="display:grid;gap:10px;max-width:420px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="sec">
    <input type="password" name="proof" required autocomplete="off" placeholder="<?= $iHaveSec ? 'Current security password' : 'Your sign-in password (to confirm it is you)' ?>">
    <input type="password" name="new" required minlength="8" autocomplete="new-password" placeholder="<?= $iHaveSec ? 'New security password' : 'Choose a security password (8+ characters)' ?>">
    <button class="btn"><?= $iHaveSec ? 'Change security password' : 'Set security password' ?></button></form></div>
<?php if ($iAmMaster): ?>
<div class="tm-panel" style="grid-column:1/-1"><strong>➕ Add another admin</strong>
  <p class="muted" style="margin:6px 0 10px">The new admin is created as <b>pending</b> and cannot sign in until you approve them. Give them their own sign-in password.</p>
  <?php if (!$iHaveSec): ?><p class="muted">Set your security password above first.</p><?php else: ?>
  <form method="post" class="f" style="display:grid;gap:10px;max-width:420px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="team_add">
    <input name="name" required placeholder="Full name"><input type="email" name="email" required placeholder="Email">
    <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Sign-in password for the new admin (8+)">
    <input type="password" name="security" required autocomplete="off" placeholder="🔒 Your security password">
    <button class="btn">Add admin (pending approval)</button></form><?php endif; ?></div>
<?php endif; ?></div>
<?php if (!$admins): ?><p class="muted">No admin accounts found.</p><?php endif; ?></div>
<?php endif; ?>

<?php if ($role === 'all' || $role === 'seller'): ?>
<h2 style="margin:18px 0 8px">🏪 Sellers <?= $badge('SELLER', '#e1f3e8', '#166534') ?></h2>
<div class="table-wrap" style="margin-bottom:18px"><table class="table"><tr><th>Business</th><th>Email</th><th>Status</th><th>Products</th><th>Joined</th><th>Manage</th></tr>
<?php foreach ($sellers as $s): ?><tr><td><?= admin_h($s['business_name']) ?></td><td><?= admin_h($s['email']) ?></td><td><span class="pill"><?= admin_h($s['status']) ?></span></td><td><?= (int) $s['products'] ?></td><td><?= admin_h($s['created_at']) ?></td><td><a class="btn gray" href="sellers.php">Open in Sellers</a></td></tr><?php endforeach; ?>
<?php if (!$sellers): ?><tr><td colspan="6" class="muted">No sellers.</td></tr><?php endif; ?></table></div>
<?php endif; ?>

<?php if ($role === 'all' || $role === 'customer'): ?>
<h2 style="margin:18px 0 8px">🛍️ Customers <?= $badge('CUSTOMER', '#eef1f0', '#52615a') ?></h2>
<div class="table-wrap"><table class="table"><tr><th>Name</th><th>Email</th><th>Orders</th><th>Joined</th><th>Edit</th><th>Delete</th></tr>
<?php foreach ($users as $u): ?><tr><form method="post"><td><input name="name" value="<?= admin_h($u['name']) ?>"></td><td><input type="email" name="email" value="<?= admin_h($u['email']) ?>"></td><td><?= (int) $u['orders'] ?></td><td><?= admin_h($u['created_at']) ?></td><td><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn">Save</button></td><td><button class="btn red" name="delete" value="1" onclick="return confirm('Delete this customer?')">Delete</button></td></form></tr><?php endforeach; ?>
<?php if (!$users): ?><tr><td colspan="6" class="muted">No customers.</td></tr><?php endif; ?></table></div>
<?php endif; ?>
<?php require 'includes/footer.php';
