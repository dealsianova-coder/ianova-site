<?php require 'includes/auth.php';
$id=(int)($_GET['id']??0);
if($id&&hash_equals(admin_csrf(),(string)($_GET['csrf']??''))){
  $u=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');$u->execute([$id]);
  if((int)$u->fetchColumn()>0){$pdo->prepare("UPDATE products SET status='hidden' WHERE id=?")->execute([$id]);}
  else{$pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);}
}
header('Location: products.php');exit;
