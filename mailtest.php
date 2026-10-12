<?php
foreach (['config.local.php','config.php'] as $f) { $p=__DIR__.'/'.$f; if (!is_file($p)) continue;
  preg_match_all("/define\(\s*['\"](SMTP_\w+|SITE_NAME)['\"]\s*,\s*['\"]?([^'\")]*)/", file_get_contents($p), $mm, PREG_SET_ORDER);
  foreach ($mm as $m) if (!defined($m[1])) define($m[1], $m[2]); }
foreach (['SMTP_HOST'=>'smtp.gmail.com','SMTP_PORT'=>587,'SMTP_USER'=>'','SMTP_PASS'=>'','SITE_NAME'=>'IANOVA'] as $k=>$v) if (!defined($k)) define($k,$v);
require __DIR__.'/vendor/autoload.php';
$to = $argv[1] ?? SMTP_USER;
echo "From: ".SMTP_USER."\nTo: $to\nPassword length: ".strlen(SMTP_PASS)." (Gmail app password = 16)\n\n";
$m = new PHPMailer\PHPMailer\PHPMailer(true);
try { $m->isSMTP(); $m->Host=SMTP_HOST; $m->SMTPAuth=true; $m->Username=SMTP_USER; $m->Password=SMTP_PASS;
  $m->SMTPSecure='tls'; $m->Port=SMTP_PORT; $m->Timeout=20; $m->SMTPDebug=2;
  $m->setFrom(SMTP_USER, SITE_NAME); $m->addAddress($to); $m->Subject='IANOVA test'; $m->Body='Email works.';
  $m->send(); echo "\nSENT. Check the inbox and Spam.\n";
} catch (Throwable $e) { echo "\nFAILED: ".($m->ErrorInfo ?: $e->getMessage())."\n"; }
