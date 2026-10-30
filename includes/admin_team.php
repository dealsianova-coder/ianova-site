<?php
// Admin team: one master admin, optional extra admins who need the master's approval.
// A separate "security password" (different from the sign-in password) protects editing and adding admins.

function team_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // the table changes below only need to run once (remembered in ops_settings)
        $have = function_exists('ops_setting') && ops_setting($pdo, 'schema_team', '') === '5';
        if (!$have) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS status VARCHAR(12) NOT NULL DEFAULT 'active'");
            $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS is_master TINYINT NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS security_hash VARCHAR(255) NULL");
            $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS added_by INT NULL");
            $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS perms TEXT NULL");
            $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS twofa TINYINT NOT NULL DEFAULT 0");
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_log (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', action VARCHAR(60) NOT NULL, detail VARCHAR(300) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (created_at), KEY (admin_id)) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_sec_reset (admin_id INT PRIMARY KEY, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, requested_at DATETIME NOT NULL) ENGINE=InnoDB");
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tokens (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
            $pdo->exec("ALTER TABLE admin_tokens ADD COLUMN IF NOT EXISTS last_used DATETIME NULL");
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_sec_fail (admin_id INT PRIMARY KEY, fails INT NOT NULL DEFAULT 0, until_at DATETIME NULL) ENGINE=InnoDB");
        }
        if (!(int) $pdo->query('SELECT COUNT(*) FROM admins WHERE is_master = 1')->fetchColumn()) {
            $pdo->exec('UPDATE admins SET is_master = 1 WHERE id = (SELECT m FROM (SELECT MIN(id) m FROM admins) x)');
        }
        if (!$have && function_exists('ops_setting_set')) ops_setting_set($pdo, 'schema_team', '5');
    } catch (Throwable $e) { /* older MariaDB: columns may already exist */ }
}

function team_is_master(PDO $pdo, int $id): bool {
    team_ensure($pdo);
    $s = $pdo->prepare('SELECT is_master FROM admins WHERE id = ?'); $s->execute([$id]);
    return (int) $s->fetchColumn() === 1;
}

/** '' = may sign in, otherwise the message to show. */
function team_login_block(PDO $pdo, array $row): string {
    team_ensure($pdo);
    $s = $pdo->prepare('SELECT status FROM admins WHERE id = ?'); $s->execute([(int) ($row['id'] ?? 0)]);
    $st = (string) $s->fetchColumn();
    if ($st === 'pending') return 'This admin account is waiting for approval by the master admin.';
    if ($st === 'suspended') return 'This admin account has been suspended by the master admin.';
    return '';
}

function team_has_security(PDO $pdo, int $id): bool {
    team_ensure($pdo);
    $s = $pdo->prepare('SELECT security_hash FROM admins WHERE id = ?'); $s->execute([$id]);
    return (string) $s->fetchColumn() !== '';
}

/** '' = correct, otherwise an error message. */
function team_verify(PDO $pdo, int $id, string $pw): string {
    team_ensure($pdo);
    $s = $pdo->prepare('SELECT security_hash FROM admins WHERE id = ?'); $s->execute([$id]);
    $hash = (string) $s->fetchColumn();
    if ($hash === '') return 'Set your security password first (Users > Admin > Security).';
    $l = $pdo->prepare('SELECT until_at > NOW() FROM admin_sec_fail WHERE admin_id = ?'); $l->execute([$id]);
    if ((int) $l->fetchColumn() === 1) return 'Too many wrong attempts. Try again in a few minutes.';
    if ($pw !== '' && password_verify($pw, $hash)) {
        $pdo->prepare('DELETE FROM admin_sec_fail WHERE admin_id = ?')->execute([$id]);
        return '';
    }
    $pdo->prepare('INSERT INTO admin_sec_fail (admin_id, fails) VALUES (?, 1) ON DUPLICATE KEY UPDATE fails = fails + 1')->execute([$id]);
    $f = $pdo->prepare('SELECT fails FROM admin_sec_fail WHERE admin_id = ?'); $f->execute([$id]);
    if ((int) $f->fetchColumn() >= 5) $pdo->prepare('UPDATE admin_sec_fail SET fails = 0, until_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE admin_id = ?')->execute([$id]);
    usleep(700000);
    return 'Security password is incorrect.';
}

