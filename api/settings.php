<?php
require __DIR__ . '/_bootstrap.php';

// The delivery / returns / seller blurbs the owner already wrote in the admin
// settings. "Secure checkout" is left out on purpose: no online payment yet.
$trust = [];
foreach (['delivery', 'returns', 'verified'] as $key) {
    $title = trim((string) store_setting($pdo, "trust_{$key}_title"));
    $text = trim((string) store_setting($pdo, "trust_{$key}_text"));
    if ($title !== '') {
        $trust[] = ['key' => $key, 'title' => $title, 'text' => $text];
    }
}

out(['success' => true, 'settings' => [
    'free_delivery_threshold' => store_free_delivery_threshold($pdo),
    'delivery_fee' => store_delivery_fee($pdo),
    'trust' => $trust,
]]);
