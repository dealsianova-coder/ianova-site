<?php
/**
 * Admin -> sellers broadcast. Each seller gets their own message in their inbox,
 * greeted by their own business name. Tables are created on first use.
 */
require_once __DIR__ . '/seller_messaging.php';

function bc_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    ensure_seller_messaging($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS seller_broadcasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message TEXT NOT NULL,
        status_change VARCHAR(20) NOT NULL DEFAULT '',
        mention TINYINT(1) NOT NULL DEFAULT 1,
        recipient_count INT NOT NULL DEFAULT 0,
        recipients MEDIUMTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function bc_sellers(PDO $pdo): array {
    return $pdo->query("SELECT id, business_name, email, status FROM sellers
        ORDER BY FIELD(status, 'approved', 'pending', 'suspended', 'rejected'), business_name")->fetchAll();
}

function bc_history(PDO $pdo, int $limit = 15): array {
    $limit = max(1, min(50, $limit));
    return $pdo->query("SELECT id, message, status_change, recipient_count, recipients, created_at
        FROM seller_broadcasts ORDER BY id DESC LIMIT $limit")->fetchAll();
}

/** Builds the text one seller receives. {name} in the message becomes their business name. */
function bc_personalize(string $message, string $name, bool $mention, string $statusLine): string {
    $hasTag = stripos($message, '{name}') !== false;
    $text = $hasTag ? str_ireplace('{name}', $name, $message) : $message;
    if (!$hasTag && $mention) {
        $text = 'Hello ' . $name . ($text !== '' ? ",\n\n" . $text : ',');
    }
    if ($statusLine !== '') {
        $text = rtrim($text) . ($text !== '' ? "\n\n" : '') . $statusLine;
    }
    return trim($text);
}

/**
 * Sends the message to the chosen sellers, optionally changing their status first.
 * With $email, each seller is also emailed their own copy after the messages are saved.
 * Returns ['count', 'names', 'changed', 'emailed', 'email_failed'] or ['error' => '...'].
 */
function bc_send(PDO $pdo, string $message, array $ids, string $statusChange, bool $mention, bool $email = false): array {
    bc_ensure($pdo);
    $message = trim($message);
    if (mb_strlen($message) > 2000) return ['error' => 'The message is too long (2000 characters at most).'];
    if (!in_array($statusChange, ['', 'approved', 'suspended', 'rejected'], true)) return ['error' => 'Invalid status.'];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return ['error' => 'Select at least one seller.'];
    if (count($ids) > 500) return ['error' => 'Too many sellers selected (500 at most).'];
    if ($message === '' && $statusChange === '') return ['error' => 'Write a message first.'];

    $hook = false;
    if ($statusChange !== '' && is_file(__DIR__ . '/seller_ops.php')) {
        require_once __DIR__ . '/seller_ops.php';
        if (function_exists('ops_ensure')) ops_ensure($pdo);
        $hook = function_exists('ops_seller_status_changed');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT id, business_name, email, status FROM sellers WHERE id IN ($in) ORDER BY business_name");
    $q->execute($ids);
    $sellers = $q->fetchAll();
    if (!$sellers) return ['error' => 'No matching sellers found.'];

    $names = [];
    $copies = [];
    $changed = 0;
    try {
        $pdo->beginTransaction();
        foreach ($sellers as $s) {
            $statusLine = '';
            if ($statusChange !== '' && $s['status'] !== $statusChange) {
                $pdo->prepare('UPDATE sellers SET status = ? WHERE id = ?')->execute([$statusChange, (int) $s['id']]);
                $changed++;
                $statusLine = 'Your account status is now: ' . ucfirst($statusChange) . '.';
                if ($hook) {
                    try { ops_seller_status_changed($pdo, (int) $s['id'], (string) $s['status'], $statusChange); } catch (Throwable $e) { /* keep going */ }
                }
            }
            $body = bc_personalize($message, (string) $s['business_name'], $mention, $statusLine);
            seller_message_add($pdo, (int) $s['id'], 'admin', $body);
            $names[] = (string) $s['business_name'];
            $copies[] = [(string) $s['email'], (string) $s['business_name'], $body];
        }
        $pdo->prepare('INSERT INTO seller_broadcasts (message, status_change, mention, recipient_count, recipients) VALUES (?, ?, ?, ?, ?)')
            ->execute([$message, $statusChange, $mention ? 1 : 0, count($names), implode("\n", $names)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Could not send. Nothing was changed.'];
    }
    $emailed = 0;
    $failed = 0;
    if ($email) {
        @set_time_limit(300);
        foreach ($copies as [$to, $business, $body]) {
            if (bc_email($to, $business, $body)) $emailed++; else $failed++;
        }
    }
    return ['count' => count($names), 'names' => $names, 'changed' => $changed, 'emailed' => $emailed, 'email_failed' => $failed];
}

/** Emails one seller their copy. Never throws; returns false when it could not be sent. */
function bc_email(string $to, string $business, string $body): bool {
    if ($to === '') return false;
    try {
        require_once __DIR__ . '/mailer.php';
        if (!function_exists('send_mail') || !defined('SMTP_PASS')) return false;
        $site = defined('SITE_NAME') ? (string) SITE_NAME : 'IANOVA';
        $html = '<div style="font-family:Arial,sans-serif;color:#0F0F12;max-width:520px;line-height:1.5;">'
            . '<h2>New message from ' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<div style="background:#F3F4F7;border-radius:16px;padding:14px 16px;white-space:pre-wrap;">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<p style="margin-top:16px;">You can reply in the ' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . ' app or seller area.</p>'
            . '<p style="margin-top:24px;color:#5F6575;font-size:13px;">— ' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . ' team</p></div>';
        return (bool) send_mail($to, $business, 'New message from ' . $site, $html);
    } catch (Throwable $e) {
        return false;
    }
}
