<?php
/** Full pages for sign in, forgot password and reset — one implementation for shopper, seller and admin. */
require_once __DIR__ . '/auth_ui.php';

function auth_cfg(string $kind): array {
    return [
        'user'   => ['root' => '',    'badge' => '',          'head' => 'Welcome back',  'sub' => 'Sign in to continue shopping.', 'home' => 'account.php', 'register' => 'register.php', 'reg_text' => 'New here? ', 'reg_link' => 'Create an account'],
        'seller' => ['root' => '../', 'badge' => 'Seller area', 'head' => 'Seller sign in', 'sub' => 'Manage your products and orders.',  'home' => 'index.php',   'register' => 'register.php', 'reg_text' => 'New seller? ', 'reg_link' => 'Apply here'],
        'admin'  => ['root' => '../', 'badge' => 'Admin',      'head' => 'Admin sign in',  'sub' => 'Administrator control panel.',    'home' => 'index.php',   'register' => '',             'reg_text' => '', 'reg_link' => ''],
    ][$kind];
}

function auth_login_page(PDO $pdo, string $kind): void {
    $c = auth_cfg($kind);
    $error = ''; $notice = '';
    if ($kind === 'admin') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,email VARCHAR(160) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,last_login DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    }
    $sessKey = ['user' => 'user_id', 'seller' => 'seller_id', 'admin' => 'admin_id'][$kind];
    if (!empty($_SESSION[$sessKey])) { header('Location: ' . ($kind === 'user' ? $c['home'] : 'index.php')); exit; }
    $redirect = auth_safe_redirect((string)($_POST['redirect'] ?? $_GET['redirect'] ?? ''), $c['home']);
    if (!empty($_GET['reset'])) $notice = 'Password changed. Sign in with your new password.';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = auth_norm((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $wait = $email !== '' ? auth_locked($pdo, $kind, $email) : 0;
        if (!auth_csrf_ok()) {
            $error = 'Your page expired. Please try again.';
        } elseif ($email === '' || $pass === '') {
            $error = 'Please enter both email and password.';
        } elseif ($wait) {
            $error = auth_lock_message($wait);
        } else {
            $tbl = ['user' => 'users', 'seller' => 'sellers', 'admin' => 'admins'][$kind];
            $s = $pdo->prepare("SELECT * FROM $tbl WHERE email = ? LIMIT 1");
            $s->execute([$email]);
            $a = $s->fetch();
            $hash = $a['password_hash'] ?? '';
            // verify against a dummy hash when the account is missing so timing does not reveal it
            $ok = password_verify($pass, $hash !== '' ? $hash : '$2y$10$rdliT4eBC/K2GG7vipzPd.XV5M9Rz220vqRXdghMzCkIvt8SsAsfW');
            if (!$a || $hash === '' || !$ok) {
                auth_record($pdo, $kind, $email);
                $error = 'Incorrect email or password.';
                if (auth_locked($pdo, $kind, $email)) $error = auth_lock_message(auth_locked($pdo, $kind, $email));
            } elseif ($kind === 'admin' && (include_once __DIR__ . '/admin_team.php') && ($error = team_login_block($pdo, $a)) !== '') {
                // pending or suspended admin: message set above
            } elseif ($kind === 'seller' && in_array($a['status'], ['pending', 'suspended', 'rejected'], true)) {
                $msgs = ['pending' => 'Your application is still under review. We will email you once approved.', 'suspended' => 'Your seller account has been suspended. Contact support for more information.', 'rejected' => 'Your seller application was not approved.'];
                $error = $msgs[$a['status']];
            } else {
                auth_clear($pdo, $kind, $email);
                session_regenerate_id(true);
                if ($kind === 'user') { $_SESSION['user_id'] = $a['id']; $_SESSION['user_name'] = $a['name']; }
                if ($kind === 'seller') { $_SESSION['seller_id'] = $a['id']; $_SESSION['seller_name'] = $a['business_name']; }
                if ($kind === 'admin') {
                    $_SESSION['admin_id'] = $a['id']; $_SESSION['admin_name'] = $a['name']; $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
                    $pdo->prepare("UPDATE admins SET last_login=NOW() WHERE id=?")->execute([$a['id']]);
                }
                header('Location: ' . ($kind === 'user' ? $redirect : 'index.php')); exit;
            }
        }
    }
    auth_page_start('Sign in', $c['head'], $c['sub'], $c['badge'], $c['root']);
    if ($kind === 'admin' && !(int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn()) {
        echo '<div class="auth-err"><b>No administrator exists yet.</b> Open <code>admin/setup.php</code> once to create the first admin.</div>';
    }
    auth_notice($notice); auth_error($error);
    echo '<form method="post" autocomplete="on">' . auth_csrf_field() . '<input type="hidden" name="redirect" value="' . auth_h($redirect) . '">'
       . '<div class="auth-field"><label for="email">Email</label><input id="email" type="email" name="email" required autocomplete="email" value="' . auth_h($_POST['email'] ?? '') . '"></div>';
    auth_pw_field('password', 'Password');
    echo '<div class="auth-row"><span></span><a href="forgot.php">Forgot password?</a></div>'
       . '<button class="auth-btn" type="submit">Sign in</button></form>';
    if ($c['register'] !== '') echo '<p class="auth-foot">' . $c['reg_text'] . '<a href="' . $c['register'] . '">' . $c['reg_link'] . '</a></p>';
    auth_page_end();
}

function auth_forgot_page(PDO $pdo, string $kind): void {
    $c = auth_cfg($kind);
    $sent = false; $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!auth_csrf_ok()) { $error = 'Your page expired. Please try again.'; }
        elseif (!empty($_POST['website'])) { $sent = true; }                      // bots fill the hidden field
        else {
            $email = (string)($_POST['email'] ?? '');
            if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
            else { auth_reset_start($pdo, $kind, $email); $sent = true; }
        }
    }
    auth_page_start('Forgot password', 'Forgot your password?', 'Enter your email and we will send you a link to choose a new one.', $c['badge'], $c['root']);
    if ($sent) {
        auth_notice('If that email has an account, a reset link is on its way. It works for ' . AUTH_RESET_MINUTES . ' minutes. Check your spam folder too.');
    } else {
        auth_error($error);
        echo '<form method="post">' . auth_csrf_field()
           . '<input class="auth-hp" tabindex="-1" autocomplete="off" name="website">'
           . '<div class="auth-field"><label for="email">Email</label><input id="email" type="email" name="email" required autocomplete="email" value="' . auth_h($_POST['email'] ?? '') . '"></div>'
           . '<button class="auth-btn" type="submit">Send reset link</button></form>';
    }
    echo '<p class="auth-foot"><a href="login.php">Back to sign in</a></p>';
    auth_page_end();
}

