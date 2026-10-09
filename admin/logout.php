<?php require_once __DIR__ . '/../config.php'; unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['admin_csrf']); session_regenerate_id(true); header('Location: login.php'); exit;
