<?php
/**
 * Background worker for email push: finds new events and sends queued emails.
 * Started automatically by the site. You can also run it yourself in Termux:
 *   while true; do php ~/ianova-php/notify_cron.php; sleep 30; done
 */
chdir(__DIR__);
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notify.php';
try { notify_run($pdo); } catch (Throwable $e) { error_log('notify_cron: ' . $e->getMessage()); }
