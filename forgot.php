<?php
require 'config.php';
require 'includes/auth_flows.php';
auth_forgot_page($pdo, 'user');
