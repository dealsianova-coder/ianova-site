<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/admin_team.php';
team_ensure($pdo);
$meId = (int) ($_SESSION['admin_id'] ?? 0);
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$error = ''; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $error = team_sec_reset_apply($pdo, $meId, $token, (string) ($_POST['login'] ?? ''), (string) ($_POST['new'] ?? ''));
    $done = $error === '';
}
$page_title = 'Reset security password';
require 'includes/header.php';
?>
<div class="card" style="max-width:460px;margin:0 auto">
<?php if ($done): ?>
  <h2 style="margin-top:0">✅ Security password saved</h2><p class="muted">You can use it right away.</p><a class="btn" href="admins.php">Back to Admin team</a>
<?php else: ?>
  <h2 style="margin-top:0">🔒 Set a new security password</h2>
  <p class="muted">Confirm with your sign-in password, then choose a new security password (8+ characters, different from your sign-in password).</p>
  <?php if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
  <form method="post" style="display:grid;gap:10px"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="t" value="<?= admin_h($token) ?>">
    <input type="password" name="login" required autocomplete="current-password" placeholder="Your sign-in password">
    <input type="password" name="new" required minlength="8" autocomplete="new-password" placeholder="New security password">
    <button class="btn">Save security password</button></form>
<?php endif; ?>
</div>
<?php require 'includes/footer.php'; ?>
