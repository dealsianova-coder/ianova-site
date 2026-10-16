<?php
require 'config.php';
require 'includes/auth_flows.php';
auth_login_page($pdo, 'user');
