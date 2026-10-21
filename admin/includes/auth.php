<?php
require_once __DIR__ . '/../../config.php';
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../../includes/admin_team.php';
if (isset($pdo) && team_login_block($pdo, ['id' => (int) $_SESSION['admin_id']]) !== '') { unset($_SESSION['admin_id']); header('Location: login.php'); exit; }
function admin_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function admin_csrf() {
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['admin_csrf'];
}
function admin_check_csrf() {
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid security token. Please go back and try again.');
    }
}
function admin_redirect($url) { header('Location: ' . $url); exit; }
