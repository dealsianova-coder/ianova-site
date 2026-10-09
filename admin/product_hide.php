<?php
require 'includes/auth.php';
$id=(int)($_GET['id']??0);
if($id){$s=$pdo->prepare("UPDATE products SET status='hidden' WHERE id=?");$s->execute([$id]);}
header('Location: products.php');
exit;
