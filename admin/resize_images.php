<?php
require_once 'includes/auth.php';
@set_time_limit(300);
@ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');
if (!function_exists('imagecreatefrompng')) { exit("GD is not installed on this server yet.\n"); }
$root = realpath(__DIR__ . '/../uploads');
if (!$root) { exit("No uploads folder.\n"); }
$max = 800;
$apply = (($_GET['run'] ?? '') === '1');
echo $apply ? "APPLYING changes (max width {$max}px)\n\n" : "DRY RUN - nothing changed. Add ?run=1 to apply.\n\n";
$saved = 0; $n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $path = $f->getPathname();
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
    $info = @getimagesize($path);
    if (!$info) continue;
    [$w, $h] = $info;
    $size = filesize($path);
    if ($w <= $max) continue;
    $rel = substr($path, strlen($root) + 1);
    $nh = (int) round($h * $max / $w);
    echo sprintf("%s  %dx%d %dKB -> %dx%d\n", $rel, $w, $h, round($size / 1024), $max, $nh);
    if (!$apply) continue;
    $src = $ext === 'png' ? @imagecreatefrompng($path) : ($ext === 'webp' ? @imagecreatefromwebp($path) : @imagecreatefromjpeg($path));
    if (!$src) { echo "  skipped (cannot read)\n"; continue; }
    if ($ext !== 'png' && function_exists('exif_read_data')) {
        $ex = @exif_read_data($path);
        $o = (int) ($ex['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { $src = $r; $w = imagesx($src); $h = imagesy($src); $nh = (int) round($h * $max / $w); } }
    }
    $dst = imagecreatetruecolor($max, $nh);
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $max, $nh, $w, $h);
    $tmp = $path . '.tmp';
    $ok = $ext === 'png' ? imagepng($dst, $tmp, 9) : ($ext === 'webp' ? imagewebp($dst, $tmp, 82) : imagejpeg($dst, $tmp, 82));
    imagedestroy($src); imagedestroy($dst);
    if ($ok && is_file($tmp) && filesize($tmp) < $size) {
        $saved += $size - filesize($tmp); $n++;
        rename($tmp, $path);
        echo sprintf("  done, now %dKB\n", round(filesize($path) / 1024));
    } else {
        @unlink($tmp);
        echo "  kept original (new file was not smaller)\n";
    }
}
echo $apply ? sprintf("\nResized %d files, saved %d KB\n", $n, round($saved / 1024)) : "\nEnd of list.\n";
