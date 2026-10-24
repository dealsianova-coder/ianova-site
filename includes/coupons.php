<?php
/**
 * Coupons: percent off, fixed amount off, or free delivery.
 * Tables are created on first use (remembered in ops_settings, so it only runs once).
 * The discount is paid by the store: sellers still earn on the full item prices.
 */
require_once __DIR__ . '/seller_ops.php';

function cp_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (ops_setting($pdo, 'schema_cp', '') === '1') return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, kind VARCHAR(10) NOT NULL DEFAULT 'percent', value DECIMAL(12,2) NOT NULL DEFAULT 0, min_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0, max_uses INT NOT NULL DEFAULT 0, per_user INT NOT NULL DEFAULT 1, used_count INT NOT NULL DEFAULT 0, starts_at DATETIME NULL, expires_at DATETIME NULL, active TINYINT NOT NULL DEFAULT 1, note VARCHAR(120) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS coupon_uses (id INT AUTO_INCREMENT PRIMARY KEY, coupon_id INT NOT NULL, user_id INT NOT NULL DEFAULT 0, order_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (order_id), KEY (coupon_id, user_id)) ENGINE=InnoDB");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(30) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount DECIMAL(12,2) NOT NULL DEFAULT 0");
        ops_setting_set($pdo, 'schema_cp', '1');
    } catch (Throwable $e) { /* columns may already exist */ }
}

function cp_clean_code(string $c): string {
    return strtoupper(substr(preg_replace('/[^A-Za-z0-9_-]/', '', trim($c)), 0, 30));
}

