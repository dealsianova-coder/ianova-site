<?php
require_once 'includes/auth.php';
if(isset($_GET['delete'])&&hash_equals(admin_csrf(),(string)($_GET['csrf']??''))){$pdo->prepare('DELETE FROM newsletter_subscribers WHERE id=?')->execute([(int)$_GET['delete']]);header('Location: subscribers.php');exit;}$rows=$pdo->query('SELECT * FROM newsletter_subscribers ORDER BY id DESC')->fetchAll();
$page_title='Newsletter subscribers';
require 'includes/header.php';
?><div class="table-wrap"><table class="table"><tr><th>Email</th><th>Subscribed</th><th>Action</th></tr><?php foreach($rows as $r):?><tr><td><?=admin_h($r['email'])?></td><td><?=admin_h($r['subscribed_at'])?></td><td><a class="btn red" href="subscribers.php?delete=<?=intval($r['id'])?>&csrf=<?=admin_csrf()?>" onclick="return confirm('Delete subscriber?')">Delete</a></td></tr><?php endforeach;?></table></div><?php require 'includes/footer.php';
