<?php
require 'config.php';
require 'includes/functions.php';

$error = '';
$redirect = $_GET['redirect'] ?? 'account.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? 'account.php';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Incorrect email or password.';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            header('Location: ' . $redirect);
            exit;
        }
    }
}

$page_title = 'Sign in';
require 'includes/header.php';
?>

<section class="wrap" style="max-width:440px;margin:48px auto;">
  <div class="card form-card" style="padding:28px;border:1px solid var(--line);border-radius:14px;background:#fff;">
    <h1 style="margin-top:0;">Sign in</h1>
    <?php if ($error): ?>
      <div class="notice error" style="background:#fdeaea;color:#8a2424;padding:12px 14px;border-radius:9px;margin-bottom:16px;"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
      <div class="field" style="margin-bottom:15px;">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Email</label>
        <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" style="width:100%;padding:11px;border:1px solid #ccd7d1;border-radius:9px;font:inherit;">
      </div>
      <div class="field" style="margin-bottom:15px;">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Password</label>
        <input type="password" name="password" required style="width:100%;padding:11px;border:1px solid #ccd7d1;border-radius:9px;font:inherit;">
      </div>
      <button class="btn btn-amber" type="submit" style="width:100%;padding:12px;border:0;border-radius:9px;font-weight:700;cursor:pointer;">Sign in</button>
    </form>
    <p style="margin-top:16px;font-size:13.5px;color:var(--muted);">New here? <a href="register.php" style="font-weight:700;color:var(--ink);">Create an account</a></p>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
