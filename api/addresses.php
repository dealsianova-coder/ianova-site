<?php
require __DIR__ . '/_bootstrap.php';
$user = require_user($pdo);
$uid = (int) $user['id'];

function addr_row(PDO $pdo, int $id, int $uid): ?array {
    $s = $pdo->prepare('SELECT id, label, recipient_name, phone, address, is_default FROM user_addresses WHERE id = ? AND user_id = ?');
    $s->execute([$id, $uid]);
    $r = $s->fetch();
    if ($r) { $r['is_default'] = (bool) $r['is_default']; }
    return $r ?: null;
}
function addr_fields(array $b): array {
    $f = [trim((string) ($b['label'] ?? '')), trim((string) ($b['recipient_name'] ?? '')),
          trim((string) ($b['phone'] ?? '')), trim((string) ($b['address'] ?? '')), !empty($b['is_default']) ? 1 : 0];
    if ($f[1] === '' || $f[2] === '' || $f[3] === '') fail('Name, phone and address are required.');
    return $f;
}

switch (method()) {
    case 'GET':
        $s = $pdo->prepare('SELECT id, label, recipient_name, phone, address, is_default FROM user_addresses
                            WHERE user_id = ? ORDER BY is_default DESC, id DESC');
        $s->execute([$uid]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) $r['is_default'] = (bool) $r['is_default'];
        out(['success' => true, 'addresses' => $rows]);

    case 'POST':
        [$label, $name, $phone, $addr, $def] = addr_fields(body());
        $n = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = ?');
        $n->execute([$uid]);
        if ((int) $n->fetchColumn() === 0) $def = 1;       // first address is the default
        if ($def) $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('INSERT INTO user_addresses (user_id, label, recipient_name, phone, address, is_default) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $label, $name, $phone, $addr, $def]);
        out(['success' => true, 'address' => addr_row($pdo, (int) $pdo->lastInsertId(), $uid)], 201);

    case 'PUT':
        $b = body();
        $id = (int) ($b['id'] ?? 0);
        if (!addr_row($pdo, $id, $uid)) fail('Address not found.', 404);
        [$label, $name, $phone, $addr, $def] = addr_fields($b);
        if ($def) $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('UPDATE user_addresses SET label=?, recipient_name=?, phone=?, address=?, is_default=? WHERE id=? AND user_id=?')
            ->execute([$label, $name, $phone, $addr, $def, $id, $uid]);
        out(['success' => true, 'address' => addr_row($pdo, $id, $uid)]);

    case 'DELETE':
        $id = (int) (body()['id'] ?? 0);
        $row = addr_row($pdo, $id, $uid);
        if (!$row) fail('Address not found.', 404);
        $pdo->prepare('DELETE FROM user_addresses WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        if ($row['is_default']) {   // promote the newest remaining address
            $pdo->prepare('UPDATE user_addresses SET is_default = 1 WHERE user_id = ? ORDER BY id DESC LIMIT 1')->execute([$uid]);
        }
        out(['success' => true]);

    default:
        fail('Method not allowed.', 405);
}
