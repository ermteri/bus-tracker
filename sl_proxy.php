<?php
declare(strict_types=1);

/*
 * sl_proxy.php - only needed if the browser refuses to call SL's API directly (CORS).
 * It fetches the departures for ONE fixed stop and passes the JSON on.
 * Use:  sl_proxy.php?direction=1   or   sl_proxy.php?direction=2
 * Then set  const PROXY = 'sl_proxy.php';  in stop_display.html.
 */
const SITE_ID = 1512;          // Sköntorp
const CACHE_SECONDS = 10;      // be polite to SL's API

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = (string) ($_GET['direction'] ?? '');
if (!in_array($dir, ['1', '2'], true)) {
    http_response_code(400);
    echo '{"error":"direction must be 1 or 2"}';
    exit;
}

$cache = sys_get_temp_dir() . "/sl_departures_{$dir}.json";
if (is_file($cache) && time() - (int) filemtime($cache) < CACHE_SECONDS) {
    readfile($cache);
    exit;
}

$ch = curl_init('https://transport.integration.sl.se/v1/sites/' . SITE_ID . "/departures?direction=$dir");
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
