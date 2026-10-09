<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
$sql = 'SELECT id, guest_name, guest_email, guest_phone, address, total, status, created_at FROM orders WHERE user_id = ?';
$args = [(int) $user['id']];
$status = trim((string) ($_GET['status'] ?? ''));
if ($status !== '') { $sql .= ' AND status = ?'; $args[] = $status; }
$sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit);
$s = $pdo->prepare($sql);
$s->execute($args);
out(['success' => true, 'orders' => $s->fetchAll(), 'page' => $page, 'limit' => $limit]);
