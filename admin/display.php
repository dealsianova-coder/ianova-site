<?php
require_once 'includes/auth.php';

$tabs = ['sections' => 'Home sections', 'banners' => 'Banners', 'categories' => 'Categories', 'products' => 'Products'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'sections';

$sections = [
    ['label' => 'Flash deals', 'note' => 'The countdown and deal products on the home page.', 'show' => 'show_flash', 'disp' => 'disp_flash', 'default' => '1', 'both' => true],
    ['label' => 'Announcement bar', 'note' => 'The message across the top (write it in Store control).', 'show' => 'announcement_enabled', 'disp' => 'disp_announcement', 'default' => '0', 'both' => true],
    ['label' => 'Video ad', 'note' => 'The video pop-up (set the video in Video ad).', 'show' => 'video_ad_enabled', 'disp' => 'disp_video_ad', 'default' => '0', 'both' => true],
    ['label' => 'Sale shortcut', 'note' => 'App home screen only.', 'show' => 'show_sale', 'default' => '1', 'both' => false],
    ['label' => 'Category shortcuts', 'note' => 'App home screen only.', 'show' => 'show_categories', 'default' => '1', 'both' => false],
    ['label' => 'Recently viewed', 'note' => 'App home screen only.', 'show' => 'show_recent', 'default' => '1', 'both' => false],
    ['label' => 'Popular', 'note' => 'App home screen only.', 'show' => 'show_popular', 'default' => '1', 'both' => false],
];

$pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB");

function disp_set(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

$back = 'display.php?tab=' . $tab . '&saved=1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    if ($tab === 'sections') {
        foreach ($sections as $sec) {
            $v = (string) ($_POST['sec'][$sec['show']] ?? '');
            if ($v === 'off') {
                disp_set($pdo, $sec['show'], '0');
            } elseif ($v === 'on' || in_array($v, ['both', 'web', 'app'], true)) {
                disp_set($pdo, $sec['show'], '1');
                if ($sec['both'] && $v !== 'on') disp_set($pdo, $sec['disp'], $v);
            }
        }
    } elseif ($tab === 'banners' || $tab === 'categories') {
        $table = $tab === 'banners' ? 'banners' : 'categories';
        $upd = $pdo->prepare("UPDATE `$table` SET show_on = ? WHERE id = ?");
        foreach ((array) ($_POST['show'] ?? []) as $id => $v) {
            if (in_array($v, ['both', 'web', 'app'], true)) $upd->execute([$v, (int) $id]);
        }
    } else {
        // Products: a product with colors/sizes is several rows; change them all together.
        $get = $pdo->prepare('SELECT category_id, COALESCE(seller_id, 0) AS sid, name FROM products WHERE id = ?');
        $upd = $pdo->prepare("UPDATE products SET show_on = ? WHERE category_id = ? AND COALESCE(seller_id, 0) = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))");
        foreach ((array) ($_POST['show'] ?? []) as $id => $v) {
            $id = (int) $id;
            if (!in_array($v, ['both', 'web', 'app'], true)) continue;
            if (($_POST['prev'][$id] ?? '') === $v) continue;
            $get->execute([$id]);
            $row = $get->fetch();
            if ($row) $upd->execute([$v, (int) $row['category_id'], (int) $row['sid'], $row['name']]);
        }
        $back .= '&q=' . urlencode((string) ($_POST['q'] ?? '')) . '&v=' . urlencode((string) ($_POST['v'] ?? '')) . '&page=' . (int) ($_POST['page'] ?? 1);
    }
    header('Location: ' . $back);
    exit;
}

$settings = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];

$page_title = 'Display control';
require 'includes/header.php';

function disp_select(string $name, string $current, array $options, string $extra = ''): string {
    $html = '<select name="' . admin_h($name) . '" ' . $extra . '>';
    foreach ($options as $k => $label) {
        $html .= '<option value="' . admin_h($k) . '"' . ((string) $k === $current ? ' selected' : '') . '>' . admin_h($label) . '</option>';
    }
    return $html . '</select>';
}
?>
<?php if (isset($_GET['saved'])): ?><div class="notice" style="margin-bottom:14px">Saved. The website and the app use this right away.</div><?php endif; ?>