/** Set or change the security password. Needs the sign-in password the first time, the current security password afterwards. */
function team_set_security(PDO $pdo, int $id, string $proof, string $new): string {
    team_ensure($pdo);
    if (strlen($new) < 8) return 'The security password must be at least 8 characters.';
    $s = $pdo->prepare('SELECT password_hash, security_hash FROM admins WHERE id = ?'); $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'Admin not found.';
    if ((string) $r['security_hash'] === '') {
        if ($proof === '' || !password_verify($proof, (string) $r['password_hash'])) { usleep(700000); return 'Enter your sign-in password to set the security password.'; }
    } else {
        $m = team_verify($pdo, $id, $proof);
        if ($m !== '') return $m;
    }
    if (password_verify($new, (string) $r['password_hash'])) return 'The security password must be different from your sign-in password.';
    $pdo->prepare('UPDATE admins SET security_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
    team_log($pdo, $id, 'security_password_set', '');
    return '';
}

function team_email_taken(PDO $pdo, string $email, int $exceptAdmin = 0): bool {
    $q = $pdo->prepare('SELECT (SELECT COUNT(*) FROM users WHERE LOWER(email) = ?) + (SELECT COUNT(*) FROM sellers WHERE LOWER(email) = ?) + (SELECT COUNT(*) FROM admins WHERE LOWER(email) = ? AND id <> ?)');
    $q->execute([$email, $email, $email, $exceptAdmin]);
    return (int) $q->fetchColumn() > 0;
}

/** Edit your own admin account. '' = saved. */
function team_edit_self(PDO $pdo, int $id, string $name, string $email, string $newLoginPw, string $security, string $keepTokenHash = ''): string {
    $m = team_verify($pdo, $id, $security);
    if ($m !== '') return $m;
    $email = strtolower(trim($email)); $name = trim($name);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a name and a valid email for the admin.';
    if ($newLoginPw !== '' && strlen($newLoginPw) < 8) return 'The new sign-in password must be at least 8 characters.';
    if (team_email_taken($pdo, $email, $id)) return 'That email is already used by another account. The admin email must be unique.';
    $pdo->prepare('UPDATE admins SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]);
    if ($newLoginPw !== '') {
        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($newLoginPw, PASSWORD_DEFAULT), $id]);
        // sign out every other app session of this admin (the one making the change stays)
        try { $pdo->prepare('DELETE FROM admin_tokens WHERE admin_id = ? AND token_hash <> ?')->execute([$id, $keepTokenHash]); } catch (Throwable $e) {}
    }
    team_log($pdo, $id, 'account_edit', $newLoginPw !== '' ? 'name/email and sign-in password' : 'name/email');
    return '';
}

/** Master adds another admin; it stays pending until approved. '' = created. */
function team_add(PDO $pdo, int $masterId, string $name, string $email, string $loginPw, string $security, array $perms = []): string {
    if (!team_is_master($pdo, $masterId)) return 'Only the master admin can add admins.';
    $m = team_verify($pdo, $masterId, $security);
    if ($m !== '') return $m;
    $email = strtolower(trim($email)); $name = trim($name);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a name and a valid email for the new admin.';
    if (strlen($loginPw) < 8) return 'The new admin needs a sign-in password of at least 8 characters.';
    if (team_email_taken($pdo, $email)) return 'That email is already used by another account.';
    $pdo->prepare("INSERT INTO admins (name, email, password_hash, status, is_master, added_by, perms) VALUES (?, ?, ?, 'pending', 0, ?, ?)")
        ->execute([$name, $email, password_hash($loginPw, PASSWORD_DEFAULT), $masterId, team_clean_perms($perms)]);
    team_log($pdo, $masterId, 'admin_add', $email . ' (pending)');
    return '';
}

