<?php
require 'config.php';
require 'includes/functions.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users(name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, $hash]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['user_name'] = $name;
            header('Location: account.php');
            exit;
        }
    }
}

$page_title = 'Create account';
require 'includes/header.php';
?>

<section class="wrap" style="max-width:440px;margin:48px auto;">
  <div class="card form-card" style="padding:28px;border:1px solid var(--line);border-radius:14px;background:#fff;">
    <h1 style="margin-top:0;">Create your account</h1>
    <?php if ($error): ?>
      <div class="notice error" style="background:#fdeaea;color:#8a2424;padding:12px 14px;border-radius:9px;margin-bottom:16px;"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="field" style="margin-bottom:15px;">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Full name</label>
        <input name="name" required value="<?= h($_POST['name'] ?? '') ?>" style="width:100%;padding:11px;border:1px solid #ccd7d1;border-radius:9px;font:inherit;">
      </div>
      <div class="field" style="margin-bottom:15px;">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Email</label>
        <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" style="width:100%;padding:11px;border:1px solid #ccd7d1;border-radius:9px;font:inherit;">
      </div>
      <div class="field" style="margin-bottom:15px;">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Password</label>
        <input type="password" name="password" required minlength="6" style="width:100%;padding:11px;border:1px solid #ccd7d1;border-radius:9px;font:inherit;">
      </div>
      <button class="btn btn-amber" type="submit" style="width:100%;padding:12px;border:0;border-radius:9px;font-weight:700;cursor:pointer;">Create account</button>
    </form>
    <p style="margin-top:16px;font-size:13.5px;color:var(--muted);">Already have an account? <a href="login.php" style="font-weight:700;color:var(--ink);">Sign in</a></p>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
