<?php
/**
 * Address suggestions for Kenya. Free, no key.
 *   api/places.php?q=text&mode=local  -> instant (built-in list of Kenyan towns/estates)
 *   api/places.php?q=text             -> built-in list + OpenStreetMap (Photon)
 * Response: {success, places:[{name, detail, label, lat, lon}]}
 * Map data (c) OpenStreetMap contributors.
 */
require __DIR__ . '/_bootstrap.php';

$q = trim((string) preg_replace('/\s+/', ' ', (string) ($_GET['q'] ?? '')));
if (mb_strlen($q) < 3) out(['success' => true, 'places' => []]);
$q = mb_substr($q, 0, 100);
$mode = ($_GET['mode'] ?? '') === 'local' ? 'local' : 'full';

function norm(string $s): string {
    $s = mb_strtolower($s);
    $s = str_replace(["'", '’'], '', $s);
    return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $s));
}

/** Built-in places: every typed word must start a word of "name detail". */
function local_matches(string $q): array {
    static $list = null;
    if ($list === null) {
        $file = __DIR__ . '/ke_places.php';
        $list = is_file($file) ? (require $file) : [];
    }
    $nq = norm($q);
    if ($nq === '') return [];
    $tokens = explode(' ', $nq);
    $scored = [];
    foreach ($list as $row) {
        [$name, $detail] = $row;
        $nn = norm($name);
        $words = explode(' ', $nn . ' ' . norm($detail));
        $ok = true;
        foreach ($tokens as $t) {
            $hit = false;
            foreach ($words as $w) { if ($w !== '' && strpos($w, $t) === 0) { $hit = true; break; } }
            if (!$hit) { $ok = false; break; }
        }
        if (!$ok) continue;
        $score = strpos($nn, $nq) === 0 ? 0 : 1;
        $scored[] = [$score, $name, $detail];
    }
    usort($scored, function ($a, $b) { return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]); });
    $out = [];
    foreach (array_slice($scored, 0, 6) as [, $name, $detail]) {
        $out[] = ['name' => $name, 'detail' => $detail, 'label' => $name . ', ' . $detail . ', Kenya', 'lat' => null, 'lon' => null];
    }
    return $out;
}

$local = local_matches($q);
if ($mode === 'local') out(['success' => true, 'places' => $local]);

// ---- OpenStreetMap (Photon), cached for a day and rate limited ----
$dir = sys_get_temp_dir() . '/ianova_places';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$remote = null;
$cache = $dir . '/q2_' . md5(mb_strtolower($q)) . '.json';
if (is_file($cache) && filemtime($cache) > time() - 86400) {
    $saved = json_decode((string) file_get_contents($cache), true);
    if (is_array($saved)) $remote = $saved;
}

if ($remote === null) {
    $remote = [];
    $rate = $dir . '/rate_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x') . '_' . date('YmdHi');
    $count = (int) @file_get_contents($rate);
    if ($count < 40) {
        @file_put_contents($rate, (string) ($count + 1));
        if (mt_rand(1, 50) === 1) {
            foreach (glob($dir . '/rate_*') ?: [] as $old) {
                if (@filemtime($old) < time() - 300) @unlink($old);
            }
        }
        $url = 'https://photon.komoot.io/api/?' . http_build_query([
            'q' => $q,
            'limit' => 10,
            'bbox' => '33.9,-4.8,41.95,5.6',
        ]);
        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'IANOVA-store/1.0',
            ]);
            $body = curl_exec($ch);
            if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $body = false;
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: IANOVA-store/1.0\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
        }
        $data = is_string($body) ? json_decode($body, true) : null;
        foreach (($data['features'] ?? []) as $f) {
            $p = $f['properties'] ?? [];
            if (strtoupper((string) ($p['countrycode'] ?? 'KE')) !== 'KE') continue;
            $coords = $f['geometry']['coordinates'] ?? [null, null];
            $name = trim((string) ($p['name'] ?? ''));
            $streetLine = trim(trim((string) ($p['housenumber'] ?? '')) . ' ' . trim((string) ($p['street'] ?? '')));
            $title = $name !== '' ? $name : $streetLine;
            if ($title === '') continue;
            $detail = [];
            foreach ([$streetLine, $p['district'] ?? '', $p['locality'] ?? '', $p['city'] ?? '', $p['county'] ?? '', $p['state'] ?? ''] as $part) {
                $part = trim((string) $part);
                if ($part === '' || strcasecmp($part, $title) === 0) continue;
                foreach ($detail as $d) { if (strcasecmp($d, $part) === 0) continue 2; }
                $detail[] = $part;
            }
            $remote[] = [
                'name' => $title,
                'detail' => implode(', ', $detail),
                'label' => implode(', ', array_merge([$title], $detail, ['Kenya'])),
                'lat' => $coords[1] ?? null,
                'lon' => $coords[0] ?? null,
            ];
        }
        if ($remote) @file_put_contents($cache, json_encode($remote));
    }
}

// Built-in matches first (they are instant and reliable), then OpenStreetMap results.
$places = [];
$seen = [];
foreach (array_merge($local, $remote) as $pl) {
    $key = norm($pl['name']) . '|' . norm(explode(',', $pl['detail'])[0] ?? '');
    $nameKey = norm($pl['name']);
    if (isset($seen[$key]) || (isset($seen['n:' . $nameKey]) && $pl['lat'] === null)) continue;
    $seen[$key] = true;
    $seen['n:' . $nameKey] = true;
    $places[] = $pl;
    if (count($places) >= 10) break;
}
out(['success' => true, 'places' => $places]);
