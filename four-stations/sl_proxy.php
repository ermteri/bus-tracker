<?php
declare(strict_types=1);

/*
 * sl_proxy.php
 */
const CACHE_SECONDS = 10;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$siteId = (string) ($_GET['site_id'] ?? '1512');
$dir    = (string) ($_GET['direction'] ?? '');

if (!ctype_digit($siteId)) {
    http_response_code(400);
    echo '{"error":"Invalid site_id"}';
    exit;
}

$cache = sys_get_temp_dir() . "/sl_departures_{$siteId}_{$dir}.json";
if (is_file($cache) && time() - (int) filemtime($cache) < CACHE_SECONDS) {
    readfile($cache);
    exit;
}

$url = "https://transport.integration.sl.se/v1/sites/{$siteId}/departures";
if (in_array($dir, ['1', '2'], true)) {
    $url .= "?direction={$dir}";
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => '',
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 sl-proxy/1.0',
]);
$data = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

if ($data === false || $code !== 200) {
    http_response_code(502);
    echo json_encode(['error' => "SL API HTTP $code"]);
    exit;
}

@file_put_contents($cache, $data);
echo $data;