function cp_find(PDO $pdo, string $code, bool $lock = false): ?array {
    cp_ensure($pdo);
    $code = cp_clean_code($code);
    if ($code === '') return null;
    $s = $pdo->prepare('SELECT * FROM coupons WHERE code = ?' . ($lock ? ' FOR UPDATE' : ''));
    $s->execute([$code]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** How much this coupon takes off. Never more than the order itself. */
function cp_amount(array $c, float $subtotal, float $delivery): float {
    $v = (float) $c['value'];
    if ($c['kind'] === 'percent') $a = $subtotal * min(100.0, max(0.0, $v)) / 100;
    elseif ($c['kind'] === 'freeship') $a = $delivery;
    else $a = min($v, $subtotal);
    return round(max(0.0, min($a, $subtotal + $delivery)), 2);
}

function cp_label(array $c): string {
    if ($c['kind'] === 'percent') return rtrim(rtrim(number_format((float) $c['value'], 2), '0'), '.') . '% off';
    if ($c['kind'] === 'freeship') return 'Free delivery';
    return number_format((float) $c['value'], 0) . ' off';
}

/** ['ok'=>bool,'message'=>string,'coupon'=>row,'discount'=>float]. $lock = inside the checkout transaction. */
function cp_check(PDO $pdo, string $code, int $uid, float $subtotal, float $delivery, bool $lock = false): array {
    $c = cp_find($pdo, $code, $lock);
    $bad = fn(string $m) => ['ok' => false, 'message' => $m, 'coupon' => null, 'discount' => 0.0];
    if (!$c || !(int) $c['active']) return $bad('This coupon code is not valid.');
    $now = date('Y-m-d H:i:s');
    if ($c['starts_at'] && $c['starts_at'] > $now) return $bad('This coupon is not active yet.');
    if ($c['expires_at'] && $c['expires_at'] < $now) return $bad('This coupon has expired.');
    if ((int) $c['max_uses'] > 0 && (int) $c['used_count'] >= (int) $c['max_uses']) return $bad('This coupon has been fully used.');
    if ($subtotal < (float) $c['min_subtotal']) return $bad('Spend at least ' . number_format((float) $c['min_subtotal'], 0) . ' to use this coupon.');
    if ((int) $c['per_user'] > 0 && $uid > 0) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM coupon_uses WHERE coupon_id = ? AND user_id = ?');
        $q->execute([(int) $c['id'], $uid]);
        if ((int) $q->fetchColumn() >= (int) $c['per_user']) return $bad('You have already used this coupon.');
    }
    $d = cp_amount($c, $subtotal, $delivery);
    if ($d <= 0) return $bad('This coupon does not apply to your order.');
    return ['ok' => true, 'message' => cp_label($c) . ' applied.', 'coupon' => $c, 'discount' => $d];
}

/** Records the use on a newly placed order (call inside the checkout transaction). */
function cp_apply(PDO $pdo, int $orderId, int $uid, array $c, float $discount): void {
    $pdo->prepare('INSERT INTO coupon_uses (coupon_id, user_id, order_id, amount) VALUES (?,?,?,?)')->execute([(int) $c['id'], $uid, $orderId, $discount]);
    $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([(int) $c['id']]);
    $pdo->prepare('UPDATE orders SET coupon_code = ?, discount = ? WHERE id = ?')->execute([$c['code'], $discount, $orderId]);
}

/** A cancelled order gives its coupon use back. */
function cp_release(PDO $pdo, int $orderId): void {
    try {
        cp_ensure($pdo);
        $q = $pdo->prepare('SELECT id, coupon_id FROM coupon_uses WHERE order_id = ?');
        $q->execute([$orderId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $pdo->prepare('DELETE FROM coupon_uses WHERE id = ?')->execute([$u['id']]);
            $pdo->prepare('UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?')->execute([$u['coupon_id']]);
        }
    } catch (Throwable $e) {
    }
}

/** The customer changed the items of a pending order: recalculate the discount. Returns the new discount. */
function cp_reprice(PDO $pdo, int $orderId, float $subtotal, float $delivery): float {
    try {
        cp_ensure($pdo);
        $q = $pdo->prepare('SELECT coupon_code FROM orders WHERE id = ?');
        $q->execute([$orderId]);
        $code = (string) $q->fetchColumn();
        if ($code === '') return 0.0;
        $c = cp_find($pdo, $code);
        $d = ($c && $subtotal >= (float) $c['min_subtotal']) ? cp_amount($c, $subtotal, $delivery) : 0.0;
        $pdo->prepare('UPDATE orders SET discount = ? WHERE id = ?')->execute([$d, $orderId]);
        $pdo->prepare('UPDATE coupon_uses SET amount = ? WHERE order_id = ?')->execute([$d, $orderId]);
        return $d;
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** For the admin screens. */
function cp_list(PDO $pdo): array {
    cp_ensure($pdo);
    $rows = $pdo->query('SELECT c.*, (SELECT COALESCE(SUM(u.amount),0) FROM coupon_uses u WHERE u.coupon_id = c.id) given FROM coupons c ORDER BY c.active DESC, c.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $now = date('Y-m-d H:i:s');
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id']; $r['value'] = (float) $r['value']; $r['min_subtotal'] = (float) $r['min_subtotal'];
        $r['max_uses'] = (int) $r['max_uses']; $r['per_user'] = (int) $r['per_user']; $r['used_count'] = (int) $r['used_count']; $r['active'] = (int) $r['active']; $r['given'] = round((float) $r['given'], 2);
        $r['label'] = cp_label($r);
        $r['state'] = !$r['active'] ? 'off' : (($r['expires_at'] && $r['expires_at'] < $now) ? 'expired' : (($r['max_uses'] > 0 && $r['used_count'] >= $r['max_uses']) ? 'used up' : (($r['starts_at'] && $r['starts_at'] > $now) ? 'scheduled' : 'live')));
    }
    return $rows;
}

/** Create or update. Returns '' or a message. */
function cp_save(PDO $pdo, int $id, array $in): string {
    cp_ensure($pdo);
    $code = cp_clean_code((string) ($in['code'] ?? ''));
    if (strlen($code) < 3) return 'The code needs at least 3 letters or numbers.';
    $kind = (string) ($in['kind'] ?? 'percent');
    if (!in_array($kind, ['percent', 'fixed', 'freeship'], true)) return 'Choose percent, fixed amount or free delivery.';
    $value = max(0.0, (float) ($in['value'] ?? 0));
    if ($kind === 'percent' && ($value <= 0 || $value > 100)) return 'A percent coupon needs a value between 1 and 100.';
    if ($kind === 'fixed' && $value <= 0) return 'A fixed coupon needs an amount above 0.';
    if ($kind === 'freeship') $value = 0;
    $min = max(0.0, (float) ($in['min_subtotal'] ?? 0));
    $max = max(0, (int) ($in['max_uses'] ?? 0));
    $per = max(0, (int) ($in['per_user'] ?? 1));
    $norm = function ($v) { $v = trim((string) $v); if ($v === '') return null; $t = strtotime($v); return $t ? date('Y-m-d H:i:s', $t) : null; };
    $from = $norm($in['starts_at'] ?? ''); $to = $norm($in['expires_at'] ?? '');
    if ($to !== null && strlen(trim((string) ($in['expires_at'] ?? ''))) <= 10) $to = date('Y-m-d 23:59:59', strtotime($to));
    if ($from && $to && $to < $from) return 'The end date is before the start date.';
    $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 120);
    $active = !empty($in['active']) ? 1 : 0;
    $dup = $pdo->prepare('SELECT id FROM coupons WHERE code = ? AND id <> ?'); $dup->execute([$code, $id]);
    if ($dup->fetchColumn()) return 'Another coupon already uses this code.';
    if ($id > 0) {
        $pdo->prepare('UPDATE coupons SET code=?, kind=?, value=?, min_subtotal=?, max_uses=?, per_user=?, starts_at=?, expires_at=?, active=?, note=? WHERE id=?')
            ->execute([$code, $kind, $value, $min, $max, $per, $from, $to, $active, $note, $id]);
    } else {
        $pdo->prepare('INSERT INTO coupons (code, kind, value, min_subtotal, max_uses, per_user, starts_at, expires_at, active, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$code, $kind, $value, $min, $max, $per, $from, $to, 1, $note]);
    }
    return '';
}