<div class="card" style="margin-bottom:16px">
<h2 style="margin-bottom:6px">Display control</h2>
<p class="muted" style="margin:0 0 12px">Choose where each thing is shown: the website, the app, or both. Anything you do not change stays on both.</p>
<div>
<?php foreach ($tabs as $k => $label): ?>
<a class="btn <?= $k === $tab ? '' : 'gray' ?>" href="display.php?tab=<?= $k ?>"><?= admin_h($label) ?></a>
<?php endforeach; ?>
</div>
</div>

<?php if ($tab === 'sections'): ?>
<form method="post" class="card">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<div class="table-wrap"><table class="table">
<tr><th>Section</th><th>Show it on</th></tr>
<?php foreach ($sections as $sec):
    $shown = trim((string) ($settings[$sec['show']] ?? $sec['default'])) !== '0';
    if ($sec['both']) {
        $cur = $shown ? display_clean($settings[$sec['disp']] ?? 'both') : 'off';
        $opts = display_values() + ['off' => 'Hidden everywhere'];
    } else {
        $cur = $shown ? 'on' : 'off';
        $opts = ['on' => 'Shown in the app', 'off' => 'Hidden'];
    } ?>
<tr><td><b><?= admin_h($sec['label']) ?></b><br><span class="muted"><?= admin_h($sec['note']) ?></span></td>
<td><?= disp_select('sec[' . $sec['show'] . ']', $cur, $opts) ?></td></tr>
<?php endforeach; ?>
</table></div>
<p style="margin-top:14px"><button class="btn">Save</button></p>
</form>

<?php elseif ($tab === 'banners'): ?>
<?php $rows = $pdo->query('SELECT id, title, subtitle, is_active, COALESCE(show_on, \'both\') AS show_on FROM banners ORDER BY sort_order, id')->fetchAll(); ?>
<form method="post" class="card">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<div class="table-wrap"><table class="table">
<tr><th>Banner</th><th>Status</th><th>Show it on</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><b><?= admin_h($r['title']) ?></b><br><span class="muted"><?= admin_h($r['subtitle']) ?></span></td>
<td><?= $r['is_active'] ? 'Showing' : 'Hidden' ?></td>
<td><?= disp_select('show[' . (int) $r['id'] . ']', display_clean($r['show_on']), display_values()) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3" class="muted">No banners yet. Add them under Banners.</td></tr><?php endif; ?>
</table></div>
<?php if ($rows): ?><p style="margin-top:14px"><button class="btn">Save</button></p><?php endif; ?>
</form>

<?php elseif ($tab === 'categories'): ?>
<?php require_once __DIR__ . '/../includes/category_images.php'; cat_img_ensure($pdo);
$rows = $pdo->query('SELECT id, name, emoji, image, is_hidden, COALESCE(show_on, \'both\') AS show_on FROM categories ORDER BY sort_order, name')->fetchAll(); ?>
<form method="post" class="card">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<div class="table-wrap"><table class="table">
<tr><th>Category</th><th>Status</th><th>Show it on</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?php if (!empty($r['image'])): ?><img src="../<?= admin_h($r['image']) ?>" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:9px;vertical-align:middle"><?php else: ?><?= admin_h($r['emoji']) ?><?php endif; ?> <b><?= admin_h($r['name']) ?></b></td>
<td><?= !empty($r['is_hidden']) ? 'Hidden' : 'Visible' ?></td>
<td><?= disp_select('show[' . (int) $r['id'] . ']', display_clean($r['show_on']), display_values()) ?></td></tr>
<?php endforeach; ?>
</table></div>
<p style="margin-top:14px"><button class="btn">Save</button></p>
</form>