/** Master approves / rejects / suspends / restores / removes another admin. '' = done. */
function team_do(PDO $pdo, int $masterId, int $id, string $do, string $security): string {
    if (!team_is_master($pdo, $masterId)) return 'Only the master admin can do that.';
    $m = team_verify($pdo, $masterId, $security);
    if ($m !== '') return $m;
    if ($id === $masterId) return 'The master admin cannot be changed here.';
    $s = $pdo->prepare('SELECT status, is_master FROM admins WHERE id = ?'); $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int) $r['is_master'] === 1) return 'Admin not found.';
    team_log($pdo, $masterId, 'admin_' . $do, '#' . $id);
    if ($do === 'make_master') {
        if ($r['status'] !== 'active') return 'Only an active admin can become the master admin.';
        $pdo->prepare('UPDATE admins SET is_master = 0 WHERE id = ?')->execute([$masterId]);
        $pdo->prepare('UPDATE admins SET is_master = 1, perms = NULL WHERE id = ?')->execute([$id]);
        return '';
    }
    $kill = fn() => $pdo->prepare('DELETE FROM admin_tokens WHERE admin_id = ?')->execute([$id]);
    if ($do === 'approve' || $do === 'restore') { $pdo->prepare("UPDATE admins SET status = 'active' WHERE id = ?")->execute([$id]); return ''; }
    if ($do === 'suspend') { $pdo->prepare("UPDATE admins SET status = 'suspended' WHERE id = ?")->execute([$id]); try { $kill(); } catch (Throwable $e) {} return ''; }
    if ($do === 'reject' || $do === 'remove') { $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]); try { $kill(); } catch (Throwable $e) {} return ''; }
    return 'Unknown action.';
}

function team_list(PDO $pdo): array {
    team_ensure($pdo);
    $rows = $pdo->query("SELECT id, name, email, last_login, created_at, status, is_master, perms, twofa, (security_hash IS NOT NULL AND security_hash <> '') has_security FROM admins ORDER BY is_master DESC, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['allowed'] = team_allowed($r); unset($r['perms']); }
    return $rows;
}

// ---------------------------------------------------------------- what each admin can see
/** key => [label, web pages]. The master always sees everything; Admin team is open to every admin (own account). */
function team_sections(): array {
    return [
        'dashboard' => ['📊 Dashboard', ['index.php', 'revenue.php', 'trash.php', 'dash_customize.php']],
        'analytics' => ['📈 Analytics', ['analytics.php']],
        'products' => ['📦 Products', ['products.php', 'product_view.php', 'product_edit.php', 'product_approve.php', 'product_delete.php', 'product_hide.php', 'product_unhide.php', 'products_bulk.php', 'products_bulk_template.php']],
        'categories' => ['🏷️ Categories', ['categories.php']],
        'orders' => ['🛒 Orders', ['orders.php', 'orders_bulk.php', 'order_view.php', 'order_item.php', 'order_note.php', 'order_ops.php', 'invoice.php', 'export_orders.php']],
        'users' => ['👥 Users (sellers & customers)', ['users.php']],
        'sellers' => ['🏪 Sellers', ['sellers.php', 'seller_messages.php']],
        'broadcast' => ['📢 Broadcast', ['broadcast.php']],
        'subscribers' => ['✉️ Subscribers', ['subscribers.php']],
        'suggestions' => ['💡 Suggestions', ['suggestions.php']],
        'email' => ['📧 Email alerts', ['email.php']],
        'banners' => ['🖼️ Banners', ['banners.php']],
        'video' => ['🎬 Video ad', ['video_ad.php']],
        'store' => ['🎛️ Store control', ['store.php']],
        'display' => ['🖥️ Display control', ['display.php']],
        'flash' => ['⚡ Flash deals', ['flash.php']],
        'coupons' => ['🎟️ Coupons', ['coupons.php']],
        'payouts' => ['💸 Seller payouts', ['payouts.php']],
        'payments' => ['💳 Payments & deposits', ['payments.php']],
        'settings' => ['⚙️ Settings', ['settings.php']],
        'alerts' => ['🔔 Alerts', ['notifications.php']],
    ];
}

function team_clean_perms(array $perms): string {
    $ok = array_keys(team_sections());
    return implode(',', array_values(array_intersect($ok, array_map('strval', $perms))));
}

/** Allowed section keys for an admin row (needs is_master and perms). NULL perms = legacy admin with full access. */
function team_allowed(array $row): array {
    $all = array_keys(team_sections());
    if ((int) ($row['is_master'] ?? 0) === 1 || !array_key_exists('perms', $row) || $row['perms'] === null) return $all;
    return array_values(array_intersect($all, array_filter(explode(',', (string) $row['perms']))));
}

function team_can(PDO $pdo, int $id, string $section): bool {
    team_ensure($pdo);
    $s = $pdo->prepare('SELECT is_master, perms FROM admins WHERE id = ?'); $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? in_array($section, team_allowed($r), true) : false;
}

function team_allowed_for(PDO $pdo, int $id): array {
    team_ensure($pdo);
    $s = $pdo->prepare('SELECT is_master, perms FROM admins WHERE id = ?'); $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? team_allowed($r) : [];
}

function team_set_perms(PDO $pdo, int $masterId, int $id, array $perms, string $security): string {
    if (!team_is_master($pdo, $masterId)) return 'Only the master admin can change what other admins see.';
    $m = team_verify($pdo, $masterId, $security);
    if ($m !== '') return $m;
    $s = $pdo->prepare('SELECT is_master FROM admins WHERE id = ?'); $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int) $r['is_master'] === 1 || $id === $masterId) return 'Admin not found.';
    $pdo->prepare('UPDATE admins SET perms = ? WHERE id = ?')->execute([team_clean_perms($perms), $id]);
    team_log($pdo, $masterId, 'admin_access', '#' . $id . ': ' . team_clean_perms($perms));
    return '';
}

