<?php
// Private settings (DB password, SMTP password) live in config.local.php, which is not committed.
if (is_file(__DIR__ . '/config.local.php')) { require __DIR__ . '/config.local.php'; }

/**
 * IANOVA — configuration & database connection
 * Edit the four constants below to match your MySQL setup.
 */

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'ianova');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

define('SITE_NAME', 'IANOVA');
define('SITE_SLOGAN', 'Shop smart, live better.');
define('CURRENCY', 'KSh ');
define('FREE_DELIVERY_THRESHOLD', 1500);
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_USER')) define('SMTP_USER', 'dealsianova@gmail.com');
if (!defined('SMTP_PASS')) define('SMTP_PASS', '');
// Public address of the site, used for links in emails (e.g. https://shop.example.com). Leave empty on a phone.
if (!defined('SITE_URL')) define('SITE_URL', '');
if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', 'dealsianova@gmail.com');

session_start();

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Check config.php and make sure schema.sql has been imported. (' . htmlspecialchars($e->getMessage()) . ')');
}

require_once __DIR__ . '/includes/display.php';
display_ensure($pdo);

/** Small helper so views never print raw strings without escaping. */
function h($str) {
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

/** Total number of items currently in the session cart. */
function cart_count() {
    if (empty($_SESSION['cart'])) return 0;
    return array_sum(array_column($_SESSION['cart'], 'qty'));
}
