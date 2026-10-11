<?php
require 'includes/auth.php';
require_once __DIR__ . '/../includes/seller_ops.php';
$id=(int)($_GET['id']??0);
if($id){$old=ops_product_status($pdo,$id);$s=$pdo->prepare("UPDATE products SET status='approved' WHERE id=?");$s->execute([$id]);ops_product_status_changed($pdo,$id,$old,'approved');}
header('Location: products.php');
exit;