/** Which section an API action belongs to (null = not restricted). $file is "panel" (admin_panel.php) or "admin" (admin.php). */
function team_section_of_action(string $file, string $a): ?string {
    if ($file === 'admin') {
        $m = ['summary' => 'dashboard', 'products' => 'products', 'product_update' => 'products', 'orders' => 'orders', 'order_status' => 'orders',
              'store_get' => 'store', 'store_set' => 'store', 'alerts' => 'alerts', 'alerts_read' => 'alerts', 'broadcast_options' => 'broadcast', 'broadcast_send' => 'broadcast'];
        if (isset($m[$a])) return $m[$a];
        return strpos($a, 'seller_') === 0 ? 'sellers' : null;
    }
    $exact = ['dashboard' => 'dashboard', 'revenue_report' => 'dashboard', 'trash_list' => 'dashboard', 'dash_prefs_save' => 'dashboard', 'dash_prefs_get' => 'dashboard', 'revenue_reset_do' => 'dashboard', 'revenue_restore_do' => 'dashboard', 'revenue_trash_do' => 'dashboard', 'revenue_trash_restore_do' => 'dashboard', 'analytics' => 'analytics', 'orders_list' => 'orders', 'order_view' => 'orders', 'order_notes' => 'orders', 'order_note_add' => 'orders', 'order_tracking_save' => 'orders', 'order_refund_add' => 'orders', 'order_item_cancel' => 'orders', 'order_item_status' => 'orders', 'orders_bulk_status' => 'orders', 'order_payment_add' => 'orders', 'order_payment_delete' => 'orders', 'bulk_import' => 'products',
        'users_list' => 'users', 'user_save' => 'users', 'user_delete' => 'users', 'sellers_full' => 'sellers', 'seller_action' => 'sellers', 'auto_master' => 'sellers',
        'subscribers_list' => 'subscribers', 'subscriber_delete' => 'subscribers', 'suggestions_list' => 'suggestions', 'suggestion_reviewed' => 'suggestions',
        'alerts_tab' => 'alerts', 'alerts_tab_read' => 'alerts', 'settings_get' => 'settings', 'settings_set' => 'settings'];
    if (isset($exact[$a])) return $exact[$a];
    foreach (['product' => 'products', 'category' => 'categories', 'categories' => 'categories', 'banner' => 'banners', 'flash' => 'flash', 'display' => 'display', 'video_ad' => 'video', 'mail' => 'email', 'coupon' => 'coupons', 'payout' => 'payouts', 'payment' => 'payments'] as $pre => $sec) {
        if (strpos($a, $pre . '_') === 0 || $a === $pre . 's_list') return $sec;
    }
    return null;
}

