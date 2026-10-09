<?php require 'includes/auth.php';$id=(int)($_GET['id']??0);if($id){$s=$pdo->prepare('DELETE FROM products WHERE id=?');$s->execute([$id]);}header('Location: products.php');exit;
