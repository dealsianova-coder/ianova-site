<?php
// Database backup: writes ~/ianova-backups/ianova-YYYYmmdd-HHMM.sql.gz and keeps the newest 14.
// Usage:  sh ~/ianova-php/tools/run.sh backup        (add "uploads" to also archive the uploads folder)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$dir = rtrim((string) (getenv('IANOVA_BACKUP_DIR') ?: (getenv('HOME') . '/ianova-backups')), '/');
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { fwrite(STDERR, "Cannot create $dir\n"); exit(1); }
$file = $dir . '/ianova-' . date('Ymd-Hi') . '.sql.gz';
$gz = gzopen($file, 'w9');
if (!$gz) { fwrite(STDERR, "Cannot write $file\n"); exit(1); }
gzwrite($gz, "-- IANOVA backup " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
$tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
$rowsTotal = 0;
foreach ($tables as $t) {
    $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
    gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
    $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`', PDO::FETCH_NUM);
    $batch = [];
    foreach ($st as $row) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
        $batch[] = '(' . implode(',', $vals) . ')'; $rowsTotal++;
        if (count($batch) >= 200) { gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n"); $batch = []; }
    }
    if ($batch) gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
    gzwrite($gz, "\n");
}
gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
gzclose($gz);
chmod($file, 0600);
$all = glob($dir . '/ianova-*.sql.gz'); sort($all);
foreach (array_slice($all, 0, max(0, count($all) - 14)) as $old) @unlink($old);
echo "Backup saved: $file (" . count($tables) . " tables, $rowsTotal rows, " . round(filesize($file) / 1024) . " KB)\n";
if (in_array('uploads', $argv ?? [], true) && is_dir(__DIR__ . '/../uploads')) {
    $tar = $dir . '/uploads-' . date('Ymd') . '.tar.gz';
    exec('tar -czf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg(dirname(__DIR__)) . ' uploads 2>&1', $o, $rc);
    echo $rc === 0 ? "Uploads saved: $tar\n" : "Could not archive uploads (tar missing?)\n";
}