/** Website admin: block pages the admin may not see and hide their menu links. */
function team_page_guard(PDO $pdo, int $id): void {
    team_ensure($pdo);
    $allowed = team_allowed_for($pdo, $id);
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $hide = [];
    if (!team_is_master($pdo, $id)) { $hide[] = preg_quote('activity.php', '~'); if ($page === 'activity.php') { http_response_code(403); exit('Only the master admin can see the activity log.'); } }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !in_array($page, ['logout.php', 'login.php'], true)) {
        $d = []; foreach (['kind', 'do', 'id', 'status', 'order_id', 'action'] as $k) if (isset($_POST[$k]) && is_scalar($_POST[$k])) $d[] = $k . '=' . substr((string) $_POST[$k], 0, 40);
        team_log($pdo, $id, 'web:' . $page, implode(' ', $d));
    }
    foreach (team_sections() as $key => $def) {
        foreach ($def[1] as $f) {
            if (!in_array($key, $allowed, true)) { $hide[] = preg_quote($f, '~'); if ($f === $page) $block = $key; }
        }
    }
    if (isset($block)) {
        if ($block === 'dashboard') {
            foreach (team_sections() as $k => $def) if (in_array($k, $allowed, true)) { header('Location: ' . $def[1][0]); exit; }
            if (!headers_sent()) http_response_code(403);
            exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;padding:40px;text-align:center"><h2>🔒 No access yet</h2><p>The master admin has not given you access to any section yet.</p><p><a href="admins.php">Admin team</a> · <a href="logout.php">Sign out</a></p></body>');
        }
        http_response_code(403);
        exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;padding:40px;text-align:center"><h2>🔒 You do not have access to this section</h2><p>Ask the master admin if you need it.</p><p><a href="index.php">Back</a></p></body>');
    }
    if ($hide) {
        $re = '~<a\b[^>]*href="(?:' . implode('|', $hide) . ')(?:\?[^"]*)?"[^>]*>.*?</a>~is';
        ob_start(function ($html) use ($re) { return preg_replace($re, '', $html) ?? $html; });
    }
}

