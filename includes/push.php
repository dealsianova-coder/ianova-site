<?php
/**
 * Phone push notifications through Firebase Cloud Messaging (HTTP v1).
 * Switched OFF until firebase-service-account.json exists in the site folder (or FCM_SERVICE_ACCOUNT points to it).
 * Every email the store queues (orders, status changes, tracking, refunds, payouts, alerts...) also becomes a push
 * for the same person when they have the app installed and signed in.
 */
function push_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_tokens (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(10) NOT NULL, owner_id INT NOT NULL, token VARCHAR(255) NOT NULL, platform VARCHAR(12) NOT NULL DEFAULT 'android', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY (token), KEY (kind, owner_id)) ENGINE=InnoDB");
    } catch (Throwable $e) {
    }
}

function push_account_path(): string {
    return defined('FCM_SERVICE_ACCOUNT') ? (string) FCM_SERVICE_ACCOUNT : __DIR__ . '/../firebase-service-account.json';
}

function push_account(): ?array {
    static $acct = false;
    if ($acct !== false) return $acct;
    $acct = null;
    $p = push_account_path();
    if (is_file($p)) {
        $j = json_decode((string) @file_get_contents($p), true);
        if (is_array($j) && !empty($j['private_key']) && !empty($j['client_email']) && !empty($j['project_id'])) $acct = $j;
    }
    return $acct;
}

function push_enabled(): bool { return push_account() !== null && function_exists('openssl_sign'); }

function push_http(string $url, array $headers, string $body): array {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
        $r = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return [$code, (string) $r];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 6, 'ignore_errors' => true]]);
    $r = @file_get_contents($url, false, $ctx);
    $code = 0; foreach (($http_response_header ?? []) as $h) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $code = (int) $m[1];
    return [$code, (string) $r];
}

function push_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** OAuth access token for FCM (cached for ~50 minutes in the system temp folder). */
function push_access_token(array $acct): ?string {
    $cache = sys_get_temp_dir() . '/ianova_fcm_' . md5($acct['client_email']) . '.json';
    $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (is_array($c) && ($c['exp'] ?? 0) > time() + 60 && !empty($c['token'])) return $c['token'];
    $now = time();
    $uri = $acct['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $in = push_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . push_b64(json_encode(['iss' => $acct['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($in, $sig, $acct['private_key'], OPENSSL_ALGO_SHA256)) return null;
    [$code, $res] = push_http($uri, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $in . '.' . push_b64($sig)]));
    $j = json_decode($res, true);
    if ($code !== 200 || empty($j['access_token'])) return null;
    @file_put_contents($cache, json_encode(['token' => $j['access_token'], 'exp' => $now + 3000]), LOCK_EX);
    return $j['access_token'];
}

/** Push to every phone one person is signed in on. kind: app (customer) | seller | admin. owner 0 = every admin. Returns how many were sent. */
function push_to_owner(PDO $pdo, string $kind, int $ownerId, string $title, string $body, array $data = []): int {
    if (!push_enabled()) return 0;
    push_ensure($pdo);
    $acct = push_account();
    $tok = push_access_token($acct);
    if (!$tok) return 0;
    $q = $pdo->prepare('SELECT id, token FROM device_tokens WHERE kind = ?' . ($ownerId > 0 ? ' AND owner_id = ?' : '') . ' LIMIT 40');
    $q->execute($ownerId > 0 ? [$kind, $ownerId] : [$kind]);
    $sent = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $msg = ['message' => ['token' => $d['token'], 'notification' => ['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 180)],
            'data' => array_map('strval', $data + ['kind' => $kind]), 'android' => ['priority' => 'HIGH']]];
        [$code, $res] = push_http('https://fcm.googleapis.com/v1/projects/' . rawurlencode($acct['project_id']) . '/messages:send', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], json_encode($msg));
        if ($code === 200) $sent++;
        elseif ($code === 404 || ($code === 400 && strpos($res, 'INVALID_ARGUMENT') !== false) || strpos($res, 'UNREGISTERED') !== false) $pdo->prepare('DELETE FROM device_tokens WHERE id = ?')->execute([$d['id']]);
    }
    return $sent;
}

/** Called for every queued email: the same news goes to that person's phone. */
function push_notify_email(PDO $pdo, string $audience, string $email, string $subject): void {
    if (!push_enabled()) return;
    if ($audience === 'admin') { push_to_owner($pdo, 'admin', 0, 'IANOVA Admin', $subject); return; }
    $map = ['customer' => ['users', 'app'], 'seller' => ['sellers', 'seller']];
    if (!isset($map[$audience])) return;
    $q = $pdo->prepare('SELECT id FROM ' . $map[$audience][0] . ' WHERE email = ? LIMIT 1'); $q->execute([$email]);
    $id = (int) $q->fetchColumn();
    if ($id > 0) push_to_owner($pdo, $map[$audience][1], $id, 'IANOVA', $subject);
}
