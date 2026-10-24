<?php
require_once 'includes/auth.php';
require_once __DIR__ . '/../includes/coupons.php';
cp_ensure($pdo);
$error = ''; $notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($do === 'save') {
        $m = cp_save($pdo, $id, $_POST + ['active' => 1]);
        if ($m === '') $notice = $id ? 'Coupon saved.' : 'Coupon created.'; else $error = $m;
    } elseif ($do === 'toggle') {
        $pdo->prepare('UPDATE coupons SET active = 1 - active WHERE id = ?')->execute([$id]); $notice = 'Done.';
    } elseif ($do === 'delete') {
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]); $notice = 'Coupon deleted. Past orders keep their discount.';
    }
}
$edit = null; $editId = (int) ($_GET['edit'] ?? 0);
$rows = cp_list($pdo);
foreach ($rows as $r) if ($r['id'] === $editId) $edit = $r;
$e = $edit ?: ['id' => 0, 'code' => '', 'kind' => 'percent', 'value' => 10, 'min_subtotal' => 0, 'max_uses' => 0, 'per_user' => 1, 'starts_at' => '', 'expires_at' => '', 'note' => ''];
$page_title = 'Coupons';
require 'includes/header.php';
$colors = ['live' => '#1e8e5a', 'off' => '#8a8f98', 'expired' => '#b3261e', 'used up' => '#b3261e', 'scheduled' => '#2b6fd6'];
?>
<style>.cpg{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end}.cpg label{font-size:12px;font-weight:700;color:#5b6670;display:block;margin-bottom:3px}.cpg input,.cpg select{width:100%;box-sizing:border-box;padding:9px;border:1px solid #d9dee3;border-radius:10px}.cpt{display:inline-block;padding:2px 9px;border-radius:999px;color:#fff;font-size:12px;font-weight:700}</style>
<h1>🎟️ Coupons</h1>
<?php if ($notice): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid #e0a100">
  <h3 style="margin:0 0 10px"><?= $e['id'] ? 'Edit coupon' : 'New coupon' ?></h3>
  <form method="post" class="cpg"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
    <div><label>Code</label><input name="code" required maxlength="30" value="<?= admin_h($e['code']) ?>" placeholder="WELCOME10" style="text-transform:uppercase"></div>
    <div><label>Type</label><select name="kind"><option value="percent" <?= $e['kind'] === 'percent' ? 'selected' : '' ?>>Percent off</option><option value="fixed" <?= $e['kind'] === 'fixed' ? 'selected' : '' ?>>Fixed amount off</option><option value="freeship" <?= $e['kind'] === 'freeship' ? 'selected' : '' ?>>Free delivery</option></select></div>
    <div><label>Value (% or amount)</label><input name="value" type="number" step="0.01" min="0" value="<?= admin_h($e['value']) ?>"></div>
    <div><label>Minimum order</label><input name="min_subtotal" type="number" step="1" min="0" value="<?= admin_h($e['min_subtotal']) ?>"></div>
    <div><label>Total uses (0 = unlimited)</label><input name="max_uses" type="number" min="0" value="<?= admin_h($e['max_uses']) ?>"></div>
    <div><label>Uses per customer (0 = unlimited)</label><input name="per_user" type="number" min="0" value="<?= admin_h($e['per_user']) ?>"></div>
    <div><label>Starts (optional)</label><input name="starts_at" type="date" value="<?= admin_h(substr((string) $e['starts_at'], 0, 10)) ?>"></div>
    <div><label>Ends (optional)</label><input name="expires_at" type="date" value="<?= admin_h(substr((string) $e['expires_at'], 0, 10)) ?>"></div>
    <div style="grid-column:1/-1"><label>Note for yourself (optional)</label><input name="note" maxlength="120" value="<?= admin_h($e['note']) ?>"></div>
    <div><button class="btn"><?= $e['id'] ? 'Save coupon' : 'Create coupon' ?></button> <?php if ($e['id']): ?><a class="btn gray" href="coupons.php">Cancel</a><?php endif; ?></div>
  </form>
  <p class="muted" style="margin:10px 0 0">The store pays for the discount: sellers still earn on the full item price.</p>
</div>
<?php if (!$rows): ?><div class="card muted">No coupons yet.</div><?php endif; ?>
<?php foreach ($rows as $r): ?>
<div class="card" style="margin-bottom:10px;border-left:5px solid <?= $colors[$r['state']] ?? '#8a8f98' ?>">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
    <div><b style="font-size:18px;letter-spacing:.04em"><?= admin_h($r['code']) ?></b> <span class="cpt" style="background:<?= $colors[$r['state']] ?? '#8a8f98' ?>"><?= admin_h($r['state']) ?></span><div><?= admin_h($r['label']) ?><?= $r['min_subtotal'] > 0 ? ' · min ' . number_format($r['min_subtotal'], 0) : '' ?></div>
      <div class="muted">Used <?= $r['used_count'] ?><?= $r['max_uses'] ? ' of ' . $r['max_uses'] : '' ?> · discount given <?= CURRENCY . number_format($r['given'], 2) ?><?= $r['expires_at'] ? ' · ends ' . admin_h(substr($r['expires_at'], 0, 10)) : '' ?><?= $r['note'] !== '' ? ' · ' . admin_h($r['note']) : '' ?></div></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap"><a class="btn gray" href="coupons.php?edit=<?= $r['id'] ?>">Edit</a>
      <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn gray"><?= $r['active'] ? 'Turn off' : 'Turn on' ?></button></form>
      <form method="post" style="margin:0" onsubmit="return confirm('Delete this coupon?')"><input type="hidden" name="csrf" value="<?= admin_csrf() ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn red">Delete</button></form></div>
  </div>
</div>
<?php endforeach; ?>
<?php require 'includes/footer.php'; ?>
