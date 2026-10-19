<?php
// Run:  php ~/ianova-php/mailtest.php   (checks the mail setup without touching the database)
chdir(__DIR__);
if (is_file('config.local.php')) require 'config.local.php';
foreach (['SMTP_HOST' => 'smtp.gmail.com', 'SMTP_PORT' => 587, 'SMTP_USER' => 'dealsianova@gmail.com', 'SMTP_PASS' => '', 'ADMIN_EMAIL' => 'dealsianova@gmail.com'] as $k => $v) if (!defined($k)) define($k, $v);
echo "PHPMailer installed: ", is_file('vendor/autoload.php') ? "yes" : "NO (run: composer install)", "\n";
echo "SMTP_USER: ", SMTP_USER, "\n";
echo "SMTP_PASS length: ", strlen(SMTP_PASS), " (a Gmail app password has 16 letters)\n";
if (!is_file('vendor/autoload.php') || SMTP_PASS === '') exit(1);
require 'vendor/autoload.php';
$m = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $m->isSMTP(); $m->Host = SMTP_HOST; $m->SMTPAuth = true; $m->Username = SMTP_USER; $m->Password = SMTP_PASS;
    $m->SMTPSecure = 'tls'; $m->Port = SMTP_PORT; $m->SMTPDebug = 2;
    $m->setFrom(SMTP_USER, 'IANOVA'); $m->addAddress(ADMIN_EMAIL); $m->Subject = 'IANOVA mail test'; $m->Body = 'It works.';
    $m->send(); echo "\nSENT to ", ADMIN_EMAIL, "\n";
} catch (Throwable $e) { echo "\nFAILED: ", $e->getMessage(), "\n"; }
