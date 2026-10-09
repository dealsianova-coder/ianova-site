<?php
require_once __DIR__ . '/../config.php';
unset($_SESSION['seller_id'], $_SESSION['seller_name']);
header('Location: login.php');
exit;
