<?php
require_once __DIR__ . '/../config.php';
$pdo->exec("CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,email VARCHAR(160) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,last_login DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
if (!empty($_SESSION['admin_id'])) { header('Location: index.php'); exit; }
$admin_count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? ''); $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE email=? LIMIT 1"); $stmt->execute([$email]); $admin=$stmt->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true); $_SESSION['admin_id']=$admin['id']; $_SESSION['admin_name']=$admin['name']; $_SESSION['admin_csrf']=bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE admins SET last_login=NOW() WHERE id=?")->execute([$admin['id']]); header('Location: index.php'); exit;
    } else $error='Invalid administrator email or password.';
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login — IANOVA</title><style>body{font-family:Arial;background:#f3f6f4;margin:0}.login{max-width:430px;margin:10vh auto;background:#fff;padding:30px;border-radius:16px;border:1px solid #ddd}input{width:100%;padding:12px;margin:6px 0 15px;box-sizing:border-box;border:1px solid #ccc;border-radius:8px}button{width:100%;padding:12px;background:#17352c;color:#fff;border:0;border-radius:8px;font-weight:700}.err{background:#fdeaea;color:#8a2424;padding:10px;border-radius:8px;margin-bottom:15px}</style><link rel="stylesheet" href="../assets/css/admin-theme.css?v=1"></head><body><div class="login"><h1>IANOVA Admin</h1><p>Administrator control panel</p><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?><?php if(!$admin_count):?><p><b>No administrator exists yet.</b> Open <code>admin/setup.php</code> once to create the first admin.</p><?php endif;?><form method="post"><label>Email</label><input type="email" name="email" required><label>Password</label><input type="password" name="password" required><button>Sign in</button></form></div></body></html>
