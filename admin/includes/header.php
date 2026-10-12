<?php require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/ui.php';
$__n = adm_counts($pdo);
$__cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__nav = [
  ['index.php', '📊', 'Dashboard', 0],
  ['products.php', '📦', 'Products', $__n['products']],
  ['categories.php', '🏷️', 'Categories', 0],
  ['orders.php', '🛒', 'Orders', $__n['orders']],
  ['notifications.php', '🔔', 'Alerts', $__n['alerts']],
  ['users.php', '👥', 'Users', 0],
  ['sellers.php', '🏪', 'Sellers', $__n['sellers'] + $__n['messages']],
  ['subscribers.php', '✉️', 'Subscribers', 0],
  ['suggestions.php', '💡', 'Suggestions', $__n['suggestions']],
  ['banners.php', '🖼️', 'Banners', 0],
  ['store.php', '🎛️', 'Store control', 0],
  ['flash.php', '⚡', 'Flash deals', 0],
  ['settings.php', '⚙️', 'Settings', 0],
];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= admin_h($page_title ?? 'Admin') ?> — <?= admin_h(SITE_NAME) ?></title>
<link rel="icon" href="../assets/img/favicon.png">
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f5f7f6;color:#17231e}a{color:inherit;text-decoration:none}.layout{display:flex;min-height:100vh}.side{width:245px;background:#17352c;color:#fff;padding:24px 16px;position:sticky;top:0;height:100vh;overflow:auto}.brand{font-size:25px;font-weight:800;margin:0 8px 28px}.nav a{display:block;padding:11px 12px;border-radius:10px;margin:4px 0;color:#dce8e2}.nav a:hover,.nav a.active{background:#285445;color:#fff}.main{flex:1;padding:28px;min-width:0}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px}.top h1{margin:0;font-size:28px}.user{font-size:13px;color:#607168}.btn{display:inline-block;border:0;border-radius:9px;padding:10px 14px;background:#1d6b52;color:#fff;cursor:pointer;font-weight:700}.btn.gold{background:#b58117}.btn.red{background:#b83b3b}.btn.gray{background:#64736d}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.card{background:#fff;border:1px solid #dce4df;border-radius:14px;padding:18px;box-shadow:0 3px 14px rgba(20,45,35,.04)}.metric{font-size:28px;font-weight:800;margin-top:7px}.muted{color:#6b7973;font-size:13px}.table-wrap{overflow:auto;background:#fff;border:1px solid #dce4df;border-radius:14px}.table{width:100%;border-collapse:collapse;min-width:800px}.table th,.table td{padding:12px 14px;border-bottom:1px solid #e8eeea;text-align:left;vertical-align:middle}.table th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;background:#f7f9f8}.thumb{width:52px;height:52px;object-fit:cover;border-radius:9px;background:#eef3ef}.form-card{max-width:1000px}.row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.field{margin-bottom:15px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}.field input,.field textarea,.field select{width:100%;padding:11px;border:1px solid #ccd7d1;border-radius:9px;background:#fff;font:inherit}.field textarea{min-height:120px;resize:vertical}.actions{display:flex;gap:8px;flex-wrap:wrap}.notice{padding:12px 14px;border-radius:9px;background:#e7f4ed;margin-bottom:16px}.error{background:#fdeaea;color:#8a2424}.search{padding:10px;border:1px solid #ccd7d1;border-radius:9px;width:280px}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#e7f0eb;font-size:12px}.login{max-width:430px;margin:10vh auto;background:#fff;padding:28px;border-radius:16px;border:1px solid #dce4df}.login h1{margin-top:0}@media(max-width:900px){.side{width:190px}.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:650px){.layout{display:block}.side{width:100%;height:auto;position:static}.nav{display:grid;grid-template-columns:repeat(2,1fr)}.main{padding:16px}.row{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style><link rel="stylesheet" href="../assets/css/admin-theme.css?v=3"></head><body><div class="layout"><aside class="side"><a class="brand" href="index.php"><img src="../assets/img/ianova-wordmark.png" alt="IANOVA" height="34"></a><nav class="nav">
<?php foreach ($__nav as $__i): ?><a href="<?= $__i[0] ?>" class="<?= $__cur === $__i[0] ? 'active' : '' ?>"><?= $__i[1] ?> <?= $__i[2] ?><?php if ($__i[3] > 0): ?> <span class="nbadge"><?= (int) $__i[3] ?></span><?php endif; ?></a><?php endforeach; ?>
<a href="../seller/" target="_blank" rel="noopener">🧑‍💼 Seller site</a><a href="../index.php" target="_blank" rel="noopener">🌐 View store</a><a href="logout.php">🚪 Logout</a>
</nav></aside><main class="main"><div class="top"><div><h1><?= admin_h($page_title ?? 'Admin') ?></h1><div class="user">Signed in as <?= admin_h($_SESSION['admin_name'] ?? 'Administrator') ?></div></div></div>
