<?php
/**
 * Remove a customer safely.
 * - No orders: the account and its data are deleted.
 * - Has orders: the account is closed (cannot sign in, personal details removed) but the orders stay, so your sales records and seller payouts keep adding up.
 * Returns ['mode' => 'deleted'|'closed'|'missing', 'message' => string].
 */
function user_remove(PDO $pdo, int $id): array {
    $q = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?'); $q->execute([$id]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) return ['mode' => 'missing', 'message' => 'Customer not found.'];
    $n = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?'); $n->execute([$id]);
    $orders = (int) $n->fetchColumn();
    $clean = function (string $sql, array $p) use ($pdo) { try { $pdo->prepare($sql)->execute($p); } catch (Throwable $e) { /* table may not exist */ } };
    $clean('DELETE FROM api_tokens WHERE user_id = ?', [$id]);
    $clean('DELETE FROM cart_items WHERE user_id = ?', [$id]);
    $clean('DELETE FROM user_addresses WHERE user_id = ?', [$id]);
    $clean("DELETE FROM device_tokens WHERE kind = 'app' AND owner_id = ?", [$id]);
    if ($orders === 0) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        return ['mode' => 'deleted', 'message' => 'Customer deleted.'];
    }
    // keep the name/email on the orders themselves (delivery and tax records), then close the account
    $clean('UPDATE orders SET guest_name = COALESCE(NULLIF(guest_name, \'\'), ?), guest_email = COALESCE(NULLIF(guest_email, \'\'), ?) WHERE user_id = ?', [(string) $u['name'], (string) $u['email'], $id]);
    $pdo->prepare('UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?')->execute(['Closed account', 'closed-' . $id . '@closed.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $id]);
    return ['mode' => 'closed', 'message' => 'This customer has ' . $orders . ' order(s), so the account was closed and the orders were kept.'];
}
