<?php
function ianova_unique_slug(PDO $pdo, string $slug, int $id, string $color, string $size): string {
    $clean = function (string $v): string { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($v)), '-'); };
    $base = $slug; $n = 1;
    while (true) {
        $q = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND id <> ?');
        $q->execute([$slug, $id]);
        if (!$q->fetch()) return $slug;
        $extra = trim($clean($color) . '-' . $clean($size), '-');
        $slug = $base . (($n === 1 && $extra !== '') ? '-' . $extra : '-' . ($extra !== '' ? $extra . '-' : '') . $n);
        $n++;
        if ($n > 50) return $base . '-' . time();
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/seller_messaging.php';
ensure_seller_messaging($pdo);

if (empty($_SESSION['seller_id'])) {
    header('Location: login.php');
    exit;
}

$seller_id = (int) $_SESSION['seller_id'];
$stmt = $pdo->prepare('SELECT * FROM sellers WHERE id = ?');
$stmt->execute([$seller_id]);
$seller = $stmt->fetch();

$id = (int) ($_GET['id'] ?? 0);
$product = null;
if ($id) {
    $s = $pdo->prepare('SELECT * FROM products WHERE id = ? AND seller_id = ?');
    $s->execute([$id, $seller_id]);
    $product = $s->fetch();
    if (!$product) {
        header('Location: index.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/seller_ops.php';
if (($seller['status'] ?? '') === 'suspended' || ($product && ops_product_locked($product))) {
    $_SESSION['ops_flash'] = 'This is restricted by the admin. You cannot change it until the admin approves it. Use "Request review" on your dashboard.';
    header('Location: index.php');
    exit;
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $cat = (int) $_POST['category_id'];
    $price = (float) $_POST['price'];
    $orig = $_POST['original_price'] === '' ? null : (float) $_POST['original_price'];
    $stock = max(0, (int) $_POST['stock']);
    $desc = trim($_POST['description'] ?? '');
    $sub = substr(trim($_POST['subcategory'] ?? ''), 0, 60);
    $color = substr(trim($_POST['color'] ?? ''), 0, 40);
    $size = substr(trim($_POST['size'] ?? ''), 0, 30);
    $emoji = trim($_POST['emoji'] ?? '📦');
    $bg = trim($_POST['bg_color'] ?? '#EFF3EC');
    $flash = isset($_POST['is_flash_deal']) ? 1 : 0;
    $image = $product['image'] ?? null;

    if (!empty($_FILES['image']['name'])) {
        $f = $_FILES['image'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : $f['type'];
        if (isset($allowed[$mime]) && $f['size'] <= 5 * 1024 * 1024) {
            $filename = 'uploads/products/' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
            if (move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $filename)) $image = $filename;
        } else {
            $error = 'Image must be JPG, PNG, WEBP or GIF and max 5MB.';
        }
    }

    if (!$error && ($name === '' || $slug === '' || $cat < 1)) {
        $error = 'Name, slug and category are required.';
    }

    if (!$error) { $slug = ianova_unique_slug($pdo, $slug, (int) $id, $color, $size); }

    // Trusted sellers (set by the admin) publish straight away; others wait for review.
    $status = !empty($seller['auto_approve']) ? 'approved' : 'pending';

    if (!$error) {
        try {
            if ($id) {
                $s = $pdo->prepare("UPDATE products SET category_id=?,name=?,slug=?,description=?,price=?,original_price=?,emoji=?,bg_color=?,stock=?,is_flash_deal=?,image=?,subcategory=?,color=?,size=?,status=? WHERE id=? AND seller_id=?");
                $s->execute([$cat, $name, $slug, $desc, $price, $orig, $emoji, $bg, $stock, $flash, $image, $sub, $color, $size, $status, $id, $seller_id]);
            } else {
                $s = $pdo->prepare("INSERT INTO products(category_id,name,slug,description,price,original_price,emoji,bg_color,stock,is_flash_deal,image,subcategory,color,size,seller_id,seller_name,rating,review_count,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,4.5,0,?)");
                $s->execute([$cat, $name, $slug, $desc, $price, $orig, $emoji, $bg, $stock, $flash, $image, $sub, $color, $size, $seller_id, $seller['business_name'], $status]);
            }
            require_once __DIR__ . '/../includes/seller_extras.php';
            $pid = $id ? (int) $id : (int) $pdo->lastInsertId();
            $xerr = seller_extras_save($pdo, $pid, $seller, $status);
            if ($xerr !== '') { $_SESSION['ops_flash'] = $xerr; }
            header('Location: index.php?submitted=1');
            exit;
        } catch (Throwable $e) {
            $error = 'Could not save product. The slug may already exist.';
        }
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Product Editor — IANOVA</title><style>
*{box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;background:#FAFAFB;margin:0;color:#0F0F12}
.top{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;background:#fff;border-bottom:1px solid #E9EBEF}
.wrap{max-width:700px;margin:24px auto;padding:0 16px}
.card{background:#fff;border:1px solid #E9EBEF;border-radius:14px;padding:22px}
.field{margin-bottom:15px}
.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
.field input,.field textarea,.field select{width:100%;padding:11px;border:1px solid #E9EBEF;border-radius:16px;font:inherit}
.row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.btn{display:inline-block;border:0;border-radius:16px;padding:10px 14px;background:#0F0F12;color:#fff;cursor:pointer;font-weight:700;text-decoration:none}
.btn.amber{background:#0F0F12;color:#0F0F12}
.err{background:#FFF1F4;color:#E5173F;padding:12px 14px;border-radius:16px;margin-bottom:16px}
@media(max-width:600px){.row{grid-template-columns:1fr}}
</style></head><body>
<div class="top"><h1 style="margin:0;font-size:20px;"><?= $product ? 'Edit' : 'Add' ?> product</h1><a class="btn" href="index.php">Back</a></div>
<div class="wrap"><div class="card">
<?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="id" value="<?= intval($product['id'] ?? 0) ?>">
<div class="row">
<div class="field"><label>Name</label><input name="name" required value="<?= h($product['name'] ?? '') ?>"></div>
<div class="field"><label>Slug</label><input name="slug" required value="<?= h($product['slug'] ?? '') ?>"></div>
</div>
<div class="field"><label>Category</label><select name="category_id" required>
<?php foreach ($categories as $c): ?>
<option value="<?= intval($c['id']) ?>" <?= ((int)($product['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
<?php endforeach; ?>
</select></div>
<?php $subs=[];try{$subs=$pdo->query("SELECT DISTINCT subcategory FROM products WHERE subcategory<>'' ORDER BY subcategory")->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable $e){} ?>
<div class="field"><label>Subcategory (e.g. Men, Women)</label><input name="subcategory" list="subcats" maxlength="60" value="<?= h($product['subcategory'] ?? '') ?>"><datalist id="subcats"><?php foreach ($subs as $sc): ?><option value="<?= h($sc) ?>"><?php endforeach; ?></datalist></div><div class="row"><div class="field"><label>Color (optional)</label><input name="color" list="colors" maxlength="40" value="<?=h($product['color']??'')?>"><datalist id="colors"><option value="Black"><option value="White"><option value="Red"><option value="Blue"><option value="Green"><option value="Pink"><option value="Purple"><option value="Yellow"><option value="Orange"><option value="Grey"><option value="Brown"><option value="Beige"><option value="Navy"></datalist></div><div class="field"><label>Size (optional)</label><input name="size" maxlength="30" placeholder="e.g. 42, M, 500ml" value="<?=h($product['size']??'')?>"></div></div><p class="muted" style="margin:-6px 0 14px">Tip: add the same product name again with a different color or size, and customers can choose between them.</p>
<div class="row">
<div class="field"><label>Price</label><input type="number" step="0.01" name="price" required value="<?= h($product['price'] ?? '0') ?>"></div>
<div class="field"><label>Original price</label><input type="number" step="0.01" name="original_price" value="<?= h($product['original_price'] ?? '') ?>"></div>
</div>
<div class="row">
<div class="field"><label>Stock</label><input type="number" name="stock" value="<?= intval($product['stock'] ?? 0) ?>"></div>
<div class="field"><label>Emoji fallback</label><input name="emoji" value="<?= h($product['emoji'] ?? '📦') ?>"></div>
</div>
<div class="field"><label>Background color</label><input name="bg_color" value="<?= h($product['bg_color'] ?? '#EFF3EC') ?>"></div>
<div class="field"><label>Description</label><textarea name="description" style="min-height:100px"><?= h($product['description'] ?? '') ?></textarea></div>
<div class="field"><label>Product image (max 5MB)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
<?php if (!empty($product['image'])): ?><p><img src="../<?= h($product['image']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:16px;margin-top:8px;"></p><?php endif; ?>
</div>
<div class="field"><label>More photos - front, back, sides (pick several)</label><input type="file" name="gallery[]" multiple accept="image/jpeg,image/png,image/webp,image/gif">
<?php require_once __DIR__ . '/../includes/gallery.php'; gallery_ensure($pdo); $gx = !empty($product['id']) ? gallery_rows($pdo, (int) $product['id']) : []; if ($gx): ?>
<p style="font-size:12px;color:#777">Tick a photo to remove it when you save.</p><div style="display:flex;gap:10px;flex-wrap:wrap"><?php foreach ($gx as $g): ?><label style="text-align:center;font-size:12px"><img src="../<?= h($g['image']) ?>" style="width:64px;height:64px;object-fit:cover;border-radius:12px"><br><input type="checkbox" name="del_img[]" value="<?= intval($g['id']) ?>" style="width:auto"> remove</label><?php endforeach; ?></div><?php endif; ?></div>
<div class="field"><label>More colors of this product (customers swipe between them)</label><div id="opts"></div><button type="button" class="btn" id="addopt">+ Add another color</button><p style="font-size:12px;color:#777">Each color gets its own photo and stock. Leave price empty to use the main price. The Color field above is the first color.</p></div>
<div class="field"><label><input type="checkbox" name="is_flash_deal" style="width:auto" <?= !empty($product['is_flash_deal']) ? 'checked' : '' ?>> Show as flash deal</label></div>
<button class="btn amber" type="submit">Save product</button>
</form>
</div></div>
<script>(function(){var box=document.getElementById('opts'),btn=document.getElementById('addopt'),n=0;
function add(){var k=n++,d=document.createElement('div');d.style.cssText='border:1px solid #E9EBEF;border-radius:14px;padding:12px;margin-bottom:10px';
d.innerHTML='<input type="hidden" name="opt_k[]" value="'+k+'"><div class="row"><div class="field"><label>Color</label><input name="opt_color[]" list="colors" maxlength="40"></div><div class="field"><label>Stock</label><input type="number" min="0" name="opt_stock[]" value="0"></div></div><div class="row"><div class="field"><label>Price (optional)</label><input type="number" step="0.01" name="opt_price[]"></div><div class="field"><label>Main photo</label><input type="file" name="opt_image[]" accept="image/jpeg,image/png,image/webp,image/gif"></div></div><div class="field"><label>More photos of this color (optional)</label><input type="file" name="opt_more['+k+'][]" multiple accept="image/jpeg,image/png,image/webp,image/gif"></div><button type="button" class="btn">Remove</button>';
d.querySelector('button').onclick=function(){d.remove();};box.appendChild(d);}
btn.onclick=add;})();</script>
</body></html>
