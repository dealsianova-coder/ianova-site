<?php
/** Shared look for the seller site (same style as the admin). Pages call seller_head() and seller_foot(). */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/seller_ops.php';
if (empty($_SESSION['seller_id'])) { header('Location: login.php'); exit; }
ops_ensure($pdo);
if (empty($_SESSION['ops_csrf'])) $_SESSION['ops_csrf'] = bin2hex(random_bytes(16));
$seller_id = (int) $_SESSION['seller_id'];
$__sq = $pdo->prepare('SELECT * FROM sellers WHERE id = ?');
$__sq->execute([$seller_id]);
$seller = $__sq->fetch();
if (!$seller) { header('Location: logout.php'); exit; }

function sel_img(?string $path): string {
    $path = trim((string) $path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return '../' . ltrim($path, '/');
}
function sel_photo(?string $path, string $alt = '', string $class = 'ph', string $fallback = '🛍️', bool $zoom = false): string {
    $src = sel_img($path);
    if ($src === '') return '<div class="' . $class . ' ph-empty"><span>' . ops_h($fallback) . '</span></div>';
    $s = ops_h($src);
    return '<div class="' . $class . '"><img src="' . $s . '" alt="' . ops_h($alt) . '" loading="lazy"' . ($zoom ? ' data-zoom="' . $s . '"' : '') . '></div>';
}
function sel_pill(string $st): string {
    $k = strtolower($st);
    $c = in_array($k, ['approved', 'paid', 'delivered', 'active'], true) ? 'ok' : (in_array($k, ['pending', 'shipped', 'new'], true) ? 'warn' : 'bad');
    return '<span class="spill ' . $c . '">' . ops_h(ucfirst($st)) . '</span>';
}

/** What the seller has not dealt with yet. */
function sel_counts(PDO $pdo, int $sid): array {
    $c = ops_seller_counts($pdo, $sid);
    $q = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ? AND status = 'pending'");
    $q->execute([$sid]);
    return ['orders' => (int) $c['pending_orders'], 'messages' => (int) $c['unread_messages'], 'locked' => (int) $c['locked_products'], 'pending' => (int) $q->fetchColumn()];
}

function seller_head(string $title, string $active = ''): void {
    global $pdo, $seller, $seller_id;
    $n = sel_counts($pdo, $seller_id);
    $nav = [
        ['index.php', '📊', 'Dashboard', 0],
        ['products.php', '📦', 'Products', $n['locked'] + $n['pending']],
        ['orders.php', '🛒', 'Orders', $n['orders']],
        ['messages.php', '💬', 'Messages', $n['messages']],
        ['auto_approval.php', '⚙️', 'Order approval', 0],
        ['product_edit.php', '➕', 'Add product', 0],
    ];
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= ops_h($title) ?> — IANOVA Seller</title>
<link rel="icon" href="../assets/img/favicon.png">
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f5f7f6;color:#17231e}a{color:inherit;text-decoration:none}.layout{display:flex;min-height:100vh}.side{width:245px;background:#17352c;color:#fff;padding:24px 16px;position:sticky;top:0;height:100vh;overflow:auto}.brand{font-size:25px;font-weight:800;margin:0 8px 28px}.nav a{display:block;padding:11px 12px;border-radius:10px;margin:4px 0;color:#dce8e2}.nav a:hover,.nav a.active{background:#285445;color:#fff}.main{flex:1;padding:28px;min-width:0}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px}.top h1{margin:0;font-size:28px}.user{font-size:13px;color:#607168}.btn{display:inline-block;border:0;border-radius:9px;padding:10px 14px;background:#1d6b52;color:#fff;cursor:pointer;font-weight:700}.btn.gold{background:#b58117}.btn.red{background:#b83b3b}.btn.gray{background:#64736d}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.card{background:#fff;border:1px solid #dce4df;border-radius:14px;padding:18px;box-shadow:0 3px 14px rgba(20,45,35,.04)}.metric{font-size:28px;font-weight:800;margin-top:7px}.muted{color:#6b7973;font-size:13px}.table-wrap{overflow:auto;background:#fff;border:1px solid #dce4df;border-radius:14px}.table{width:100%;border-collapse:collapse;min-width:800px}.table th,.table td{padding:12px 14px;border-bottom:1px solid #e8eeea;text-align:left;vertical-align:middle}.table th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;background:#f7f9f8}.thumb{width:52px;height:52px;object-fit:cover;border-radius:9px;background:#eef3ef}.form-card{max-width:1000px}.row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.field{margin-bottom:15px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}.field input,.field textarea,.field select{width:100%;padding:11px;border:1px solid #ccd7d1;border-radius:9px;background:#fff;font:inherit}.field textarea{min-height:120px;resize:vertical}.actions{display:flex;gap:8px;flex-wrap:wrap}.notice{padding:12px 14px;border-radius:9px;background:#e7f4ed;margin-bottom:16px}.error{background:#fdeaea;color:#8a2424}.search{padding:10px;border:1px solid #ccd7d1;border-radius:9px;width:280px}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#e7f0eb;font-size:12px}.login{max-width:430px;margin:10vh auto;background:#fff;padding:28px;border-radius:16px;border:1px solid #dce4df}.login h1{margin-top:0}@media(max-width:900px){.side{width:190px}.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:650px){.layout{display:block}.side{width:100%;height:auto;position:static}.nav{display:grid;grid-template-columns:repeat(2,1fr)}.main{padding:16px}.row{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style><link rel="stylesheet" href="../assets/css/admin-theme.css?v=3"></head><body><div class="layout"><aside class="side"><a class="brand" href="index.php"><img src="../assets/img/ianova-wordmark.png" alt="IANOVA" height="34"></a><div class="muted" style="color:#9AA0AE;margin:-14px 8px 14px;font-size:12px">SELLER</div><nav class="nav">
<?php foreach ($nav as $i): ?><a href="<?= $i[0] ?>" class="<?= $active === $i[0] ? 'active' : '' ?>"><?= $i[1] ?> <?= $i[2] ?><?php if ($i[3] > 0): ?> <span class="nbadge"><?= (int) $i[3] ?></span><?php endif; ?></a><?php endforeach; ?>
<a href="../index.php" target="_blank" rel="noopener">🌐 View store</a><a href="logout.php">🚪 Sign out</a>
</nav></aside><main class="main"><div class="top"><div><h1><?= ops_h($title) ?></h1><div class="user"><?= ops_h($seller['business_name']) ?> · <?= ops_h(ucfirst((string) ($seller['status'] ?? ''))) ?></div></div></div>
<?php if (!empty($_SESSION['ops_flash'])): ?><div class="notice"><?= ops_h($_SESSION['ops_flash']) ?></div><?php unset($_SESSION['ops_flash']); endif; ?>
<?php if (in_array($seller['status'] ?? '', ['suspended', 'rejected'], true)): ?><div class="notice error"><b>Your seller account is restricted by the admin.</b> You cannot add or edit products or approve orders until the admin approves it again.
<form method="post" action="request_review.php" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= ops_h($_SESSION['ops_csrf']) ?>"><input type="hidden" name="product_id" value="0"><div class="field" style="margin-bottom:8px"><input name="note" maxlength="800" placeholder="What did you correct?"></div><button class="btn" type="submit">Request review</button></form></div><?php endif; ?>
<?php
}

function seller_foot(): void { ?>
</main></div>
<script>
(function(){
  document.querySelectorAll('.table').forEach(function(t){
    var rows=[].slice.call(t.querySelectorAll('tr')), labels=[];
    rows.forEach(function(r){
      var ths=r.querySelectorAll('th');
      if(ths.length){ r.classList.add('th-row'); labels=[].map.call(ths,function(h){return h.textContent.trim();}); return; }
      [].forEach.call(r.children,function(c,i){
        if(c.tagName==='TD'){ if(labels[i]) c.setAttribute('data-label',labels[i]); if(i===0) c.classList.add('cell-main'); if(/action/i.test(labels[i]||'')) c.classList.add('cell-actions'); }
      });
    });
  });
})();
document.addEventListener('click',function(e){
  var t=e.target.closest('[data-zoom]'); if(!t) return;
  e.preventDefault();
  var o=document.createElement('div'); o.className='zoom-ov';
  o.innerHTML='<img src="'+t.getAttribute('data-zoom')+'" alt=""><button type="button" aria-label="Close">×</button>';
  o.addEventListener('click',function(){o.remove();});
  document.body.appendChild(o);
});
</script>
</body></html>
<?php }
