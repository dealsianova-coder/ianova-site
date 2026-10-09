<?php $page_title='Dashboard'; require 'includes/header.php';
$metrics=[
'Products'=>(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
'Categories'=>(int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
'Orders'=>(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
'Users'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
];
$revenue=(float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','shipped','delivered')")->fetchColumn();
$recent=$pdo->query("SELECT o.id,COALESCE(guest_name,u.name,'Guest') customer,total,status,o.created_at FROM orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 8")->fetchAll();
?><div class="grid"><?php foreach($metrics as $k=>$v):?><div class="card"><div class="muted"><?=admin_h($k)?></div><div class="metric"><?=$v?></div></div><?php endforeach;?><div class="card"><div class="muted">Confirmed revenue</div><div class="metric"><?=CURRENCY.number_format($revenue,2)?></div></div></div><div class="card" style="margin-top:20px"><div class="top" style="margin-bottom:10px"><h2 style="margin:0">Recent orders</h2><a class="btn" href="orders.php">Manage orders</a></div><div class="table-wrap"><table class="table"><tr><th>#</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th></tr><?php foreach($recent as $o):?><tr><td>#<?=intval($o['id'])?></td><td><?=admin_h($o['customer'])?></td><td><?=CURRENCY.number_format($o['total'],2)?></td><td><span class="pill"><?=admin_h($o['status'])?></span></td><td><?=admin_h($o['created_at'])?></td></tr><?php endforeach;?></table></div></div><?php require 'includes/footer.php';