<?php else:
    $q = trim((string) ($_GET['q'] ?? ''));
    $v = in_array($_GET['v'] ?? '', ['both', 'web', 'app'], true) ? $_GET['v'] : '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = 40;
    $where = "p.status = 'approved' AND p.id IN (SELECT MIN(g.id) FROM products g WHERE g.status = 'approved' GROUP BY g.category_id, COALESCE(g.seller_id, 0), LOWER(TRIM(g.name)))";
    $args = [];
    if ($q !== '') { $where .= ' AND p.name LIKE ?'; $args[] = '%' . $q . '%'; }
    if ($v !== '') { $where .= " AND COALESCE(p.show_on, 'both') = ?"; $args[] = $v; }
    $count = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $where");
    $count->execute($args);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $per));
    $page = min($page, $pages);
    $off = ($page - 1) * $per;
    $st = $pdo->prepare("SELECT p.id, p.name, p.price, p.seller_name, COALESCE(p.show_on, 'both') AS show_on,
        (SELECT COUNT(DISTINCT COALESCE(g2.show_on, 'both')) FROM products g2 WHERE g2.category_id = p.category_id AND COALESCE(g2.seller_id, 0) = COALESCE(p.seller_id, 0) AND LOWER(TRIM(g2.name)) = LOWER(TRIM(p.name))) AS kinds
        FROM products p WHERE $where ORDER BY p.id DESC LIMIT $per OFFSET $off");
    $st->execute($args);
    $rows = $st->fetchAll();
?>
<form method="get" class="card" style="margin-bottom:16px">
<input type="hidden" name="tab" value="products">
<div class="row">
<div class="field"><label>Search products</label><input name="q" value="<?= admin_h($q) ?>" placeholder="Product name"></div>
<div class="field"><label>Only show products that are</label><?= disp_select('v', $v, ['' => 'Any'] + display_values()) ?></div>
</div>
<button class="btn">Filter</button> <span class="muted"><?= $total ?> product<?= $total === 1 ? '' : 's' ?></span>
</form>

<form method="post" class="card" id="prodForm">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<input type="hidden" name="q" value="<?= admin_h($q) ?>"><input type="hidden" name="v" value="<?= admin_h($v) ?>"><input type="hidden" name="page" value="<?= $page ?>">
<p class="muted" style="margin-top:0">A product with several colors or sizes is changed all together. Hidden products do not appear in lists or search, and their page will not open.</p>
<div style="margin-bottom:12px"><b>Set every product on this page to:</b> <?= disp_select('bulk', '', ['' => 'Choose...'] + display_values(), 'id="bulk"') ?></div>
<div class="table-wrap"><table class="table">
<tr><th>Product</th><th>Seller</th><th>Price</th><th>Show it on</th></tr>
<?php foreach ($rows as $r):
    $mixed = (int) $r['kinds'] > 1;
    $opts = $mixed ? ['' => 'Mixed (leave as is)'] + display_values() : display_values(); ?>
<tr><td><b><?= admin_h($r['name']) ?></b></td><td><?= admin_h($r['seller_name']) ?></td><td><?= admin_h(number_format((float) $r['price'], 0)) ?></td>
<td><input type="hidden" name="prev[<?= (int) $r['id'] ?>]" value="<?= $mixed ? '' : admin_h($r['show_on']) ?>">
<?= disp_select('show[' . (int) $r['id'] . ']', $mixed ? '' : display_clean($r['show_on']), $opts) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4" class="muted">No products found.</td></tr><?php endif; ?>
</table></div>
<p style="margin-top:14px"><button class="btn">Save changes</button></p>
<?php if ($pages > 1): ?>
<p>
<?php if ($page > 1): ?><a class="btn gray" href="display.php?tab=products&q=<?= urlencode($q) ?>&v=<?= urlencode($v) ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
<span class="muted">Page <?= $page ?> of <?= $pages ?></span>
<?php if ($page < $pages): ?><a class="btn gray" href="display.php?tab=products&q=<?= urlencode($q) ?>&v=<?= urlencode($v) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
</p>
<?php endif; ?>
</form>
<script>
document.getElementById('bulk').addEventListener('change', function () {
  var val = this.value;
  if (!val) return;
  Array.prototype.forEach.call(document.querySelectorAll('select[name^="show["]'), function (s) { s.value = val; });
});
</script>
<?php endif; ?>

<?php require 'includes/footer.php';
