<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth_flows.php';
auth_reset_page($pdo, 'seller');
