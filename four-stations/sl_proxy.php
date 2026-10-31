<?php
declare(strict_types=1);

/*
 * sl_proxy.php - SL departures for stop_display.html, filtered on the server. No key needed.
 *
 * One mechanism for every stop. Say WHICH stop with one of
 *   ?site=1512            one or more SL site ids, comma-separated (max 5)
 *   ?name=Skanstull       every SL site with exactly this name (looked up in SL's site list, kept for 7 days)
 * and, if you like, narrow it down with
 *   &line=168             only this line
 *   &dest=Östbergahöjden  the destination must contain this text
 *   &direction=1|2        SL's direction code at the stop
 *   &debug=1              also shows which sites were used and what each one returned
 *
 * Examples
 *   sl_proxy.php?site=1512&direction=2
 *   sl_proxy.php?name=Skanstull&line=168&dest=Östbergahöjden
 *
 * The reply has the same shape as SL's own: {"departures":[...]}.
 * Needs PHP 8.1+ with cURL.
 */
const CACHE_SECONDS = 10;          // be polite to SL's API: identical questions within 10 s share one answer
const SITE_LIST_CACHE_DAYS = 7;
const MAX_SITES = 5;

$SL_BASE = getenv('SL_PROXY_TEST_BASE') ?: 'https://transport.integration.sl.se/v1';   // the variable is only used by tests

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array{0:int,1:string} HTTP status and body */
function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 sl-proxy/3.0',
    ]);
    $body = curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body === false ? '' : (string) $body];
}

function cache_read(string $name, int $ttl): ?string
{
    $f = sys_get_temp_dir() . '/' . $name;
    return (is_file($f) && time() - (int) filemtime($f) < $ttl) ? (string) file_get_contents($f) : null;
}
function cache_write(string $name, string $data): void
{
    @file_put_contents(sys_get_temp_dir() . '/' . $name, $data);
}

/** Reads and checks the question. Anything unexpected gets a 400, so the proxy cannot be used for other things. */
function read_query(): array
{
    $q = [];
    $site = trim((string) ($_GET['site'] ?? ''));
    $name = trim((string) ($_GET['name'] ?? ''));
    if ($site === '' && $name === '') {
        reply(['error' => 'give site=<id> or name=<stop name>'], 400);
    }
    if ($site !== '') {
        if (!preg_match('/^\d{1,6}(,\d{1,6}){0,4}$/', $site)) {
            reply(['error' => 'site must be 1 to 5 numeric ids separated by commas'], 400);
        }
        $q['site'] = array_map('intval', explode(',', $site));
    }
    $text = '/^[\p{L}\p{N} .()\/-]{1,60}$/u';
    if ($name !== '') {
        if (!preg_match($text, $name)) {
            reply(['error' => 'bad name'], 400);
        }
        $q['name'] = $name;
    }
    $line = trim((string) ($_GET['line'] ?? ''));
    if ($line !== '') {
        if (!preg_match('/^[\p{L}\p{N}]{1,8}$/u', $line)) {
            reply(['error' => 'bad line'], 400);
        }
        $q['line'] = $line;
    }
    $dest = trim((string) ($_GET['dest'] ?? ''));
    if ($dest !== '') {
        if (!preg_match($text, $dest)) {
            reply(['error' => 'bad dest'], 400);
        }
        $q['dest'] = $dest;
    }
    $dir = trim((string) ($_GET['direction'] ?? ''));
    if ($dir !== '') {
        if (!in_array($dir, ['1', '2'], true)) {
            reply(['error' => 'direction must be 1 or 2'], 400);
        }
        $q['direction'] = (int) $dir;
    }
    return $q;
}

function lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);   // mbstring is not on every host
}

/** Ids of all SL sites whose name is exactly $name (case-insensitive). */
function lookup_site_ids(string $name, string $base): array
{
    $file = 'sl_sites_' . md5(lower($name)) . '.json';
    $hit = cache_read($file, SITE_LIST_CACHE_DAYS * 86400);
    if ($hit !== null) {
        return json_decode($hit, true) ?: [];
    }
    [$code, $body] = http_get($base . '/sites');
    $sites = $code === 200 ? json_decode($body, true) : null;
    if (!is_array($sites)) {
        return [];
    }
    $ids = [];
    foreach ($sites as $s) {
        if (isset($s['id'], $s['name']) && lower((string) $s['name']) === lower($name)) {
            $ids[] = (int) $s['id'];
        }
    }
    if ($ids) {
        cache_write($file, json_encode($ids));
    }
    return $ids;
}

function departures_for(array $q, string $base, bool $debug): array
{
    $ids = $q['site'] ?? [];
    if (isset($q['name'])) {
        $ids = array_values(array_unique(array_merge($ids, lookup_site_ids($q['name'], $base))));
    }
    $ids = array_slice($ids, 0, MAX_SITES);
    if (!$ids) {
        return ['error' => 'no SL site found for that stop'];
    }

    // Ask SL with the filters it may understand; if it refuses, ask with fewer. Everything is checked again below.
    $attempts = [];
    $full = array_intersect_key($q, ['direction' => 1, 'line' => 1]);
    $attempts[] = $full;
    if (isset($full['line'])) {
        $attempts[] = array_intersect_key($full, ['direction' => 1]);
    }
    $attempts[] = [];

    $deps = [];
    $tried = [];
    $seen = [];
    foreach ($ids as $id) {
        $code = 0;
        $body = '';
        foreach ($attempts as $params) {
            [$code, $body] = http_get("$base/sites/$id/departures" . ($params ? '?' . http_build_query($params) : ''));
            if ($code === 200) {
                break;
            }
        }
        $n = 0;
        foreach (($code === 200 ? (json_decode($body, true)['departures'] ?? []) : []) as $d) {
            if (isset($q['line']) && (string) ($d['line']['designation'] ?? '') !== $q['line']) {
                continue;
            }
            if (isset($q['dest']) && stripos((string) ($d['destination'] ?? ''), $q['dest']) === false) {
                continue;
            }
            if (isset($q['direction']) && isset($d['direction_code']) && (int) $d['direction_code'] !== $q['direction']) {
                continue;
            }
            $key = (string) ($d['expected'] ?? $d['scheduled'] ?? '') . '|' . ($d['destination'] ?? '') . '|' . ($d['line']['designation'] ?? '');
            if (isset($seen[$key])) {                              // the same bus seen through two sites
                continue;
            }
            $seen[$key] = true;
            $deps[] = $d;
            $n++;
        }
        $tried[] = ['site' => $id, 'http' => $code, 'matching' => $n];
    }
    usort($deps, fn($a, $b) => strcmp((string) ($a['expected'] ?? $a['scheduled'] ?? ''), (string) ($b['expected'] ?? $b['scheduled'] ?? '')));
    $out = ['departures' => $deps];
    if ($debug) {
        $out['sites'] = $ids;
        $out['tried'] = $tried;
    }
    return $out;
}

if (!defined('SL_PROXY_LIB')) {
    $debug = isset($_GET['debug']);
    $q = read_query();
    $cacheName = 'sl_q_' . md5(json_encode($q)) . '.json';
    if (!$debug && ($hit = cache_read($cacheName, CACHE_SECONDS)) !== null) {
        echo $hit;
        exit;
    }
    $out = departures_for($q, $SL_BASE, $debug);
    if (isset($out['error'])) {
        reply($out, 502);
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    if (!$debug) {
        cache_write($cacheName, $json);
    }
    echo $json;
}