function auth_reset_page(PDO $pdo, string $kind): void {
    $c = auth_cfg($kind);
    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $error = '';
    $valid = $token !== '' && auth_reset_token_valid($pdo, $kind, $token);
    if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!auth_csrf_ok()) $error = 'Your page expired. Please try again.';
        elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) $error = 'The two passwords do not match.';
        else {
            $error = auth_reset_with_token($pdo, $kind, $token, (string)$_POST['password']) ?? '';
            if ($error === '') { header('Location: login.php?reset=1'); exit; }
        }
    }
    auth_page_start('Reset password', 'Choose a new password', '', $c['badge'], $c['root']);
    if (!$valid) {
        auth_error('This reset link has expired or was already used.');
        echo '<p class="auth-foot"><a href="forgot.php">Send me a new link</a></p>';
    } else {
        auth_error($error);
        echo '<form method="post">' . auth_csrf_field() . '<input type="hidden" name="token" value="' . auth_h($token) . '">';
        auth_pw_field('password', 'New password', true, 'new-password');
        auth_pw_field('password2', 'Confirm new password', false, 'new-password');
        echo '<button class="auth-btn" type="submit">Save new password</button></form>';
    }
    echo '<p class="auth-foot"><a href="login.php">Back to sign in</a></p>';
    auth_page_end();
}
