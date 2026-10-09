<?php
require __DIR__ . '/config.php';

// Only a signed-in administrator may use the importer.
if (empty($_SESSION['admin_id'])) {
    header('Location: admin/login.php');
    exit;
}

$message = '';
$errors = [];
$results = [];

$uploadDir = __DIR__ . '/assets/img';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

function clean_filename($name) {
    return basename(str_replace('\\', '/', (string)$name));
}

function image_url_for_file($filename) {
    return 'assets/img/' . rawurlencode($filename);
}

function copy_uploaded_image($tmp, $originalName, $uploadDir) {
    $safe = clean_filename($originalName);
    if ($safe === '' || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $safe)) return null;
    $target = $uploadDir . '/' . $safe;
    if (!move_uploaded_file($tmp, $target)) return null;
    return $safe;
}

function find_local_image($filename, $uploadDir) {
    $filename = clean_filename($filename);
    if ($filename === '') return null;
    $path = $uploadDir . '/' . $filename;
    return is_file($path) ? $filename : null;
}

function download_image($url, $uploadDir, $suggestedName = '') {
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) return null;
    $path = parse_url($url, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path ?: '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) $ext = 'jpg';
    $base = clean_filename($suggestedName);
    if ($base === '' || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $base)) {
        $base = 'product-' . bin2hex(random_bytes(5)) . '.' . $ext;
    }
    $target = $uploadDir . '/' . $base;

    $data = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'IANOVA Product Importer/1.0',
        ]);
        $data = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $data = @file_get_contents($url);
    }
    if ($data === false || strlen($data) < 20) return null;
    if (@file_put_contents($target, $data) === false) return null;
    return $base;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['csv']['tmp_name']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file.';
    } else {
        // Optional image upload: select the product photos at the same time as the CSV.
        $uploadedImages = [];
        if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
            foreach ($_FILES['images']['name'] as $i => $name) {
                if (($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $saved = copy_uploaded_image($_FILES['images']['tmp_name'][$i], $name, $uploadDir);
                if ($saved) $uploadedImages[clean_filename($name)] = $saved;
            }
        }

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $errors[] = 'Could not read the CSV file.';
        } else {
            $header = fgetcsv($fh);
            if (!$header) {
                $errors[] = 'CSV is empty.';
            } else {
                $header = array_map(function($v) { return strtolower(trim((string)$v)); }, $header);
                $required = ['name','slug','price'];
                foreach ($required as $field) {
                    if (!in_array($field, $header, true)) $errors[] = "CSV is missing required column: {$field}";
                }

                if (!$errors) {
                    $col = array_flip($header);
                    $fashionStmt = $pdo->query("SELECT id FROM categories WHERE slug='fashion' LIMIT 1");
                    $fashionId = $fashionStmt->fetchColumn();
                    $categoryCache = [];
                    $pdo->beginTransaction();
                    try {
                        $insert = $pdo->prepare("INSERT INTO products
                            (category_id,name,slug,description,price,original_price,emoji,image_url,bg_color,rating,review_count,stock,is_flash_deal,seller_name)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $update = $pdo->prepare("UPDATE products SET category_id=?, name=?, description=?, price=?, original_price=?, emoji=?, image_url=?, bg_color=?, rating=?, review_count=?, stock=?, is_flash_deal=?, seller_name=? WHERE slug=?");

                        $line = 1;
                        while (($row = fgetcsv($fh)) !== false) {
                            $line++;
                            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                            $get = function($key, $default = '') use (&$row, &$col) {
                                return isset($col[$key]) && isset($row[$col[$key]]) ? trim((string)$row[$col[$key]]) : $default;
                            };
                            $name = $get('name');
                            $slug = $get('slug');
                            $price = (float)$get('price', 0);
                            if ($name === '' || $slug === '' || $price <= 0) {
                                $results[] = "Row {$line}: skipped — name, slug and price > 0 are required.";
                                continue;
                            }

                            $description = $get('description');
                            $original = $get('original_price');
                            $original = $original === '' ? null : (float)$original;
                            $emoji = $get('emoji', '📦');
                            $bg = $get('bg_color', '#EFF3EC');
                            $rating = (float)$get('rating', 4.5);
                            $reviews = (int)$get('review_count', 0);
                            $stock = (int)$get('stock', 100);
                            $flash = (int)$get('is_flash_deal', 0);
                            $seller = $get('seller_name', 'IANOVA Direct');

                            // Category can be supplied as category_slug/category/name; otherwise Fashion.
                            $categorySlug = $get('category_slug');
                            $categoryName = $get('category');
                            $categoryId = $fashionId;
                            if ($categorySlug !== '') {
                                if (!isset($categoryCache[$categorySlug])) {
                                    $s = $pdo->prepare('SELECT id FROM categories WHERE slug=? LIMIT 1');
                                    $s->execute([$categorySlug]);
                                    $categoryCache[$categorySlug] = $s->fetchColumn();
                                }
                                if ($categoryCache[$categorySlug]) $categoryId = $categoryCache[$categorySlug];
                            } elseif ($categoryName !== '') {
                                if (!isset($categoryCache[$categoryName])) {
                                    $s = $pdo->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
                                    $s->execute([$categoryName]);
                                    $categoryCache[$categoryName] = $s->fetchColumn();
                                }
                                if ($categoryCache[$categoryName]) $categoryId = $categoryCache[$categoryName];
                            }
                            if (!$categoryId) throw new RuntimeException("Row {$line}: no valid category found.");

                            // Automatic image handling:
                            // 1) image_filename matches a photo uploaded with this import;
                            // 2) then an existing assets/img file;
                            // 3) then image_url is downloaded locally;
                            // 4) finally keep an existing image when updating an existing product.
                            $imageFilename = $get('image_filename');
                            $imageUrl = '';
                            $localImage = null;
                            if ($imageFilename !== '') {
                                $safeImage = clean_filename($imageFilename);
                                if (isset($uploadedImages[$safeImage])) $localImage = $uploadedImages[$safeImage];
                                if (!$localImage) $localImage = find_local_image($safeImage, $uploadDir);
                            }
                            if ($localImage) {
                                $imageUrl = image_url_for_file($localImage);
                            } else {
                                $sourceUrl = $get('image_url');
                                if ($sourceUrl !== '' && filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
                                    $downloaded = download_image($sourceUrl, $uploadDir, $imageFilename);
                                    if ($downloaded) $imageUrl = image_url_for_file($downloaded);
                                } elseif ($sourceUrl !== '' && is_file(__DIR__ . '/' . ltrim($sourceUrl, '/'))) {
                                    $imageUrl = ltrim($sourceUrl, '/');
                                }
                            }

                            // Upsert by slug: existing products are updated, new products are inserted.
                            $existing = $pdo->prepare('SELECT id, image_url FROM products WHERE slug=? LIMIT 1');
                            $existing->execute([$slug]);
                            $old = $existing->fetch();
                            if ($old) {
                                if ($imageUrl === '' && !empty($old['image_url'])) $imageUrl = $old['image_url'];
                                $update->execute([$categoryId,$name,$description,$price,$original,$emoji,$imageUrl,$bg,$rating,$reviews,$stock,$flash,$seller,$slug]);
                                $results[] = "Row {$line}: updated {$name}" . ($imageUrl ? ' with image' : '');
                            } else {
                                $insert->execute([$categoryId,$name,$slug,$description,$price,$original,$emoji,$imageUrl,$bg,$rating,$reviews,$stock,$flash,$seller]);
                                $results[] = "Row {$line}: imported {$name}" . ($imageUrl ? ' with image' : '');
                            }
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = 'Import failed: ' . $e->getMessage();
                    }
                }
            }
            fclose($fh);
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>IANOVA CSV Product Import</title>
<style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 20px}input,button{margin:8px 0;padding:10px}button{cursor:pointer}.ok{background:#edf8ee;padding:12px}.err{background:#fff0f0;padding:12px}.hint{background:#f5f5f5;padding:14px;line-height:1.6}code{background:#eee;padding:2px 4px}</style></head>
<body>
<h1>IANOVA Product CSV Import</h1>
<div class="hint"><strong>Automatic image handling:</strong> upload the CSV and the product photos together. If <code>image_filename</code> is present, the importer matches that filename, copies it to <code>assets/img/</code>, and saves the local <code>image_url</code>. Existing slugs are updated instead of rejected.</div>
<?php if ($errors): ?><div class="err"><strong>Errors</strong><ul><?php foreach($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($results): ?><div class="ok"><strong>Import results</strong><ul><?php foreach($results as $r): ?><li><?=h($r)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<label>CSV file<br><input type="file" name="csv" accept=".csv,text/csv" required></label><br>
<label>Product photos (select all matching images)<br><input type="file" name="images[]" accept="image/*" multiple></label><br>
<button type="submit">Import Products</button>
</form>
<p>Supported image columns: <code>image_filename</code> and <code>image_url</code>. The importer prefers an uploaded/local filename, then downloads a valid HTTP(S) image URL when possible.</p>
</body></html>
