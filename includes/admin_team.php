<?php
// Admin team: one master admin, optional extra admins who need the master's approval.
// A separate "security password" (different from the sign-in password) protects editing and adding admins.

function team_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS status VARCHAR(12) NOT NULL DEFAULT 'active'");
        $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS is_master TINYINT NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS security_hash VARCHAR(255) NULL");
        $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS added_by INT NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_sec_fail (admin_id INT PRIMARY KEY, fails INT NOT NULL DEFAULT 0, until_at DATETIME NULL) ENGINE=InnoDB");
        if (!(int) $pdo->query('SELECT COUNT(*) FROM admins WHERE is_master = 1')->fetchColumn()) {
            $pdo->exec('UPDATE admins SET is_master = 1 WHERE id = (SELECT m FROM (SELECT MIN(id) m FROM admins) x)');
        }
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
    return '';
}

function team_email_taken(PDO $pdo, string $email, int $exceptAdmin = 0): bool {
    $q = $pdo->prepare('SELECT (SELECT COUNT(*) FROM users WHERE LOWER(email) = ?) + (SELECT COUNT(*) FROM sellers WHERE LOWER(email) = ?) + (SELECT COUNT(*) FROM admins WHERE LOWER(email) = ? AND id <> ?)');
    $q->execute([$email, $email, $email, $exceptAdmin]);
    return (int) $q->fetchColumn() > 0;
}

/** Edit your own admin account. '' = saved. */
function team_edit_self(PDO $pdo, int $id, string $name, string $email, string $newLoginPw, string $security): string {
    $m = team_verify($pdo, $id, $security);
    if ($m !== '') return $m;
    $email = strtolower(trim($email)); $name = trim($name);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a name and a valid email for the admin.';
    if ($newLoginPw !== '' && strlen($newLoginPw) < 8) return 'The new sign-in password must be at least 8 characters.';
    if (team_email_taken($pdo, $email, $id)) return 'That email is already used by another account. The admin email must be unique.';
    $pdo->prepare('UPDATE admins SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]);
    if ($newLoginPw !== '') $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($newLoginPw, PASSWORD_DEFAULT), $id]);
    return '';
}

/** Master adds another admin; it stays pending until approved. '' = created. */
function team_add(PDO $pdo, int $masterId, string $name, string $email, string $loginPw, string $security): string {
    if (!team_is_master($pdo, $masterId)) return 'Only the master admin can add admins.';
    $m = team_verify($pdo, $masterId, $security);
    if ($m !== '') return $m;
    $email = strtolower(trim($email)); $name = trim($name);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a name and a valid email for the new admin.';
    if (strlen($loginPw) < 8) return 'The new admin needs a sign-in password of at least 8 characters.';
    if (team_email_taken($pdo, $email)) return 'That email is already used by another account.';
    $pdo->prepare("INSERT INTO admins (name, email, password_hash, status, is_master, added_by) VALUES (?, ?, ?, 'pending', 0, ?)")
        ->execute([$name, $email, password_hash($loginPw, PASSWORD_DEFAULT), $masterId]);
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
    $kill = fn() => $pdo->prepare('DELETE FROM admin_tokens WHERE admin_id = ?')->execute([$id]);
    if ($do === 'approve' || $do === 'restore') { $pdo->prepare("UPDATE admins SET status = 'active' WHERE id = ?")->execute([$id]); return ''; }
    if ($do === 'suspend') { $pdo->prepare("UPDATE admins SET status = 'suspended' WHERE id = ?")->execute([$id]); try { $kill(); } catch (Throwable $e) {} return ''; }
    if ($do === 'reject' || $do === 'remove') { $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]); try { $kill(); } catch (Throwable $e) {} return ''; }
    return 'Unknown action.';
}

function team_list(PDO $pdo): array {
    team_ensure($pdo);
    return $pdo->query("SELECT id, name, email, last_login, created_at, status, is_master, (security_hash IS NOT NULL AND security_hash <> '') has_security FROM admins ORDER BY is_master DESC, id")->fetchAll(PDO::FETCH_ASSOC);
}
