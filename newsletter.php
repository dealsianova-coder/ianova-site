<?php
require 'config.php';

$referrer = $_SERVER['HTTP_REFERER'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO newsletter_subscribers (email) VALUES (?)");
            $stmt->execute([$email]);
            $_SESSION['flash_message'] = "You're subscribed — welcome to IANOVA!";
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Something went wrong. Please try again.';
        }
    } else {
        $_SESSION['flash_message'] = 'Please enter a valid email address.';
    }
}

header('Location: ' . $referrer);
exit;
