<?php
require 'config.php';
require 'includes/auth_flows.php';

$error = '';
if (!empty($_SESSION['user_id'])) { header('Location: account.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = auth_norm((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!auth_csrf_ok()) {
        $error = 'Your page expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        $error = 'Could not create the account.';
    } elseif ($name === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (($password_error = auth_password_error($password)) !== null) {
        $error = $password_error;
    } elseif ($password !== (string)($_POST['password2'] ?? '')) {
        $error = 'The two passwords do not match.';
    } elseif (auth_locked($pdo, 'user', $email, 'signup') || auth_locked($pdo, 'user', auth_ip(), 'signup')) {
        $error = 'Too many sign-up attempts. Please wait a few minutes.';
    } else {
        auth_record($pdo, 'user', auth_ip(), 'signup');
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with that email already exists. Try signing in or resetting your password.';
        } else {
            $pdo->prepare('INSERT INTO users(name, email, password_hash) VALUES (?, ?, ?)')
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['user_name'] = $name;
            header('Location: account.php');
            exit;
        }
    }
}

auth_page_start('Create account', 'Create your account', 'Shop faster, track orders and save your favourites.');
auth_error($error);
?>
<form method="post" autocomplete="on">
  <?= auth_csrf_field() ?>
  <input class="auth-hp" tabindex="-1" autocomplete="off" name="website">
  <div class="auth-field"><label for="name">Full name</label><input id="name" name="name" type="text" required autocomplete="name" value="<?= auth_h($_POST['name'] ?? '') ?>"></div>
  <div class="auth-field"><label for="email">Email</label><input id="email" type="email" name="email" required autocomplete="email" value="<?= auth_h($_POST['email'] ?? '') ?>"></div>
  <?php auth_pw_field('password', 'Password', true, 'new-password'); auth_pw_field('password2', 'Confirm password', false, 'new-password'); ?>
  <button class="auth-btn" type="submit">Create account</button>
</form>
<p class="auth-foot">Already have an account? <a href="login.php">Sign in</a></p>
<?php auth_page_end();
