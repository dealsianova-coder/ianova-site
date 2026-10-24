<?php
/** What the signed-in seller has earned, been paid and is still owed. */
require __DIR__ . '/_seller.php';
require_once __DIR__ . '/../includes/payouts.php';
$seller = require_approved_seller($pdo);
$sid = (int) $seller['id'];
$f = po_figures($pdo, $sid)[$sid];
out(['success' => true, 'balance' => $f, 'payouts' => po_history($pdo, $sid, 20)]);
