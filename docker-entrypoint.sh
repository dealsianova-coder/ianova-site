#!/bin/sh
set -eu

cat > /var/www/html/config.local.php <<'PHP'
<?php

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'ianova');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');

define('SITE_URL', getenv('SITE_URL') ?: '');
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: '');

PHP

exec "$@"

