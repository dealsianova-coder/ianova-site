<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notify.php';
$e = strtolower(trim((string) ($_GET['e'] ?? '')));
$t = (string) ($_GET['t'] ?? '');
$ok = $e !== '' && hash_equals(hash_hmac('sha256', $e, notify_secret($pdo)), $t);
if ($ok) { try { $pdo->prepare('DELETE FROM newsletter_subscribers WHERE LOWER(email) = ?')->execute([$e]); } catch (Throwable $x) {} }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title></head>
<body style="font-family:Arial,sans-serif;max-width:480px;margin:12vh auto;padding:0 18px;text-align:center;color:#17231E">
<h2><?= $ok ? 'You are unsubscribed' : 'This link is not valid' ?></h2>
<p><?= $ok ? 'You will not get our newsletter any more.' : 'Please use the link from the email, or reply to the email and we will remove you.' ?></p>
<p><a href="index.php">Back to the store</a></p></body></html>
