<?php
require_once 'includes/auth.php';
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$error = ''; $notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $kind = $_POST['kind'] ?? 'customer';
    $id = (int) ($_POST['id'] ?? 0);
    if (false) {
    } elseif (isset($_POST['delete'])) {
        require_once __DIR__ . '/../includes/user_remove.php'; $r_ = user_remove($pdo, $id); $_SESSION['oo_flash'] = $r_['message']; $_SESSION['oo_flash_err'] = 0;
    } else {
        $name = trim((string) ($_POST['name'] ?? '')); $email = trim((string) ($_POST['email'] ?? ''));
        if ($id) $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name, $email, $id]);
    }
}

$role = 'customer';
$q = trim((string) ($_GET['q'] ?? ''));
$users = $pdo->query('SELECT u.*, COUNT(o.id) orders FROM users u LEFT JOIN orders o ON o.user_id = u.id GROUP BY u.id ORDER BY u.id DESC')->fetchAll();
$hit = function (array $vals) use ($q): bool { if ($q === '') return true; foreach ($vals as $v) if (stripos((string) $v, $q) !== false) return true; return false; };
$users = array_values(array_filter($users, fn($r) => $hit([$r['name'], $r['email']])));
$badge = function (string $text, string $bg, string $fg) { return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.06em;background:' . $bg . ';color:' . $fg . ';">' . $text . '</span>'; };
$page_title = 'Customers';
require 'includes/header.php';
if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<div class="actions" style="margin-bottom:14px"><span class="btn">🛍️ Customers (<?= count($users) ?>)</span>
<form method="get" style="margin-left:auto"><input class="search" name="q" value="<?= admin_h($q) ?>" placeholder="Search name or email"></form></div>

<p class="muted" style="margin:0 0 14px">Shoppers only. Sellers are in <a href="sellers.php">🏪 Sellers</a> and admin accounts are in <a href="admins.php">🛡️ Admin team</a>.</p>
<?php if (true): ?>
<h2 style="margin:18px 0 8px">🛍️ Customers <?= $badge('CUSTOMER', '#eef1f0', '#52615a') ?></h2>
<div class="table-wrap"><table class="table"><tr><th>Name</th><th>Email</th><th>Orders</th><th>Joined</th><th>Edit</th><th>Delete</th></tr>
<?php foreach ($users as $u): ?><tr><form method="post"><td><input name="name" value="<?= admin_h($u['name']) ?>"></td><td><input type="email" name="email" value="<?= admin_h($u['email']) ?>"></td><td><?= (int) $u['orders'] ?></td><td><?= admin_h($u['created_at']) ?></td><td><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn">Save</button></td><td><button class="btn red" name="delete" value="1" onclick="return confirm('Delete this customer?')">Delete</button></td></form></tr><?php endforeach; ?>
<?php if (!$users): ?><tr><td colspan="6" class="muted">No customers.</td></tr><?php endif; ?></table></div>
<?php endif; ?>
<?php require 'includes/footer.php';
