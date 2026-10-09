<?php
require_once __DIR__ . '/../config.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_name = trim($_POST['business_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $business_description = trim($_POST['business_description'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($business_name === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = $pdo->prepare('SELECT id FROM sellers WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO sellers (business_name, email, password_hash, phone, business_description, status) VALUES (?, ?, ?, ?, ?, "pending")');
            $stmt->execute([$business_name, $email, $hash, $phone, $business_description]);
            $success = true;
        }
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sell on IANOVA</title><style>
body{font-family:Inter,Arial,sans-serif;background:#FAFAFB;margin:0;color:#0F0F12}
.wrap{max-width:440px;margin:8vh auto;background:#fff;padding:30px;border-radius:28px;border:1px solid #E9EBEF;box-shadow:0 20px 50px -30px rgba(11,30,23,.3)}
h1{margin-top:0}
label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
input{width:100%;padding:11px;margin-bottom:15px;box-sizing:border-box;border:1px solid #E9EBEF;border-radius:16px;font:inherit}
button{width:100%;padding:14px;background:#0F0F12;color:#fff;border:0;border-radius:16px;font-weight:700;cursor:pointer}
.err{background:#FFF1F4;color:#E5173F;padding:12px 14px;border-radius:16px;margin-bottom:16px}
.ok{background:#F3F4F7;padding:14px;border-radius:16px}
a{color:#0F0F12;font-weight:700}
</style></head><body>
<div class="wrap">
<h1>Sell on IANOVA</h1>
<?php if ($success): ?>
  <div class="ok">Thanks! Your application has been submitted. We'll review it and email you once approved.</div>
<?php else: ?>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Business name</label>
    <input name="business_name" required value="<?= h($_POST['business_name'] ?? '') ?>">
    <label>Email</label>
    <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>">
    <label>Phone</label>
    <input name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
    <label>Tell us about your business</label>
    <textarea name="business_description" rows="4" placeholder="What do you sell, and what makes your business a good fit for IANOVA?" style="width:100%;padding:11px;margin-bottom:15px;box-sizing:border-box;border:1px solid #E9EBEF;border-radius:16px;font:inherit;resize:vertical;"><?= h($_POST['business_description'] ?? '') ?></textarea>
    <label>Password</label>
    <input type="password" name="password" required minlength="6">
    <button type="submit">Apply to sell</button>
  </form>
  <p style="margin-top:16px;font-size:13.5px;">Already have a seller account? <a href="login.php">Sign in</a></p>
<?php endif; ?>
</div>
</body></html>
