<?php
/**
 * IANOVA sign-in security + password reset (shared by website, seller, admin and the mobile API).
 * kind: 'user' | 'seller' | 'admin'
 */
if (defined('AUTH_SECURITY_LOADED')) return;
define('AUTH_SECURITY_LOADED', 1);

const AUTH_MAX_FAILS = 5;       // wrong passwords allowed per email ...
const AUTH_WINDOW_MIN = 15;     // ... within this many minutes
const AUTH_MAX_FAILS_IP = 25;   // per device/IP
const AUTH_RESET_MINUTES = 30;
const AUTH_CODE_TRIES = 5;

function auth_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(12) NOT NULL, ident VARCHAR(190) NOT NULL, ip VARCHAR(45) NOT NULL, what VARCHAR(8) NOT NULL DEFAULT 'login', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k1 (kind, ident, what, created_at), KEY k2 (ip, what, created_at)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(12) NOT NULL, account_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, code_hash CHAR(64) NOT NULL, tries INT NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k1 (kind, account_id)) ENGINE=InnoDB");
    if (random_int(1, 40) === 1) {
        $pdo->exec("DELETE FROM auth_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
        $pdo->exec("DELETE FROM password_resets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    }
}

function auth_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45); }
function auth_norm(string $email): string { return strtolower(trim($email)); }

/** Minutes the person must wait, or 0 when sign-in is allowed. */
function auth_locked(PDO $pdo, string $kind, string $email, string $what = 'login'): int {
    auth_ensure($pdo);
    $email = auth_norm($email);
    $q = $pdo->prepare("SELECT COUNT(*), MIN(created_at) FROM auth_attempts WHERE kind=? AND ident=? AND what=? AND created_at > DATE_SUB(NOW(), INTERVAL " . AUTH_WINDOW_MIN . " MINUTE)");
    $q->execute([$kind, $email, $what]);
    [$n, $first] = $q->fetch(PDO::FETCH_NUM);
    $limit = $what === 'login' ? AUTH_MAX_FAILS : 3;
    $q2 = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ip=? AND what=? AND created_at > DATE_SUB(NOW(), INTERVAL " . AUTH_WINDOW_MIN . " MINUTE)");
    $q2->execute([auth_ip(), $what]);
    $ipn = (int)$q2->fetchColumn();
    if ((int)$n >= $limit || $ipn >= AUTH_MAX_FAILS_IP) {
        $left = $first ? AUTH_WINDOW_MIN - (int)floor((time() - strtotime($first)) / 60) : AUTH_WINDOW_MIN;
        return max(1, min(AUTH_WINDOW_MIN, $left));
    }
    return 0;
}
function auth_lock_message(int $min): string {
    return "Too many attempts. For your security, please wait $min minute" . ($min === 1 ? '' : 's') . " and try again, or reset your password.";
}
function auth_record(PDO $pdo, string $kind, string $email, string $what = 'login'): void {
    auth_ensure($pdo);
    $pdo->prepare("INSERT INTO auth_attempts (kind, ident, ip, what) VALUES (?,?,?,?)")->execute([$kind, auth_norm($email), auth_ip(), $what]);
}
function auth_clear(PDO $pdo, string $kind, string $email): void {
    auth_ensure($pdo);
    $pdo->prepare("DELETE FROM auth_attempts WHERE kind=? AND ident=? AND what='login'")->execute([$kind, auth_norm($email)]);
}

/** null when OK, otherwise a message. 8+ characters, with a letter and a number. */
function auth_password_error(string $pw): ?string {
    if (strlen($pw) < 8) return 'Password must be at least 8 characters.';
    if (strlen($pw) > 200) return 'Password is too long.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use at least one letter and one number in your password.';
    return null;
}

// ---- CSRF for website forms ----
function auth_csrf(): string {
    if (empty($_SESSION['auth_csrf'])) $_SESSION['auth_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['auth_csrf'];
}
function auth_csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(auth_csrf(), ENT_QUOTES) . '">'; }
function auth_csrf_ok(): bool {
    return !empty($_SESSION['auth_csrf']) && hash_equals($_SESSION['auth_csrf'], (string)($_POST['_csrf'] ?? ''));
}

