<?php
require_once 'includes/auth.php';
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$error = ''; $notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $kind = $_POST['kind'] ?? 'customer';
    $id = (int) ($_POST['id'] ?? 0);
    if ($kind === 'admin') {
        $name = trim((string) ($_POST['name'] ?? '')); $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($id < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a name and a valid email for the admin.';
        else { try { $pdo->prepare('UPDATE admins SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]); $notice = 'Admin saved.'; } catch (Throwable $e) { $error = 'That email is already used by another admin.'; } }
    } elseif (isset($_POST['delete'])) {
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    } else {
        $name = trim((string) ($_POST['name'] ?? '')); $email = trim((string) ($_POST['email'] ?? ''));
        if ($id) $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name, $email, $id]);
    }
}

$role = in_array($_GET['role'] ?? '', ['admin', 'seller', 'customer'], true) ? $_GET['role'] : 'all';
$q = trim((string) ($_GET['q'] ?? ''));
$admins = $pdo->query('SELECT id, name, email, last_login, created_at FROM admins ORDER BY id')->fetchAll();
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
<?php foreach ($admins as $a): ?><form method="post" class="row" style="align-items:end;border-top:1px solid #e8eeea;padding-top:12px;margin-top:12px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="kind" value="admin"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
<div class="field" style="margin:0"><label>Name <?= (int) $a['id'] === $meId ? $badge('YOU', '#e1f3e8', '#166534') : '' ?></label><input name="name" value="<?= admin_h($a['name']) ?>"></div>
<div class="field" style="margin:0"><label>Email</label><input type="email" name="email" value="<?= admin_h($a['email']) ?>"></div>
<div class="muted">Last sign-in: <?= admin_h($a['last_login'] ?: 'never') ?></div><div><button class="btn">Save</button></div></form><?php endforeach; ?>
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
