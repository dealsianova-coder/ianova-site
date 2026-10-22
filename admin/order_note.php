<?php
require_once 'includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($id > 0 && $note !== '') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_notes (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, admin_id INT NOT NULL, admin_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id)) ENGINE=InnoDB");
        $pdo->prepare('INSERT INTO order_notes (order_id, admin_id, admin_name, note) SELECT ?, id, name, ? FROM admins WHERE id = ?')->execute([$id, mb_substr($note, 0, 1000), (int) $_SESSION['admin_id']]);
    }
    header('Location: order_view.php?id=' . $id); exit;
}
header('Location: orders.php');
