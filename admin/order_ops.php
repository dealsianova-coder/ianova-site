<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/order_ops.php';
require_once __DIR__ . '/../includes/payments.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: orders.php'); exit; }
admin_check_csrf();
$id = (int) ($_POST['id'] ?? 0);
$me = (int) ($_SESSION['admin_id'] ?? 0);
$kind = (string) ($_POST['kind'] ?? '');
$m = 'Unknown action.';
if ($kind === 'tracking') $m = oo_tracking_save($pdo, $me, $id, (string) ($_POST['courier'] ?? ''), (string) ($_POST['number'] ?? ''), (string) ($_POST['note'] ?? ''));
elseif ($kind === 'refund') $m = oo_refund_add($pdo, $me, $id, (float) ($_POST['amount'] ?? 0), (string) ($_POST['reason'] ?? ''), (string) ($_POST['method'] ?? ''));
elseif ($kind === 'payment') $m = pay_add($pdo, $me, $id, (float) ($_POST['amount'] ?? 0), (string) ($_POST['method'] ?? ''), (string) ($_POST['reference'] ?? ''), '');
elseif ($kind === 'payment_delete') $m = pay_delete($pdo, $me, (int) ($_POST['payment_id'] ?? 0));
elseif ($kind === 'item_status') $m = oo_item_status($pdo, $me, $id, (int) ($_POST['product_id'] ?? 0), (string) ($_POST['status'] ?? ''));
elseif ($kind === 'item_cancel') $m = oo_item_cancel($pdo, $me, $id, (int) ($_POST['product_id'] ?? 0));
$_SESSION['oo_flash'] = $m === '' ? 'Saved.' : $m;
$_SESSION['oo_flash_err'] = $m !== '' ? 1 : 0;
header('Location: order_view.php?id=' . $id);
