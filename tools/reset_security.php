<?php
// Emergency reset of an admin's SECURITY password (only someone with server access can run this).
// Usage:  sh ~/ianova-php/tools/run.sh reset_security you@email.com
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_team.php';
team_ensure($pdo);
$email = strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '') { fwrite(STDERR, "Usage: run.sh reset_security admin@email.com\n"); exit(2); }
$s = $pdo->prepare('SELECT id, name FROM admins WHERE LOWER(email) = ?'); $s->execute([$email]);
$a = $s->fetch(PDO::FETCH_ASSOC);
if (!$a) { fwrite(STDERR, "No admin with that email.\n"); exit(1); }
$pdo->prepare('UPDATE admins SET security_hash = NULL WHERE id = ?')->execute([$a['id']]);
$pdo->prepare('DELETE FROM admin_sec_fail WHERE admin_id = ?')->execute([$a['id']]);
team_log($pdo, (int) $a['id'], 'security_password_cleared', 'by server owner (command line)');
echo "Security password cleared for {$a['name']}. Sign in to the admin and set a new one in Admin team.\n";
