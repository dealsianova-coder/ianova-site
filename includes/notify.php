<?php
/**
 * IANOVA email push. One queue (notify_outbox) feeds every email the site sends on its own:
 * orders, order status changes, new sellers, products waiting for review, product decisions,
 * new subscribers and newsletters. Events are found by comparing the database with what was
 * already announced, so it works for the website, the app, the seller site and the admin alike.
 * Detection and sending run in a background process (notify_cron.php), never inside a page load.
 */
require_once __DIR__ . '/seller_ops.php';

const NOTIFY_THROTTLE_SECONDS = 15;

function notify_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_outbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dedupe VARCHAR(100) NOT NULL,
        audience VARCHAR(12) NOT NULL,
        to_email VARCHAR(190) NOT NULL,
        to_name VARCHAR(120) NOT NULL DEFAULT '',
        subject VARCHAR(190) NOT NULL,
        html MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        tries TINYINT NOT NULL DEFAULT 0,
        last_error VARCHAR(190) NOT NULL DEFAULT '',
        UNIQUE KEY uq_dedupe (dedupe)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_seen (
        kind CHAR(1) NOT NULL, ref INT NOT NULL, val VARCHAR(30) NOT NULL DEFAULT '',
        PRIMARY KEY (kind, ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_newsletters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(190) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        recipients INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function notify_on(PDO $pdo, string $audience): bool {
    if (ops_setting($pdo, 'mail_on', '1') !== '1') return false;
    return ops_setting($pdo, 'mail_' . $audience, '1') === '1';
}

function notify_admin_address(PDO $pdo): string {
    $v = trim(ops_setting($pdo, 'mail_admin_to', ''));
    if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) return $v;
    return defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : '';
}

function notify_esc($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function notify_money($v): string { return (defined('CURRENCY') ? CURRENCY : 'KSh ') . number_format((float) $v, 2); }

function notify_wrap(string $heading, string $inner): string {
    $site = defined('SITE_NAME') ? SITE_NAME : 'IANOVA';
    $head = is_file(__DIR__ . '/../assets/img/ianova-wordmark.png')
        ? '<img src="cid:ianova-logo" alt="' . notify_esc($site) . '" width="540" style="width:100%;max-width:540px;height:auto;display:block;border-radius:14px 14px 0 0;">'
        : '<div style="padding:16px 18px;font-weight:800;letter-spacing:2px;">' . notify_esc($site) . '</div>';
    return '<div style="font-family:Arial,sans-serif;color:#17231E;max-width:540px;line-height:1.55;">'
        . $head . '<div style="height:3px;background:#E9B949;"></div>'
        . '<div style="border:1px solid #DCE4DF;border-top:0;border-radius:0 0 14px 14px;padding:20px 18px;">'
        . '<h2 style="margin:0 0 10px;">' . notify_esc($heading) . '</h2>' . $inner
        . '<p style="margin-top:22px;color:#6B7973;font-size:13px;">— ' . notify_esc($site) . ' team</p></div></div>';
}

/** Add one email to the queue. The dedupe key guarantees the same event is never emailed twice. */
function notify_queue(PDO $pdo, string $dedupe, string $audience, string $to, string $name, string $subject, string $html, bool $force = false): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (!$force && !notify_on($pdo, $audience)) return false;
    notify_ensure($pdo);
    $q = $pdo->prepare('INSERT IGNORE INTO notify_outbox (dedupe, audience, to_email, to_name, subject, html) VALUES (?,?,?,?,?,?)');
    $q->execute([substr($dedupe, 0, 100), $audience, $to, mb_substr($name, 0, 120), mb_substr($subject, 0, 190), $html]);
    return $q->rowCount() > 0;
}

/** Send queued emails (a few at a time). Returns how many went out. */
function notify_deliver(PDO $pdo, int $limit = 6): int {
    notify_ensure($pdo);
    if (!defined('SMTP_PASS') || SMTP_PASS === '') return 0; // not set up yet: keep everything queued
    if (!function_exists('send_mail')) require_once __DIR__ . '/mailer.php';
    $pdo->exec("DELETE FROM notify_outbox WHERE sent_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $lock = (int) $pdo->query("SELECT GET_LOCK('ianova_notify_send', 0)")->fetchColumn();
    if ($lock !== 1) return 0;
    $sent = 0;
    try {
        // Gmail blocks accounts that send too much: stay under a daily cap (MAIL_DAILY_CAP in config.local.php, default 450). The rest waits for tomorrow.
        $cap = defined('MAIL_DAILY_CAP') ? max(1, (int) MAIL_DAILY_CAP) : 450;
        $today = (int) $pdo->query('SELECT COUNT(*) FROM notify_outbox WHERE sent_at >= CURDATE()')->fetchColumn();
        if ($today >= $cap) return 0;
        $limit = min($limit, $cap - $today);
        $rows = $pdo->query('SELECT id, to_email, to_name, subject, html FROM notify_outbox WHERE sent_at IS NULL AND tries < 3 ORDER BY id LIMIT ' . max(1, $limit))->fetchAll();
        foreach ($rows as $r) {
            $ok = false;
            $err = '';
            $errFile = sys_get_temp_dir() . '/ianova_mail_err.log';
            @unlink($errFile);
            $oldLog = ini_set('error_log', $errFile);
            try { $ok = (bool) send_mail($r['to_email'], $r['to_name'], $r['subject'], $r['html']); }
            catch (Throwable $e) { $err = substr($e->getMessage(), 0, 180); }
            if ($oldLog !== false) ini_set('error_log', $oldLog);
            if (!$ok && $err === '') {
                $last = is_file($errFile) ? trim((string) array_slice(preg_split('/\R/', trim((string) file_get_contents($errFile))), -1)[0]) : '';
                $err = $last !== '' ? substr(preg_replace('/^\[[^\]]*\]\s*/', '', $last), 0, 180) : 'Mail server refused it';
            }
            if ($ok) { $pdo->prepare('UPDATE notify_outbox SET sent_at = NOW(), tries = tries + 1, last_error = \'\' WHERE id = ?')->execute([$r['id']]); $sent++; }
            else { $pdo->prepare('UPDATE notify_outbox SET tries = tries + 1, last_error = ? WHERE id = ?')->execute([$err, $r['id']]); }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ianova_notify_send')");
    }
    return $sent;
}

// ------------------------------------------------------------------ events

function notify_order_data(PDO $pdo, int $id): ?array {
    $q = $pdo->prepare("SELECT o.id, o.total, o.status, o.address, o.guest_phone, COALESCE(o.guest_name, u.name, 'Customer') AS cname, COALESCE(o.guest_email, u.email, '') AS cemail
        FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
    $q->execute([$id]);
    $o = $q->fetch();
    if (!$o) return null;
    $l = $pdo->prepare("SELECT oi.quantity, oi.unit_price, COALESCE(p.name, 'Item') AS name, p.seller_id, COALESCE(s.business_name, '') AS seller_name, COALESCE(s.email, '') AS seller_email, COALESCE(s.status, '') AS seller_status
        FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id LEFT JOIN sellers s ON s.id = p.seller_id WHERE oi.order_id = ? ORDER BY oi.id");
    $l->execute([$id]);
    $o['lines'] = $l->fetchAll();
    return $o;
}

function notify_lines_html(array $lines): string {
    $rows = '';
    foreach ($lines as $it) {
        $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;">' . notify_esc($it['name']) . ' × ' . (int) $it['quantity']
            . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right;">' . notify_money((float) $it['unit_price'] * (int) $it['quantity']) . '</td></tr>';
    }
    return '<table style="width:100%;border-collapse:collapse;margin:10px 0;">' . $rows . '</table>';
}

/** A new order: customer receipt, admin alert, and each seller gets their part. */
function notify_order_placed(PDO $pdo, int $orderId): void {
    notify_ensure($pdo);
    $o = notify_order_data($pdo, $orderId);
    if (!$o || !$o['lines']) return;
    $pdo->prepare("INSERT IGNORE INTO notify_seen (kind, ref, val) VALUES ('o', ?, ?)")->execute([$orderId, (string) $o['status']]);
    $id = (int) $o['id'];
    $table = notify_lines_html($o['lines']);
    $total = '<p style="font-size:16px;"><strong>Total: ' . notify_money($o['total']) . '</strong></p>';

    notify_queue($pdo, 'order_new_customer:' . $id, 'customer', (string) $o['cemail'], (string) $o['cname'],
        'Your ' . (defined('SITE_NAME') ? SITE_NAME : 'IANOVA') . ' order #' . $id . ' is confirmed',
        notify_wrap('Thanks for your order, ' . $o['cname'] . '!', '<p>Your order <strong>#' . $id . '</strong> has been received and is being prepared.</p>' . $table . $total
            . ($o['address'] !== null && $o['address'] !== '' ? '<p style="color:#6B7973;font-size:13px;">Delivering to: ' . notify_esc($o['address']) . '</p>' : '')));

    notify_queue($pdo, 'order_new_admin:' . $id, 'admin', notify_admin_address($pdo), 'Admin',
        'New order #' . $id . ' — ' . notify_money($o['total']),
        notify_wrap('New order #' . $id, '<p><strong>Customer:</strong> ' . notify_esc($o['cname']) . ' (' . notify_esc($o['cemail']) . ')'
            . ($o['guest_phone'] ? '<br><strong>Phone:</strong> ' . notify_esc($o['guest_phone']) : '') . '</p>' . $table . $total));

    $by = [];
    foreach ($o['lines'] as $l) if ((int) $l['seller_id'] > 0 && $l['seller_email'] !== '') $by[(int) $l['seller_id']][] = $l;
    foreach ($by as $sid => $lines) {
        $sum = 0.0;
        foreach ($lines as $l) $sum += (float) $l['unit_price'] * (int) $l['quantity'];
        notify_queue($pdo, 'order_new_seller:' . $id . ':' . $sid, 'seller', $lines[0]['seller_email'], $lines[0]['seller_name'],
            'New order #' . $id . ' for your products',
            notify_wrap('You have a new order', '<p>Hello ' . notify_esc($lines[0]['seller_name']) . ', order <strong>#' . $id . '</strong> includes your products.</p>'
                . notify_lines_html($lines) . '<p><strong>Your part: ' . notify_money($sum) . '</strong></p>'
                . '<p>Open the seller area to approve it (if it was not approved automatically) and prepare it for delivery.</p>'));
    }
}

function notify_order_status(PDO $pdo, int $orderId, string $status): void {
    $o = notify_order_data($pdo, $orderId);
    if (!$o) return;
    $id = (int) $o['id'];
    $say = [
        'approved' => ['Your order was approved', 'Your order <strong>#' . $id . '</strong> has been approved and is being prepared.'],
        'paid' => ['Payment received', 'We received the payment for order <strong>#' . $id . '</strong>. Thank you!'],
        'shipped' => ['Your order is on the way', 'Good news: order <strong>#' . $id . '</strong> has been shipped and is on its way to you.'],
        'delivered' => ['Your order was delivered', 'Order <strong>#' . $id . '</strong> was delivered. We hope you enjoy it!'],
        'cancelled' => ['Your order was cancelled', 'Order <strong>#' . $id . '</strong> was cancelled. If you did not expect this, reply to this email and we will help.'],
    ];
    if (isset($say[$status])) {
        notify_queue($pdo, 'order_status_customer:' . $id . ':' . $status, 'customer', (string) $o['cemail'], (string) $o['cname'],
            $say[$status][0] . ' — order #' . $id,
            notify_wrap($say[$status][0], '<p>Hello ' . notify_esc($o['cname']) . ',</p><p>' . $say[$status][1] . '</p>' . notify_lines_html($o['lines'])
                . '<p><strong>Total: ' . notify_money($o['total']) . '</strong></p>'));
    }
    if ($status === 'cancelled') {
        $by = [];
        foreach ($o['lines'] as $l) if ((int) $l['seller_id'] > 0 && $l['seller_email'] !== '') $by[(int) $l['seller_id']][] = $l;
        foreach ($by as $sid => $lines) {
            notify_queue($pdo, 'order_status_seller:' . $id . ':' . $sid . ':cancelled', 'seller', $lines[0]['seller_email'], $lines[0]['seller_name'],
                'Order #' . $id . ' was cancelled',
                notify_wrap('An order was cancelled', '<p>Order <strong>#' . $id . '</strong> was cancelled. You do not need to prepare these items. The stock was returned.</p>' . notify_lines_html($lines)));
        }
    }
}

function notify_baseline(PDO $pdo): void {
    $max = fn(string $t) => (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM $t")->fetchColumn();
    ops_setting_set($pdo, 'mail_c_order', (string) $max('orders'));
    ops_setting_set($pdo, 'mail_c_seller', (string) $max('sellers'));
    try { ops_setting_set($pdo, 'mail_c_sub', (string) $max('newsletter_subscribers')); } catch (Throwable $e) { ops_setting_set($pdo, 'mail_c_sub', '0'); }
    $pdo->exec("INSERT IGNORE INTO notify_seen (kind, ref, val) SELECT 'o', id, status FROM orders");
    $pdo->exec("INSERT IGNORE INTO notify_seen (kind, ref, val) SELECT 'p', id, status FROM products");
    ops_setting_set($pdo, 'mail_baseline', date('Y-m-d H:i:s'));
}

/** Compare the database with what was already announced and queue the emails for anything new. */
function notify_scan(PDO $pdo): void {
    notify_ensure($pdo);
    if (ops_setting($pdo, 'mail_baseline', '') === '') { notify_baseline($pdo); return; }

    // 1. new orders
    $cur = (int) ops_setting($pdo, 'mail_c_order', '0');
    $q = $pdo->prepare('SELECT o.id FROM orders o WHERE o.id > ? AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id) ORDER BY o.id LIMIT 40');
    $q->execute([$cur]);
    $top = $cur;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $oid) { notify_order_placed($pdo, (int) $oid); $top = max($top, (int) $oid); }
    if ($top > $cur) ops_setting_set($pdo, 'mail_c_order', (string) $top);

    // 2. order status changes
    $rows = $pdo->query("SELECT o.id, o.status FROM orders o JOIN notify_seen s ON s.kind = 'o' AND s.ref = o.id WHERE s.val COLLATE utf8mb4_unicode_ci <> o.status COLLATE utf8mb4_unicode_ci ORDER BY o.id LIMIT 60")->fetchAll();
    foreach ($rows as $r) {
        $pdo->prepare("UPDATE notify_seen SET val = ? WHERE kind = 'o' AND ref = ?")->execute([$r['status'], $r['id']]);
        notify_order_status($pdo, (int) $r['id'], (string) $r['status']);
    }

    // 3. new seller applications (the admin is told once per email address)
    $cur = (int) ops_setting($pdo, 'mail_c_seller', '0');
    $q = $pdo->prepare('SELECT id, business_name, email FROM sellers WHERE id > ? ORDER BY id LIMIT 30');
    $q->execute([$cur]);
    $top = $cur;
    foreach ($q->fetchAll() as $s) {
        $top = max($top, (int) $s['id']);
        notify_queue($pdo, 'seller_new:' . md5(strtolower($s['email'])), 'admin', notify_admin_address($pdo), 'Admin',
            'New seller application: ' . $s['business_name'],
            notify_wrap('New seller application', '<p><strong>' . notify_esc($s['business_name']) . '</strong> (' . notify_esc($s['email']) . ') wants to sell on ' . notify_esc(defined('SITE_NAME') ? SITE_NAME : 'IANOVA') . '.</p><p>Open Admin, then Sellers, to approve or reject.</p>'));
    }
    if ($top > $cur) ops_setting_set($pdo, 'mail_c_seller', (string) $top);

    // 4. seller products: waiting for review (admin) and approved / rejected (seller)
    $rows = $pdo->query("SELECT p.id, p.name, p.status, p.seller_id, s.val AS seen, COALESCE(sl.business_name,'') AS sname, COALESCE(sl.email,'') AS semail
        FROM products p LEFT JOIN notify_seen s ON s.kind = 'p' AND s.ref = p.id LEFT JOIN sellers sl ON sl.id = p.seller_id
        WHERE s.val IS NULL OR s.val COLLATE utf8mb4_unicode_ci <> p.status COLLATE utf8mb4_unicode_ci ORDER BY p.id LIMIT 200")->fetchAll();
    $review = []; $decided = []; $maxId = 0;
    foreach ($rows as $r) {
        $maxId = max($maxId, (int) $r['id']);
        $pdo->prepare("INSERT INTO notify_seen (kind, ref, val) VALUES ('p', ?, ?) ON DUPLICATE KEY UPDATE val = VALUES(val)")->execute([$r['id'], $r['status']]);
        if ((int) $r['seller_id'] < 1) continue;
        if ($r['status'] === 'pending') $review[strtolower(trim($r['sname'] . '|' . $r['name']))] = $r;
        elseif (in_array($r['status'], ['approved', 'rejected'], true) && $r['seen'] === 'pending' && $r['semail'] !== '') $decided[$r['seller_id'] . '|' . $r['status']][strtolower(trim($r['name']))] = $r;
    }
    if ($review) {
        $li = '';
        foreach ($review as $r) $li .= '<li>' . notify_esc($r['name']) . ' <span style="color:#6B7973;">(' . notify_esc($r['sname']) . ')</span></li>';
        notify_queue($pdo, 'prod_review:' . $maxId . ':' . count($review), 'admin', notify_admin_address($pdo), 'Admin',
            count($review) . ' product(s) waiting for review',
            notify_wrap('Products waiting for review', '<ul>' . $li . '</ul><p>Open Admin, then Products, to approve or reject them.</p>'));
    }
    foreach ($decided as $key => $items) {
        [$sid, $st] = explode('|', $key);
        $first = reset($items);
        $li = '';
        foreach ($items as $r) $li .= '<li>' . notify_esc($r['name']) . '</li>';
        notify_queue($pdo, 'prod_decision:' . $sid . ':' . $st . ':' . $maxId, 'seller', $first['semail'], $first['sname'],
            $st === 'approved' ? 'Your product is approved' : 'Your product was not approved',
            notify_wrap($st === 'approved' ? 'Your product is live' : 'Your product needs changes',
                '<p>Hello ' . notify_esc($first['sname']) . ',</p><p>' . ($st === 'approved' ? 'These products were approved and can now be bought:' : 'We could not approve these products yet. Please check them and send them again, or reply to this email:') . '</p><ul>' . $li . '</ul>'));
    }

    // 5. new newsletter subscribers: welcome email
    if (notify_on($pdo, 'subs')) {
        try {
            $cur = (int) ops_setting($pdo, 'mail_c_sub', '0');
            $q = $pdo->prepare('SELECT id, email FROM newsletter_subscribers WHERE id > ? ORDER BY id LIMIT 40');
            $q->execute([$cur]);
            $top = $cur;
            foreach ($q->fetchAll() as $s) {
                $top = max($top, (int) $s['id']);
                notify_queue($pdo, 'sub_welcome:' . $s['id'], 'subs', $s['email'], '',
                    'Welcome to ' . (defined('SITE_NAME') ? SITE_NAME : 'IANOVA'),
                    notify_wrap('You are subscribed', '<p>Thanks for subscribing. We will email you about new deals and arrivals.</p>' . notify_unsub_html($pdo, $s['email'])));
            }
            if ($top > $cur) ops_setting_set($pdo, 'mail_c_sub', (string) $top);
        } catch (Throwable $e) {
        }
    }

    // 6. low stock: one email to the admin when products run low or out (once per product until restocked)
    try {
        $thr = 3;
        $rows = $pdo->query("SELECT p.id, p.name, p.stock, s.val AS seen FROM products p LEFT JOIN notify_seen s ON s.kind = 's' AND s.ref = p.id WHERE p.status = 'approved' AND p.stock <= $thr ORDER BY p.id LIMIT 100")->fetchAll();
        $fresh = []; $keys = [];
        foreach ($rows as $r) {
            $bucket = (int) $r['stock'] <= 0 ? 'out' : 'low';
            if ($r['seen'] === $bucket) continue;
            $pdo->prepare("INSERT INTO notify_seen (kind, ref, val) VALUES ('s', ?, ?) ON DUPLICATE KEY UPDATE val = VALUES(val)")->execute([$r['id'], $bucket]);
            $fresh[] = $r; $keys[] = $r['id'] . ':' . $bucket;
        }
        $pdo->exec("DELETE s FROM notify_seen s JOIN products p ON p.id = s.ref WHERE s.kind = 's' AND p.stock > $thr");
        if ($fresh) {
            $li = '';
            foreach ($fresh as $r) $li .= '<li>' . notify_esc($r['name']) . ' — <strong>' . ((int) $r['stock'] <= 0 ? 'out of stock' : (int) $r['stock'] . ' left') . '</strong></li>';
            notify_queue($pdo, 'stock_low:' . md5(implode(',', $keys)), 'admin', notify_admin_address($pdo), 'Admin',
                count($fresh) . ' product(s) running low on stock',
                notify_wrap('Low stock', '<ul>' . $li . '</ul><p>Open Admin, then Products, to restock them.</p>'));
        }
    } catch (Throwable $e) {
    }
}

// ------------------------------------------------------------------ newsletter

function notify_secret(PDO $pdo): string {
    $s = ops_setting($pdo, 'mail_secret', '');
    if ($s === '') { $s = bin2hex(random_bytes(16)); ops_setting_set($pdo, 'mail_secret', $s); }
    return $s;
}

function notify_unsub_url(PDO $pdo, string $email): string {
    if (!defined('SITE_URL') || SITE_URL === '') return '';
    return rtrim(SITE_URL, '/') . '/unsubscribe.php?e=' . rawurlencode($email) . '&t=' . hash_hmac('sha256', strtolower($email), notify_secret($pdo));
}

function notify_unsub_html(PDO $pdo, string $email): string {
    $u = notify_unsub_url($pdo, $email);
    return '<p style="color:#6B7973;font-size:12px;">' . ($u !== '' ? '<a href="' . notify_esc($u) . '">Unsubscribe</a>' : 'To stop these emails, reply with the word UNSUBSCRIBE.') . '</p>';
}

/** Queue one newsletter for every subscriber. Returns how many emails were queued. */
function notify_newsletter(PDO $pdo, string $subject, string $text, bool $testOnly): int {
    notify_ensure($pdo);
    $paras = '';
    foreach (preg_split('/\R{2,}/', trim($text)) as $p) $paras .= '<p>' . nl2br(notify_esc($p)) . '</p>';
    if ($testOnly) {
        $to = notify_admin_address($pdo);
        return notify_queue($pdo, 'news_test:' . bin2hex(random_bytes(4)), 'admin', $to, 'Admin', '[Test] ' . $subject, notify_wrap($subject, $paras . notify_unsub_html($pdo, $to)), true) ? 1 : 0;
    }
    $pdo->prepare('INSERT INTO notify_newsletters (subject, body) VALUES (?, ?)')->execute([$subject, $text]);
    $cid = (int) $pdo->lastInsertId();
    $n = 0;
    foreach ($pdo->query('SELECT id, email FROM newsletter_subscribers ORDER BY id')->fetchAll() as $s) {
        if (notify_queue($pdo, 'news:' . $cid . ':' . $s['id'], 'subs', $s['email'], '', $subject, notify_wrap($subject, $paras . notify_unsub_html($pdo, $s['email'])), true)) $n++;
    }
    $pdo->prepare('UPDATE notify_newsletters SET recipients = ? WHERE id = ?')->execute([$n, $cid]);
    return $n;
}

function notify_stats(PDO $pdo): array {
    notify_ensure($pdo);
    $c = fn(string $w) => (int) $pdo->query("SELECT COUNT(*) FROM notify_outbox WHERE $w")->fetchColumn();
    return ['queued' => $c('sent_at IS NULL AND tries < 3'), 'sent' => $c('sent_at IS NOT NULL'), 'failed' => $c('sent_at IS NULL AND tries >= 3'),
        'smtp_ready' => defined('SMTP_PASS') && SMTP_PASS !== ''];
}

function notify_recent(PDO $pdo, int $limit = 30): array {
    notify_ensure($pdo);
    return $pdo->query('SELECT id, audience, to_email, subject, created_at, sent_at, tries, last_error FROM notify_outbox ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll();
}

// ------------------------------------------------------------------ background tick

/** Called on every page / API request. Cheap: at most once per few seconds it starts the background worker. */
function notify_tick(PDO $pdo): void {
    static $ticked = false;
    if ($ticked || PHP_SAPI === 'cli') return;
    $ticked = true;
    try {
        ops_setting($pdo, 'mail_last', '0');
        $pdo->prepare('INSERT IGNORE INTO ops_settings (k, v) VALUES (?, ?)')->execute(['mail_last', '0']);
        $now = time();
        $claim = $pdo->prepare('UPDATE ops_settings SET v = ? WHERE k = ? AND CAST(v AS UNSIGNED) < ?');
        $claim->execute([(string) $now, 'mail_last', $now - NOTIFY_THROTTLE_SECONDS]);
        if ($claim->rowCount() < 1) return;
    } catch (Throwable $e) {
        return;
    }
    $script = realpath(__DIR__ . '/../notify_cron.php');
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if ($script && function_exists('exec') && !in_array('exec', $disabled, true) && PHP_BINARY !== '') {
        $sock = (string) ini_get('pdo_mysql.default_socket'); // the worker needs the same database socket as this web server
        @exec(escapeshellarg(PHP_BINARY) . ($sock !== '' ? ' -d pdo_mysql.default_socket=' . escapeshellarg($sock) : '') . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
        return;
    }
    register_shutdown_function(function () use ($pdo) {
        try { notify_run($pdo); } catch (Throwable $e) {}
    });
}

function notify_run(PDO $pdo): void {
    $lock = (int) $pdo->query("SELECT GET_LOCK('ianova_notify_scan', 0)")->fetchColumn();
    if ($lock !== 1) return;
    try {
        notify_scan($pdo);
        notify_deliver($pdo, 6);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ianova_notify_scan')");
    }
}
