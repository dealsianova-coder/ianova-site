<?php
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['seller_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM sellers WHERE email = ?');
    $stmt->execute([$email]);
    $seller = $stmt->fetch();

    if (!$seller || !password_verify($password, $seller['password_hash'])) {
        $error = 'Incorrect email or password.';
    } elseif ($seller['status'] === 'pending') {
        $error = 'Your application is still under review. We will email you once approved.';
    } elseif ($seller['status'] === 'suspended') {
        $error = 'Your seller account has been suspended. Contact support for more information.';
    } elseif ($seller['status'] === 'rejected') {
        $error = 'Your seller application was not approved.';
    } else {
        $_SESSION['seller_id'] = $seller['id'];
        $_SESSION['seller_name'] = $seller['business_name'];
        header('Location: index.php');
        exit;
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Seller Sign In — IANOVA</title><style>
body{font-family:Inter,Arial,sans-serif;background:#FAFAFB;margin:0;color:#0F0F12}
.wrap{max-width:420px;margin:10vh auto;background:#fff;padding:30px;border-radius:28px;border:1px solid #E9EBEF;box-shadow:0 20px 50px -30px rgba(11,30,23,.3)}
h1{margin-top:0}
label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
input{width:100%;padding:11px;margin-bottom:15px;box-sizing:border-box;border:1px solid #E9EBEF;border-radius:16px;font:inherit}
button{width:100%;padding:14px;background:#0F0F12;color:#fff;border:0;border-radius:16px;font-weight:700;cursor:pointer}
.err{background:#FFF1F4;color:#E5173F;padding:12px 14px;border-radius:16px;margin-bottom:16px}
a{color:#0F0F12;font-weight:700}
</style></head><body>
<div class="wrap">
<h1>Seller sign in</h1>
<?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
<form method="post">
<label>Email</label>
<input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>">
<label>Password</label>
<input type="password" name="password" required>
<button type="submit">Sign in</button>
</form>
<p style="margin-top:16px;font-size:13.5px;">New seller? <a href="register.php">Apply here</a></p>
</div>
</body></html>