/** Only allow redirects to a page on this site. */
function auth_safe_redirect(string $to, string $fallback): string {
    $to = trim($to);
    if ($to === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//|\\\\)#i', $to) || strpbrk($to, "\r\n") !== false) return $fallback;
    return $to;
}

// ---- Password reset ----
function auth_kind_info(string $kind): ?array {
    $map = [
        'user'   => ['users',   'name',          'api_tokens',        'user_id',   ''],
        'seller' => ['sellers', 'business_name', 'seller_api_tokens', 'seller_id', 'seller/'],
        'admin'  => ['admins',  'name',          'admin_tokens',      'admin_id',  'admin/'],
    ];
    return $map[$kind] ?? null;
}

function auth_site_url(): string {
    if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080');
}

/** Always behaves the same for known and unknown emails (no account guessing). */
function auth_reset_start(PDO $pdo, string $kind, string $email): void {
    auth_ensure($pdo);
    $info = auth_kind_info($kind);
    $email = auth_norm($email);
    if (!$info || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    if (auth_locked($pdo, $kind, $email, 'reset')) return;
    auth_record($pdo, $kind, $email, 'reset');
    [$tbl, $namecol] = $info;
    $s = $pdo->prepare("SELECT id, $namecol AS nm, email FROM $tbl WHERE email = ? LIMIT 1");
    $s->execute([$email]);
    $a = $s->fetch();
    if (!$a) return;
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE kind=? AND account_id=? AND used_at IS NULL")->execute([$kind, (int)$a['id']]);
    $token = bin2hex(random_bytes(32));
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO password_resets (kind, account_id, token_hash, code_hash, expires_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL " . AUTH_RESET_MINUTES . " MINUTE))")
        ->execute([$kind, (int)$a['id'], hash('sha256', $token), hash('sha256', $code . ':' . $kind . ':' . $a['id'])]);
    $link = auth_site_url() . '/' . $info[4] . 'reset.php?token=' . $token;
    if (!function_exists('send_mail')) require_once __DIR__ . '/mailer.php';
    $site = defined('SITE_NAME') ? SITE_NAME : 'IANOVA';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $html = '<div style="font-family:Arial,sans-serif;color:#0F0F12;max-width:520px;line-height:1.5;">'
        . '<h2>Reset your ' . $e($site) . ' password</h2>'
        . '<p>Hello ' . $e($a['nm']) . ', we received a request to reset your password.</p>'
        . '<p><a href="' . $e($link) . '" style="display:inline-block;background:#0B1B3F;color:#fff;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:700;">Choose a new password</a></p>'
        . '<p>Using the app? Enter this code instead:</p>'
        . '<p style="font-size:30px;letter-spacing:8px;font-weight:800;background:#F3F4F7;border-radius:14px;padding:12px 18px;display:inline-block;">' . $code . '</p>'
        . '<p style="color:#5F6575;font-size:13px;">The link and code work for ' . AUTH_RESET_MINUTES . ' minutes. If you did not ask for this, ignore this email — your password stays the same.</p>'
        . '<p style="margin-top:24px;color:#5F6575;font-size:13px;">— ' . $e($site) . ' team</p></div>';
    send_mail($a['email'], (string)$a['nm'], 'Reset your ' . $site . ' password', $html);
}

function auth_reset_token_valid(PDO $pdo, string $kind, string $token): bool {
    auth_ensure($pdo);
    $s = $pdo->prepare("SELECT id FROM password_resets WHERE kind=? AND token_hash=? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $s->execute([$kind, hash('sha256', $token)]);
    return (bool)$s->fetchColumn();
}

function auth_apply_new_password(PDO $pdo, string $kind, int $accountId, int $resetId, string $pw): void {
    [$tbl, , $ttbl, $tcol] = auth_kind_info($kind);
    $pdo->prepare("UPDATE $tbl SET password_hash=? WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), $accountId]);
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id=?")->execute([$resetId]);
    try { $pdo->prepare("DELETE FROM $ttbl WHERE $tcol=?")->execute([$accountId]); } catch (Throwable $e) {}   // sign out other devices
}

/** Reset using the emailed link. Returns an error message or null on success. */
function auth_reset_with_token(PDO $pdo, string $kind, string $token, string $pw): ?string {
    if ($e = auth_password_error($pw)) return $e;
    auth_ensure($pdo);
    $s = $pdo->prepare("SELECT id, account_id FROM password_resets WHERE kind=? AND token_hash=? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $s->execute([$kind, hash('sha256', $token)]);
    $r = $s->fetch();
    if (!$r) return 'This reset link has expired or was already used. Please request a new one.';
    auth_apply_new_password($pdo, $kind, (int)$r['account_id'], (int)$r['id'], $pw);
    return null;
}

/** Reset using the 6-digit code (mobile app). */
function auth_reset_with_code(PDO $pdo, string $kind, string $email, string $code, string $pw): ?string {
    if ($e = auth_password_error($pw)) return $e;
    auth_ensure($pdo);
    $info = auth_kind_info($kind);
    $bad = 'That code is not correct or has expired. Request a new one.';
    if (!$info) return $bad;
    $s = $pdo->prepare("SELECT id FROM {$info[0]} WHERE email=? LIMIT 1");
    $s->execute([auth_norm($email)]);
    $aid = (int)$s->fetchColumn();
    if (!$aid) return $bad;
    $q = $pdo->prepare("SELECT id, code_hash, tries FROM password_resets WHERE kind=? AND account_id=? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $q->execute([$kind, $aid]);
    $r = $q->fetch();
    if (!$r || (int)$r['tries'] >= AUTH_CODE_TRIES) return $bad;
    $code = preg_replace('/\D/', '', $code);
    if (!hash_equals($r['code_hash'], hash('sha256', $code . ':' . $kind . ':' . $aid))) {
        $pdo->prepare("UPDATE password_resets SET tries = tries + 1 WHERE id=?")->execute([$r['id']]);
        return $bad;
    }
    auth_apply_new_password($pdo, $kind, $aid, (int)$r['id'], $pw);
    return null;
}