// ---------------------------------------------------------------- activity log
function team_log(PDO $pdo, int $adminId, string $action, string $detail = ''): void {
    try {
        team_ensure($pdo);
        $n = $pdo->prepare('SELECT name FROM admins WHERE id = ?'); $n->execute([$adminId]);
        $pdo->prepare('INSERT INTO admin_log (admin_id, admin_name, action, detail, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$adminId, (string) $n->fetchColumn(), substr($action, 0, 60), substr($detail, 0, 300), substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45)]);
        $pdo->exec("DELETE FROM admin_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
    } catch (Throwable $e) { /* logging must never break the request */ }
}

function team_log_list(PDO $pdo, int $limit = 200, int $adminId = 0): array {
    team_ensure($pdo);
    $sql = 'SELECT id, admin_id, admin_name, action, detail, ip, created_at FROM admin_log' . ($adminId > 0 ? ' WHERE admin_id = ' . (int) $adminId : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------- forgot the security password
/** Emails a one-time link (valid 30 minutes) to the signed-in admin's own address. '' = sent. */
function team_sec_reset_request(PDO $pdo, int $id): string {
    team_ensure($pdo);
    $a = $pdo->prepare('SELECT name, email FROM admins WHERE id = ?'); $a->execute([$id]);
    $row = $a->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'Admin not found.';
    $recent = $pdo->prepare('SELECT COUNT(*) FROM admin_sec_reset WHERE admin_id = ? AND requested_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)'); $recent->execute([$id]);
    if ((int) $recent->fetchColumn() > 0) return 'A reset email was just sent. Check your inbox, or wait 5 minutes to ask again.';
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('REPLACE INTO admin_sec_reset (admin_id, token_hash, expires_at, requested_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())')->execute([$id, hash('sha256', $token)]);
    $base = defined('SITE_URL') && SITE_URL !== '' ? rtrim(SITE_URL, '/') : ((isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : ''));
    $link = $base . '/admin/security_reset.php?t=' . $token;
    require_once __DIR__ . '/notify.php';
    $html = notify_wrap('Reset your security password', '<p>Someone (hopefully you) asked to reset the <strong>security password</strong> of this admin account.</p><p><a href="' . notify_esc($link) . '" style="background:#1d6b52;color:#fff;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:bold;display:inline-block">Set a new security password</a></p><p style="color:#666;font-size:13px">The link works for 30 minutes and only while you are signed in to the admin. If this was not you, ignore this email: nothing changes.</p>');
    if (!function_exists('send_mail')) require_once __DIR__ . '/mailer.php';
    $ok = false; try { $ok = (bool) send_mail($row['email'], $row['name'], 'Reset your admin security password', $html); } catch (Throwable $e) {}
    if (!$ok) return 'The email could not be sent. Check Email alerts, or ask the server owner to run tools/reset_security.php.';
    team_log($pdo, $id, 'security_reset_requested', '');
    return '';
}

/** Applies the emailed link. Needs the sign-in password as well. '' = done. */
function team_sec_reset_apply(PDO $pdo, int $id, string $token, string $loginPw, string $new): string {
    team_ensure($pdo);
    if (strlen($new) < 8) return 'The security password must be at least 8 characters.';
    $q = $pdo->prepare('SELECT token_hash FROM admin_sec_reset WHERE admin_id = ? AND expires_at > NOW()'); $q->execute([$id]);
    $hash = (string) $q->fetchColumn();
    if ($hash === '' || !hash_equals($hash, hash('sha256', $token))) return 'This reset link is invalid or has expired. Ask for a new one.';
    $s = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?'); $s->execute([$id]);
    $ph = (string) $s->fetchColumn();
    if ($loginPw === '' || !password_verify($loginPw, $ph)) { usleep(700000); return 'Enter your sign-in password to confirm it is you.'; }
    if (password_verify($new, $ph)) return 'The security password must be different from your sign-in password.';
    $pdo->prepare('UPDATE admins SET security_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
    $pdo->prepare('DELETE FROM admin_sec_reset WHERE admin_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM admin_sec_fail WHERE admin_id = ?')->execute([$id]);
    team_log($pdo, $id, 'security_password_reset', 'via email link');
    return '';
}

// ---------------------------------------------------------------- two-step sign-in (a code emailed to the admin)
function team_twofa_on(PDO $pdo, int $id): bool {
    team_ensure($pdo);
    $s = $pdo->prepare('SELECT twofa FROM admins WHERE id = ?'); $s->execute([$id]);
    return (int) $s->fetchColumn() === 1;
}

function team_twofa_set(PDO $pdo, int $id, bool $on, string $security): string {
    $m = team_verify($pdo, $id, $security);
    if ($m !== '') return $m;
    $pdo->prepare('UPDATE admins SET twofa = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    team_log($pdo, $id, $on ? 'twostep_on' : 'twostep_off', '');
    return '';
}

function team_mask_email(string $e): string {
    $p = explode('@', $e, 2);
    return count($p) === 2 ? substr($p[0], 0, 1) . str_repeat('*', max(1, strlen($p[0]) - 1)) . '@' . $p[1] : $e;
}

/** Emails a 6-digit code (valid 10 minutes). Returns '' when sent; $challenge is for the app's second step. */
function team_otp_start(PDO $pdo, int $id, &$challenge = null): string {
    team_ensure($pdo);
    $a = $pdo->prepare('SELECT name, email FROM admins WHERE id = ?'); $a->execute([$id]);
    $row = $a->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'Admin not found.';
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $challenge = bin2hex(random_bytes(16));
    $pdo->prepare('REPLACE INTO admin_otp (admin_id, code_hash, challenge_hash, expires_at, tries) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)')
        ->execute([$id, hash('sha256', $code), hash('sha256', $challenge)]);
    require_once __DIR__ . '/notify.php';
    if (!function_exists('send_mail')) require_once __DIR__ . '/mailer.php';
    $html = notify_wrap('Your sign-in code', '<p>Use this code to finish signing in to the admin:</p><p style="font-size:32px;letter-spacing:8px;font-weight:bold;margin:12px 0">' . $code . '</p><p style="color:#666;font-size:13px">It works for 10 minutes. If you did not just sign in, someone knows your password: change it now.</p>');
    $ok = false; try { $ok = (bool) send_mail($row['email'], $row['name'], 'Your admin sign-in code', $html); } catch (Throwable $e) {}
    if (!$ok) { $pdo->prepare('DELETE FROM admin_otp WHERE admin_id = ?')->execute([$id]); return 'Could not email your sign-in code. Try again, or ask the server owner to switch two-step sign-in off.'; }
    return '';
}

/** '' = code correct (and used up). */
function team_otp_check(PDO $pdo, int $id, string $code): string {
    team_ensure($pdo);
    $q = $pdo->prepare('SELECT code_hash, tries, expires_at > NOW() fresh FROM admin_otp WHERE admin_id = ?'); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int) $r['fresh'] !== 1) return 'That code has expired. Sign in again to get a new one.';
    if ((int) $r['tries'] >= 5) { $pdo->prepare('DELETE FROM admin_otp WHERE admin_id = ?')->execute([$id]); return 'Too many wrong codes. Sign in again to get a new one.'; }
    $pdo->prepare('UPDATE admin_otp SET tries = tries + 1 WHERE admin_id = ?')->execute([$id]);
    if (!hash_equals((string) $r['code_hash'], hash('sha256', preg_replace('/\D/', '', $code)))) { usleep(500000); return 'That code is not right.'; }
    $pdo->prepare('DELETE FROM admin_otp WHERE admin_id = ?')->execute([$id]);
    return '';
}

/** App second step: returns the admin row on success, or an error message string. */
function team_otp_verify_challenge(PDO $pdo, string $challenge, string $code) {
    team_ensure($pdo);
    $q = $pdo->prepare('SELECT admin_id FROM admin_otp WHERE challenge_hash = ?'); $q->execute([hash('sha256', $challenge)]);
    $id = (int) $q->fetchColumn();
    if ($id < 1) return 'That sign-in has expired. Sign in again.';
    $m = team_otp_check($pdo, $id, $code);
    if ($m !== '') return $m;
    $a = $pdo->prepare('SELECT id, name, email FROM admins WHERE id = ?'); $a->execute([$id]);
    return $a->fetch(PDO::FETCH_ASSOC) ?: 'Admin not found.';
}

// ---------------------------------------------------------------- automatic sign-out after inactivity
/** minutes => label. 0 means no inactivity limit (a sign-in still lasts at most 30 days). */
function team_idle_choices(): array {
    return [5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 240 => '4 hours', 1440 => '24 hours', 0 => 'Never (only after 30 days)'];
}

function team_idle_minutes(PDO $pdo): int {
    if (!function_exists('ops_setting')) return 60;
    $v = (int) ops_setting($pdo, 'admin_idle_min', '60');
    return array_key_exists($v, team_idle_choices()) ? $v : 60;
}

/** Master only, protected by the security password. '' = saved, otherwise the message to show. */
function team_idle_set(PDO $pdo, int $masterId, int $minutes, string $security): string {
    if (!team_is_master($pdo, $masterId)) return 'Only the master admin can change this.';
    $m = team_verify($pdo, $masterId, $security);
    if ($m !== '') return $m;
    if (!array_key_exists($minutes, team_idle_choices())) return 'Choose one of the options.';
    ops_setting_set($pdo, 'admin_idle_min', (string) $minutes);
    team_log($pdo, $masterId, 'admin_idle', 'sign out after ' . team_idle_choices()[$minutes]);
    return '';
}

/** Admin app / API sessions: false when the sign-in has been idle too long (the sign-in is then removed). Counts every request as activity. */
function team_token_active(PDO $pdo, string $tokenHash): bool {
    try {
        team_ensure($pdo);
        $q = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, COALESCE(last_used, created_at), NOW()) idle FROM admin_tokens WHERE token_hash = ?');
        $q->execute([$tokenHash]);
        $idle = $q->fetchColumn();
        if ($idle === false) return true;                       // handled by the normal token check
        $min = team_idle_minutes($pdo);
        if ($min > 0 && (int) $idle > $min * 60) {
            $pdo->prepare('DELETE FROM admin_tokens WHERE token_hash = ?')->execute([$tokenHash]);
            return false;
        }
        if ((int) $idle > 30) $pdo->prepare('UPDATE admin_tokens SET last_used = NOW() WHERE token_hash = ?')->execute([$tokenHash]);
    } catch (Throwable $e) {
        return true;                                            // never lock everyone out because of a database hiccup
    }
    return true;
}
