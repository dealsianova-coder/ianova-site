<?php
/**
 * Where things are shown. Every banner, category and product has a "show on" value:
 *   both = website and app, web = website only, app = app only.
 * The admin, the seller area and the seller/admin APIs are never filtered.
 */

function display_platform(): string {
    static $p = null;
    if ($p === null) {
        if (defined('IANOVA_PLATFORM')) {
            $p = (string) IANOVA_PLATFORM;
        } else {
            $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            if (strpos($path, '/api/') !== false) $p = 'app';
            elseif (strpos($path, '/admin/') !== false || strpos($path, '/seller/') !== false) $p = 'admin';
            else $p = 'web';
        }
    }
    return $p;
}

function display_values(): array {
    return ['both' => 'Website and app', 'web' => 'Website only', 'app' => 'App only'];
}

function display_label($value): string {
    $all = display_values();
    return $all[(string) $value] ?? $all['both'];
}

function display_clean($value, string $default = 'both'): string {
    return in_array($value, ['both', 'web', 'app'], true) ? $value : $default;
}

/** SQL that is true for rows shown on the platform that is asking. $alias is the table alias, if any. */
function display_sql(string $alias = ''): string {
    $p = display_platform();
    if ($p !== 'web' && $p !== 'app') return '1=1';
    $a = $alias !== '' ? $alias . '.' : '';
    return "COALESCE({$a}show_on, 'both') IN ('both', '$p')";
}

/** For on/off switches saved in the settings table (values both / web / app). */
function display_setting_allows($value): bool {
    $p = display_platform();
    $v = trim((string) $value);
    if ($v === '' || $v === 'both' || ($p !== 'web' && $p !== 'app')) return true;
    return $v === $p;
}

/** Adds the show_on column to products, categories and banners once (a flag file avoids repeating the check). */
function display_ensure(PDO $pdo): void {
    $flag = sys_get_temp_dir() . '/ianova_display_v1.flag';
    if (is_file($flag)) return;
    $ok = true;
    foreach (['products', 'categories', 'banners'] as $table) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'show_on'")->fetch();
            if (!$has) $pdo->exec("ALTER TABLE `$table` ADD COLUMN show_on VARCHAR(10) NOT NULL DEFAULT 'both'");
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    if ($ok) @file_put_contents($flag, '1');
}
