<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth_flows.php';

$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_name = trim($_POST['business_name'] ?? '');
    $email = auth_norm((string)($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $business_description = trim($_POST['business_description'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!auth_csrf_ok()) {
        $error = 'Your page expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        $error = 'Could not submit the application.';
    } elseif ($business_name === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (($pe = auth_password_error($password)) !== null) {
        $error = $pe;
    } elseif ($password !== (string)($_POST['password2'] ?? '')) {
        $error = 'The two passwords do not match.';
    } elseif (auth_locked($pdo, 'seller', auth_ip(), 'signup')) {
        $error = 'Too many applications from this device. Please wait a few minutes.';
    } else {
        auth_record($pdo, 'seller', auth_ip(), 'signup');
        $check = $pdo->prepare('SELECT id FROM sellers WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with that email already exists. Try signing in or resetting your password.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO sellers (business_name, email, password_hash, phone, business_description, status) VALUES (?, ?, ?, ?, ?, "pending")');
            $stmt->execute([$business_name, $email, password_hash($password, PASSWORD_DEFAULT), $phone, $business_description]);
            $success = true;
        }
    }
}

auth_page_start('Sell on IANOVA', 'Sell on IANOVA', 'Apply for a seller account. We review every application.', 'Seller area', '../');
if ($success) {
    auth_notice("Thanks! Your application has been submitted. We'll review it and email you once approved.");
    echo '<p class="auth-foot"><a href="login.php">Go to sign in</a></p>';
} else {
    auth_error($error); ?>
<form method="post" autocomplete="on">
  <?= auth_csrf_field() ?>
  <input class="auth-hp" tabindex="-1" autocomplete="off" name="website">
  <div class="auth-field"><label for="bn">Business name</label><input id="bn" name="business_name" type="text" required value="<?= auth_h($_POST['business_name'] ?? '') ?>"></div>
  <div class="auth-field"><label for="email">Email</label><input id="email" type="email" name="email" required autocomplete="email" value="<?= auth_h($_POST['email'] ?? '') ?>"></div>
  <div class="auth-field"><label for="ph">Phone</label><input id="ph" name="phone" type="tel" autocomplete="tel" value="<?= auth_h($_POST['phone'] ?? '') ?>"></div>
  <div class="auth-field"><label for="bd">Tell us about your business</label><textarea id="bd" name="business_description" rows="4" placeholder="What do you sell, and what makes your business a good fit for IANOVA?"><?= auth_h($_POST['business_description'] ?? '') ?></textarea></div>
  <?php auth_pw_field('password', 'Password', true, 'new-password'); auth_pw_field('password2', 'Confirm password', false, 'new-password'); ?>
  <button class="auth-btn" type="submit">Apply to sell</button>
</form>
<p class="auth-foot">Already have a seller account? <a href="login.php">Sign in</a></p>
<?php }
auth_page_end();
