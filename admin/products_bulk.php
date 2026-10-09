<?php
require_once 'includes/auth.php';

$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
$error = '';
$success_count = 0;
$row_errors = [];

function bulk_download_image(string $url): ?string {
    $allowed = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif'];
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) return null;
    $ctx = stream_context_create(['http'=>['timeout'=>10], 'https'=>['timeout'=>10]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) > 5*1024*1024) return null;
    $filename = 'uploads/products/' . bin2hex(random_bytes(10)) . '.' . $allowed[$ext];
    if (@file_put_contents(__DIR__.'/../'.$filename, $data) === false) return null;
    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();
    $category_id = (int)($_POST['category_id'] ?? 0);

    // Photos selected in the form are saved under uploads/products/ and indexed by original name.
    $uploaded_by_name = [];
    if (!empty($_FILES['photos']['name'][0] ?? '')) {
        $allowed_mime = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        foreach ($_FILES['photos']['name'] as $i => $origName) {
            $tmp = $_FILES['photos']['tmp_name'][$i] ?? '';
            $size = (int)($_FILES['photos']['size'][$i] ?? 0);
            if (!$tmp || $size > 5*1024*1024) continue;
            $mime = function_exists('mime_content_type') ? @mime_content_type($tmp) : ($_FILES['photos']['type'][$i] ?? '');
            if (!isset($allowed_mime[$mime])) continue;
            $saved = 'uploads/products/' . bin2hex(random_bytes(10)) . '.' . $allowed_mime[$mime];
            if (move_uploaded_file($tmp, __DIR__.'/../'.$saved)) {
                $uploaded_by_name[strtolower(trim($origName))] = $saved;
            }
        }
    }

    if ($category_id < 1) {
        $error = 'Please choose a category.';
    } elseif (empty($_FILES['csv_file']['name'])) {
        $error = 'Please choose a CSV file to upload.';
    } else {
        $handle = @fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            $error = 'Could not read the uploaded file.';
        } else {
            $header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle, 0, ',', '"', '\\') ?: []);
            $required = ['name','slug','price'];
            $missing = array_diff($required, $header);
            if ($missing) {
                $error = 'CSV is missing required column(s): '.implode(', ', $missing).'. Required: name, slug, price. Optional: description, original_price, stock, emoji, bg_color, is_flash_deal, image_url, image_filename.';
            } else {
                $col = array_flip($header);
                $line = 1;
                $insert = $pdo->prepare("INSERT INTO products
                    (category_id,name,slug,description,price,original_price,emoji,bg_color,stock,is_flash_deal,image,seller_name,rating,review_count,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,'IANOVA Direct',4.5,0,'approved')");

                while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $line++;
                    if (implode('', array_map('trim', $row)) === '') continue;
                    $name = trim($row[$col['name']] ?? '');
                    $slug = trim($row[$col['slug']] ?? '');
                    $price = (float)($row[$col['price']] ?? 0);
                    if ($name === '' || $slug === '' || $price <= 0) {
                        $row_errors[] = "Row $line: name, slug, and a price greater than 0 are required.";
                        continue;
                    }

                    $description = isset($col['description']) ? trim($row[$col['description']] ?? '') : '';
                    $original_price = isset($col['original_price']) && trim($row[$col['original_price']] ?? '') !== '' ? (float)$row[$col['original_price']] : null;
                    $stock = isset($col['stock']) && trim($row[$col['stock']] ?? '') !== '' ? (int)$row[$col['stock']] : 100;
                    $emoji = isset($col['emoji']) && trim($row[$col['emoji']] ?? '') !== '' ? trim($row[$col['emoji']]) : '📦';
                    $bg_color = isset($col['bg_color']) && trim($row[$col['bg_color']] ?? '') !== '' ? trim($row[$col['bg_color']]) : '#EFF3EC';
                    $is_flash = isset($col['is_flash_deal']) && in_array(strtolower(trim($row[$col['is_flash_deal']] ?? '')), ['1','yes','true'], true) ? 1 : 0;

                    // Image resolution: selected photo -> existing local file -> URL download.
                    $image = null;
                    $image_filename = isset($col['image_filename']) ? trim($row[$col['image_filename']] ?? '') : '';
                    if ($image_filename !== '') {
                        $wanted = strtolower($image_filename);
                        $image = $uploaded_by_name[$wanted] ?? null;
                        if (!$image) {
                            $safe = basename($image_filename);
                            $local = __DIR__.'/../uploads/products/'.$safe;
                            if (is_file($local)) $image = 'uploads/products/'.$safe;
                        }
                    }
                    if (!$image && isset($col['image_url']) && trim($row[$col['image_url']] ?? '') !== '') {
                        $image = bulk_download_image(trim($row[$col['image_url']]));
                    }

                    try {
                        // IMPORTANT: existing slugs are updated, not rejected.
                        $q = $pdo->prepare('SELECT id, image FROM products WHERE slug = ? LIMIT 1');
                        $q->execute([$slug]);
                        $existing = $q->fetch(PDO::FETCH_ASSOC);
                        if ($existing) {
                            $update = $pdo->prepare("UPDATE products SET category_id=?,name=?,description=?,price=?,original_price=?,emoji=?,bg_color=?,stock=?,is_flash_deal=?,image=COALESCE(NULLIF(?,''),image),status='approved' WHERE id=?");
                            $update->execute([$category_id,$name,$description,$price,$original_price,$emoji,$bg_color,$stock,$is_flash,$image ?? '',(int)$existing['id']]);
                        } else {
                            $insert->execute([$category_id,$name,$slug,$description,$price,$original_price,$emoji,$bg_color,$stock,$is_flash,$image]);
                        }
                        $success_count++;
                    } catch (Throwable $e) {
                        $row_errors[] = "Row $line: could not save '$name' — ".$e->getMessage();
                    }
                }
            }
            fclose($handle);
        }
    }
}

$page_title = 'Bulk upload products';
require 'includes/header.php';
?>
<div class="card form-card">
<h2>Bulk upload products from CSV</h2>
<?php if ($error): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<?php if ($success_count): ?><div class="notice"><?= (int)$success_count ?> product(s) imported/updated successfully.</div><?php endif; ?>
<?php if ($row_errors): ?><div class="notice error"><?= count($row_errors) ?> row(s) had notes:<ul style="margin:8px 0 0 18px;"><?php foreach ($row_errors as $re): ?><li><?= admin_h($re) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">
<div class="field"><label>Category</label><select name="category_id" required><option value="">— Choose category —</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= admin_h($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>CSV file</label><input type="file" name="csv_file" accept=".csv" required></div>
<div class="field"><label>Photos (optional)</label><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple></div>
<button class="btn">Upload products</button> <a class="btn gray" href="products.php">Back to products</a>
</form>
<div style="margin-top:28px;padding-top:20px;border-top:1px solid #e8eeea;"><h3 style="font-size:15px;">Image handling</h3><p class="muted">If <code>image_filename</code> is present, the importer first checks selected photos, then automatically checks <code>uploads/products/</code>. Existing slugs are updated instead of rejected.</p></div>
</div>
<?php require 'includes/footer.php';
