<?php
declare(strict_types=1);

/*
 * bus.php - when does the 168 (toward Södersjukhuset) reach Sköntorp?
 * Reads SL's GTFS-Realtime feeds from Trafiklab and returns a tiny JSON reply.
 *
 *   https://YOUR-SITE/bus.php?t=SECRET            next 4 buses (src = live or timetable)
 *   https://YOUR-SITE/bus.php?t=SECRET&gps=1      same + GPS position and "where is the bus" (needs ROUTE_STOPS)
 *   https://YOUR-SITE/bus.php?t=SECRET&debug=1    every trip calling at the stop (use this first)
 *
 * Needs PHP 8.1+ with cURL. No Composer, no extra libraries.
 * Put RT_KEY, SECRET, LAST_STOP_IDS and ROUTE_STOPS in bus_config.php (see the template).
 */

// ===================== SETTINGS =====================
// Your private values (key, secret, route stops ...) live in bus_config.php next to this file,
// so you can replace bus.php with a newer version without typing them in again.
if (is_file(__DIR__ . '/bus_config.php')) {
    require __DIR__ . '/bus_config.php';
}
foreach ([
    'RT_KEY'        => '',                      // Trafiklab key for GTFS Regional Realtime
    'SECRET'        => '',                      // the page/gadget sends this as ?t=...
    'STOP_IDS'      => ['9022001013119001'],    // Sköntorp (this stop id is used by both directions!)
    'ROUTE_IDS'     => [],                      // optional route filter (the feed has no route ids so far)
    'CACHE_SECONDS' => 10,                      // protects your Trafiklab quota
    'SITE_ID'       => 1512,                    // SL site id for Sköntorp (timetable fallback)
    'DIRECTION'     => 2,                       // 2 = toward Södersjukhuset
    'LINE'          => '168',
    'DEST_MATCH'    => 'Södersjukhuset',        // timetable entries must have this destination ('' = any)
    'ROUTE_STOPS'   => [],                      // stops of the route; make_route_stops.py prints this
    'AT_STOP_M'     => 120,                     // within this many metres of a stop = "at" the stop
    'FAR_M'         => 2000,                    // farther than this from every route stop = no position text
    'LAST_STOP_IDS' => [],                      // end stops of the right trips (check_trip.py prints this)
    'DIRECTION_ID'  => null,                    // live feed direction_id (the feed has none so far)
    'MATCH_SECONDS' => 480,                     // live + timetable within 8 min = same bus
    'LIVE_GRACE_S'  => 300,                     // keep a live bus listed this long after its predicted time
    'MOVING_AWAY_M' => 80,                      // overdue bus this much farther than its closest approach = it has passed
] as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}
// ====================================================

// ---------- minimal protobuf reader (wire format only) ----------
function pb_varint(string $b, int &$p): int
{
    $r = 0;
    $shift = 0;
    $n = strlen($b);
    while ($p < $n) {
        $c = ord($b[$p++]);
        $r |= ($c & 0x7F) << $shift;
        if (!($c & 0x80)) {
            return $r;
        }
        $shift += 7;
    }
    throw new RuntimeException('truncated varint');
}

/** @return array<int, array{0:int,1:int,2:int|string}> list of [field, wiretype, value] */
function pb_fields(string $b): array
{
    $p = 0;
    $n = strlen($b);
    $out = [];
    while ($p < $n) {
        $key = pb_varint($b, $p);
        $f = $key >> 3;
        $w = $key & 7;
        switch ($w) {
            case 0:
                $v = pb_varint($b, $p);
                break;
            case 1:
                $v = substr($b, $p, 8);
                $p += 8;
                break;
            case 2:
                $len = pb_varint($b, $p);
                $v = substr($b, $p, $len);
                $p += $len;
                break;
            case 5:
                $v = substr($b, $p, 4);
                $p += 4;
                break;
            default:
                throw new RuntimeException("unsupported wire type $w");
        }
        $out[] = [$f, $w, $v];
    }
    return $out;
}

// ---------- GTFS-Realtime pieces we need ----------
/** TripDescriptor: trip_id = 1, schedule_relationship = 4 (3 = CANCELED), route_id = 5, direction_id = 6 */
function trip_info(string $td): array
{
    $id = '';
    $route = '';
    $dir = null;
    $rel = 0;
    foreach (pb_fields($td) as [$f, $w, $v]) {
        if ($f === 1 && $w === 2) {
            $id = $v;
        } elseif ($f === 4 && $w === 0) {
            $rel = $v;
        } elseif ($f === 5 && $w === 2) {
            $route = $v;
        } elseif ($f === 6 && $w === 0) {
            $dir = $v;
        }
    }
    return [$id, $route, $dir, $rel];
}

/** StopTimeEvent: delay = 1, time = 2 */
function stop_event(string $ev): array
{
    $time = 0;
    $delay = null;
    foreach (pb_fields($ev) as [$f, $w, $v]) {
        if ($f === 2 && $w === 0) {
            $time = $v;
        } elseif ($f === 1 && $w === 0) {
            $delay = $v;
        }
    }
    return [$time, $delay];
}

/** FeedHeader.timestamp (field 3 of the header, which is field 1 of the feed): when SL produced the feed. */
function feed_header_ts(string $feed): ?int
{
    try {
        $p = 0;
        $key = pb_varint($feed, $p);
        if ($key !== ((1 << 3) | 2)) {
            return null;
        }
        $len = pb_varint($feed, $p);
        foreach (pb_fields(substr($feed, $p, $len)) as [$f, $w, $v]) {
            if ($f === 3 && $w === 0) {
                return $v;
            }
        }
    } catch (Throwable $e) {
    }
    return null;
}

/** TripUpdates feed -> arrivals at our stop(s) */
function find_arrivals(string $feed): array
{
    $out = [];
    foreach (pb_fields($feed) as [$f, $w, $ent]) {
        if ($f !== 2 || $w !== 2) {            // FeedMessage.entity
            continue;
        }
        foreach (pb_fields($ent) as [$f2, $w2, $tu]) {
            if ($f2 !== 3 || $w2 !== 2) {      // FeedEntity.trip_update
                continue;
            }
            $trip = '';
            $route = '';
            $dirId = null;
            $tripRel = 0;
            $updTs = null;
            $stus = [];
            foreach (pb_fields($tu) as [$f3, $w3, $v3]) {
                if ($f3 === 1 && $w3 === 2) {
                    [$trip, $route, $dirId, $tripRel] = trip_info($v3);
                } elseif ($f3 === 2 && $w3 === 2) {
                    $stus[] = $v3;             // stop_time_update
                } elseif ($f3 === 4 && $w3 === 0) {
                    $updTs = $v3;              // when SL calculated this prediction
                }
            }
            if (ROUTE_IDS && $route !== '' && !in_array($route, ROUTE_IDS, true)) {
                continue;
            }
            if (DIRECTION_ID !== null && $dirId !== null && $dirId !== DIRECTION_ID) {
                continue;
            }
            if ($tripRel === 3) {                  // CANCELED trip: never show it
                continue;
            }
            $firstIdx = count($out);
            $lastStop = '';
            $firstStop = null;
            foreach ($stus as $stu) {
                $stop = '';
                $arr = 0;
                $dep = 0;
                $delay = null;
                $stopRel = 0;
                foreach (pb_fields($stu) as [$f4, $w4, $v4]) {
                    if ($f4 === 4 && $w4 === 2) {
                        $stop = $v4;
                    } elseif ($f4 === 5 && $w4 === 0) {
                        $stopRel = $v4;                // 1 = SKIPPED, 2 = NO_DATA
                    } elseif ($f4 === 2 && $w4 === 2) {
                        [$arr, $delay] = stop_event($v4);
                    } elseif ($f4 === 3 && $w4 === 2) {
                        [$dep, $d2] = stop_event($v4);
                        $delay ??= $d2;
                    }
                }
                $lastStop = $stop;
                $firstStop ??= $stop;
                $time = $arr ?: $dep;
                if ($time && $stopRel !== 1 && in_array($stop, STOP_IDS, true)) {
                    $out[] = ['trip' => $trip, 'route' => $route, 'dir_id' => $dirId, 'time' => $time, 'delay' => $delay,
                              'rel' => $tripRel, 'stop_rel' => $stopRel, 'upd_ts' => $updTs];
                }
            }
            // Direction guard: the right trips end at one of LAST_STOP_IDS. If the feed only lists
            // our own stop (list cut short), we can't tell, so the trip is kept.
            if (LAST_STOP_IDS && $lastStop !== '' && !in_array($lastStop, STOP_IDS, true)
                && !in_array($lastStop, LAST_STOP_IDS, true)) {
                array_splice($out, $firstIdx);
                continue;
            }
            for ($i = $firstIdx; $i < count($out); $i++) {
                $out[$i]['stops_n'] = count($stus);
                $out[$i]['last_stop'] = $lastStop;
                $out[$i]['next_stop'] = $firstStop;
            }
        }
    }
    return $out;
}

/** VehiclePositions feed -> [trip_id => [lat, lon, vstop, vstatus, vseq]] for the wanted trips */
function find_positions(string $feed, array $wanted): array
{
    $out = [];
    foreach (pb_fields($feed) as [$f, $w, $ent]) {
        if ($f !== 2 || $w !== 2) {
            continue;
        }
        foreach (pb_fields($ent) as [$f2, $w2, $veh]) {
            if ($f2 !== 4 || $w2 !== 2) {      // FeedEntity.vehicle
                continue;
            }
            $trip = '';
            $pos = null;
            $vstop = '';
            $vstatus = null;
            $vseq = null;
            $vts = null;
            foreach (pb_fields($veh) as [$f3, $w3, $v3]) {
                if ($f3 === 1 && $w3 === 2) {
                    [$trip] = trip_info($v3);
                } elseif ($f3 === 2 && $w3 === 2) {
                    $pos = $v3;
                } elseif ($f3 === 3 && $w3 === 0) {
                    $vseq = $v3;                  // current_stop_sequence
                } elseif ($f3 === 4 && $w3 === 0) {
                    $vstatus = $v3;               // 0 incoming, 1 stopped at, 2 in transit to
                } elseif ($f3 === 5 && $w3 === 0) {
                    $vts = $v3;                   // when the position was recorded
                } elseif ($f3 === 7 && $w3 === 2) {
                    $vstop = $v3;                 // stop_id
                }
            }
            if ($pos === null || !in_array($trip, $wanted, true)) {
                continue;
            }
            $lat = $lon = $bearing = $speed = null;
            foreach (pb_fields($pos) as [$f4, $w4, $v4]) {
                if ($f4 === 1 && $w4 === 5) {
                    $lat = unpack('g', $v4)[1];
                } elseif ($f4 === 2 && $w4 === 5) {
                    $lon = unpack('g', $v4)[1];
                } elseif ($f4 === 3 && $w4 === 5) {
                    $bearing = unpack('g', $v4)[1];
                } elseif ($f4 === 5 && $w4 === 5) {
                    $speed = unpack('g', $v4)[1];
                }
            }
            if ($lat !== null && $lon !== null) {
                $out[$trip] = ['lat' => round($lat, 5), 'lon' => round($lon, 5),
                               'bearing' => $bearing === null ? null : round($bearing),
                               'speed' => $speed === null ? null : round($speed, 1),
                               'vstop' => $vstop, 'vstatus' => $vstatus, 'vseq' => $vseq, 'pos_ts' => $vts];
            }
        }
    }
    return $out;
}

function haversine_m(float $la1, float $lo1, float $la2, float $lo2): float
{
    $p1 = deg2rad($la1);
    $p2 = deg2rad($la2);
    $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lo2 - $lo1) / 2) ** 2;
    return 2 * 6371000.0 * asin(sqrt($a));
}

/**
 * Distance in metres from a point to the straight line between two stops, plus where along that line
 * the point falls (t < 0: before the first stop, t > 1: beyond the second).
 */
function segment_distance(float $lat, float $lon, array $a, array $b): array
{
    $kx = cos(deg2rad($lat)) * 111320.0;
    $ax = ($a[3] - $lon) * $kx;
    $ay = ($a[2] - $lat) * 111320.0;
    $dx = ($b[3] - $lon) * $kx - $ax;
    $dy = ($b[2] - $lat) * 111320.0 - $ay;
    $len2 = $dx * $dx + $dy * $dy;
    $t = $len2 > 0 ? (-$ax * $dx - $ay * $dy) / $len2 : 0.0;
    $tc = max(0.0, min(1.0, $t));
    return [hypot($ax + $tc * $dx, $ay + $tc * $dy), $t];
}

/**
 * Where is the bus along ROUTE_STOPS? (the last entry is our own stop)
 * Within AT_STOP_M of a stop it is "at" that stop. Otherwise the bus is placed on the stretch of the route
 * (the line between two consecutive stops) it is closest to. A nearest-stop guess goes wrong at bends, where
 * the stop after the next one can be closer than the stop behind. If the feed's list of coming stops starts
 * at $nextStopId, stretches that are already behind that stop are ruled out.
 */
function describe_position(float $lat, float $lon, ?string $nextStopId = null): ?array
{
    $n = count(ROUTE_STOPS) - 1;
    if ($n < 1) {
        return null;
    }
    $d = [];
    foreach (ROUTE_STOPS as $i => $st) {
        $d[$i] = haversine_m($lat, $lon, $st[2], $st[3]);
    }
    $i = (int) array_search(min($d), $d, true);
    $dist = (int) round($d[$i]);
    if ($d[$i] > FAR_M) {
        return null;
    }
    if ($d[$i] <= AT_STOP_M) {
        return ['kind' => 'at', 'stop' => ROUTE_STOPS[$i][1], 'dist_m' => $dist, 'stops_left' => $n - $i];
    }

    $from = 0;                                        // first stretch to consider
    if ($nextStopId !== null) {
        foreach (ROUTE_STOPS as $k => $st) {
            if ($st[0] === $nextStopId) {
                $from = max(0, $k - 1);               // the stretch leading into the feed's next stop
                break;
            }
        }
    }
    $best = null;
    for ($k = $from; $k < $n; $k++) {
        [$dseg, $t] = segment_distance($lat, $lon, ROUTE_STOPS[$k], ROUTE_STOPS[$k + 1]);
        if ($best === null || $dseg < $best[0]) {
            $best = [$dseg, $k, $t];
        }
    }
    [, $k, $t] = $best;
    if ($k === 0 && $t < 0) {                         // before the first listed stop: can't say which side
        return ['kind' => 'near', 'stop' => ROUTE_STOPS[0][1], 'dist_m' => $dist, 'stops_left' => $n];
    }
    return ['kind' => 'between', 'from' => ROUTE_STOPS[$k][1], 'to' => ROUTE_STOPS[$k + 1][1],
            'dist_m' => $dist, 'stops_left' => $n - $k];
}

/**
 * Sköntorp is a dead end: after the bus has turned, it drives back past the stops it just served,
 * and the feed may still list its (old) arrival at Sköntorp for a while. So decide from the GPS
 * whether a bus has already passed. Returns [trip_id => reason] for buses to hide.
 * $state remembers, per trip, how close the bus has been (kept in a small temp file).
 */
function passed_trips(array $buses, array $pos, array &$state, int $now): array
{
    $drop = [];
    $nStops = count(ROUTE_STOPS);
    if ($nStops < 2) {
        return $drop;
    }
    $ours = ROUTE_STOPS[$nStops - 1];
    foreach ($buses as $b) {
        $trip = $b['trip'];
        if ($trip === '' || !isset($pos[$trip])) {
            continue;
        }
        $p = $pos[$trip];
        $d = haversine_m($p['lat'], $p['lon'], $ours[2], $ours[3]);
        $atOurs = $d <= AT_STOP_M;
        $st = $state[$trip] ?? ['min' => $d, 'arrived' => false];
        $st['min'] = min($st['min'], $d);
        $st['arrived'] = $st['arrived'] || $atOurs;
        $st['seen'] = $now;
        $state[$trip] = $st;
        if ($atOurs) {
            continue;                                              // at the stop: show it
        }
        if ($st['arrived']) {
            $drop[$trip] = 'has left the stop';
        } elseif ($b['min'] <= -0.5 && $d > $st['min'] + MOVING_AWAY_M) {        // overdue by 30 s or more
            $drop[$trip] = 'moving away';
        } elseif ($b['min'] < 0 && $d > 150 && ($p['speed'] ?? 2) > 1 && $p['bearing'] !== null) {
            // heading check, only if the feed gives a bearing: pointing away from the stop = passed
            $to = rad2deg(atan2(($ours[3] - $p['lon']) * cos(deg2rad($p['lat'])), $ours[2] - $p['lat']));
            $diff = abs(fmod($p['bearing'] - $to + 540, 360) - 180);
            if ($diff > 110) {
                $drop[$trip] = 'heading away';
            }
        }
    }
    return $drop;
}

function load_state(): array
{
    $j = @file_get_contents(sys_get_temp_dir() . '/bus168_state.json');
    $a = $j ? json_decode($j, true) : null;
    return is_array($a) ? $a : [];
}

function save_state(array $state, int $now): void
{
    foreach ($state as $k => $v) {
        if (($v['seen'] ?? 0) < $now - 10800) {                    // forget trips older than 3 h
            unset($state[$k]);
        }
    }
    @file_put_contents(sys_get_temp_dir() . '/bus168_state.json', json_encode($state), LOCK_EX);
}

// ---------- plumbing ----------
function fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function http_get(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',            // sends Accept-Encoding and unpacks gzip
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 bus168/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
    ]);
    $dateHeader = null;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, string $h) use (&$dateHeader) {
        if (stripos($h, 'date:') === 0) {
            $dateHeader = strtotime(trim(substr($h, 5))) ?: null;
        }
        return strlen($h);
    });
    $data = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $GLOBALS['bus_http_date'] = $dateHeader;             // read right after fetch_feed()
    $GLOBALS['bus_host_time'] = time();
    return ($data === false || $code !== 200) ? null : $data;
}

/**
 * How far is this web host's clock from the clock of the server that answered (HTTP Date header)?
 * All arrival times come from the feed, so a host clock that is minutes off would make every
 * "minutes left" wrong. Small differences (cached responses, jitter) are ignored.
 */
function clock_offset(?int $httpDate, int $hostNow): int
{
    if ($httpDate === null) {
        return 0;
    }
    $raw = $httpDate - $hostNow;
    return (abs($raw) >= 20 && abs($raw) <= 3600) ? $raw : 0;
}

function fetch_feed(string $name): string
{
    $data = http_get("https://opendata.samtrafiken.se/gtfs-rt/sl/$name.pb?key=" . RT_KEY);
    if ($data === null) {
        fail(502, "Trafiklab $name: request failed (check RT_KEY)");
    }
    return $data;
}

/** SL Transport API (no key): departures of our line as [time, dest]. Soft-fails to []. */
function fetch_timetable(): array
{
    $json = http_get('https://transport.integration.sl.se/v1/sites/' . SITE_ID . '/departures?direction=' . DIRECTION);
    if ($json === null) {
        return [];
    }
    $out = [];
    try {
        foreach ((json_decode($json, true)['departures'] ?? []) as $d) {
            if ((string) ($d['line']['designation'] ?? '') !== LINE) {
                continue;
            }
            if (isset($d['direction_code']) && (int) $d['direction_code'] !== DIRECTION) {
                continue;
            }
            $dest = (string) ($d['destination'] ?? '');
            if (DEST_MATCH !== '' && $dest !== '' && stripos($dest, DEST_MATCH) === false) {
                continue;
            }
            $t = $d['expected'] ?? $d['scheduled'] ?? null;
            if ($t) {
                $out[] = [
                    'time' => (new DateTimeImmutable($t, new DateTimeZone('Europe/Stockholm')))->getTimestamp(),
                    'dest' => $dest,
                ];
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/** Live predictions first; timetable entries only for buses the live feed doesn't have. */
function merge_buses(array $live, array $timetable, int $now): array
{
    $all = [];
    foreach ($live as $a) {
        if ($a['time'] >= $now - LIVE_GRACE_S) {
            $a['src'] = 'live';
            $a['dest'] = '';
            $all[] = $a;
        }
    }
    foreach ($timetable as $tt) {
        $ts = $tt['time'];
        if ($ts < $now - 60) {
            continue;
        }
        foreach ($all as $a) {
            if ($a['src'] === 'live' && abs($a['time'] - $ts) < MATCH_SECONDS) {
                continue 2;
            }
        }
        $all[] = ['trip' => '', 'route' => '', 'dir_id' => null, 'time' => $ts, 'delay' => null,
                  'src' => 'timetable', 'dest' => $tt['dest'], 'stops_n' => null, 'last_stop' => null, 'rel' => null, 'stop_rel' => null];
    }
    usort($all, fn($x, $y) => $x['time'] <=> $y['time']);
    return $all;
}

// ---------- main (skipped when this file is included by a test) ----------
if (!defined('BUS168_LIB')) {
    date_default_timezone_set('Europe/Stockholm');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (SECRET === '') {
        fail(500, 'SECRET is not set in bus_config.php');
    }
    if (!hash_equals(SECRET, (string) ($_GET['t'] ?? ''))) {
        fail(403, 'forbidden');
    }
    $debug = isset($_GET['debug']);
    $gps = isset($_GET['gps']);
    $cacheFile = sys_get_temp_dir() . '/bus168_cache' . ($gps ? '_gps' : '') . '.json';

    if (!$debug && is_file($cacheFile) && time() - (int) filemtime($cacheFile) < CACHE_SECONDS) {
        readfile($cacheFile);
        exit;
    }

    try {
        $feed = fetch_feed('TripUpdates');
        $rawSkew = isset($GLOBALS['bus_http_date']) ? $GLOBALS['bus_http_date'] - $GLOBALS['bus_host_time'] : 0;
        $now = time() + clock_offset($GLOBALS['bus_http_date'] ?? null, $GLOBALS['bus_host_time'] ?? time());
        $all = merge_buses(find_arrivals($feed), fetch_timetable(), $now);
        $nextStopByTrip = [];
        foreach ($all as $a) {
            if (!empty($a['trip'])) {
                $nextStopByTrip[$a['trip']] = $a['next_stop'] ?? null;
            }
        }
        $all = array_slice($all, 0, $debug ? 25 : 5);

        $buses = [];
        foreach ($all as $a) {
            $buses[] = [
                'src'      => $a['src'],
                'arrives'  => date('H:i:s', $a['time']),
                'min'      => round(($a['time'] - $now) / 60, 1),
                'delay_s'  => $a['delay'],
                'dest'     => $a['dest'],
                'trip'     => $a['trip'],
                'route'    => $a['route'],
                'dir_id'   => $a['dir_id'],
            ] + ($debug ? ['upd_age_s' => !empty($a['upd_ts']) ? $now - $a['upd_ts'] : null, 'next_stop' => $a['next_stop'] ?? null, 'stops_n' => $a['stops_n'] ?? null, 'last_stop' => $a['last_stop'] ?? null, 'rel' => $a['rel'] ?? null, 'stop_rel' => $a['stop_rel'] ?? null] : []);
        }
        $liveTrips = array_values(array_filter(array_column($buses, 'trip')));
        if ($gps && $liveTrips) {
            $pos = find_positions(fetch_feed('VehiclePositions'), array_slice($liveTrips, 0, 2));
            foreach ($buses as &$b) {
                if (isset($pos[$b['trip']])) {
                    $p = $pos[$b['trip']];
                    $b['lat'] = $p['lat'];
                    $b['lon'] = $p['lon'];
                    if (ROUTE_STOPS && ($w = describe_position($p['lat'], $p['lon'], $nextStopByTrip[$b['trip']] ?? null))) {
                        $b['where'] = $w;
                    }
                    if ($debug) {
                        $b['bearing'] = $p['bearing'];
                        $b['speed'] = $p['speed'];
                        $b['vstop'] = $p['vstop'];
                        $b['vstatus'] = $p['vstatus'];
                        $b['vseq'] = $p['vseq'];
                        $b['pos_age_s'] = !empty($p['pos_ts']) ? $now - $p['pos_ts'] : null;
                    }
                }
            }
            unset($b);

            $state = load_state();
            $drop = passed_trips($buses, $pos, $state, $now);
            save_state($state, $now);
            if ($debug) {
                foreach ($buses as &$b) {
                    if (isset($drop[$b['trip']])) {
                        $b['dropped'] = $drop[$b['trip']];
                    }
                }
                unset($b);
            } else {
                $buses = array_values(array_filter($buses, fn($b) => !isset($drop[$b['trip']])));
            }
        }
        if (!$debug) {
            $buses = array_slice($buses, 0, 4);
        }
        $reply = ['now' => date('H:i:s', $now), 'now_ts' => $now];
        if ($debug) {
            $reply['clock_offset_s'] = $rawSkew;        // other server's clock minus this host's clock
            $feedTs = feed_header_ts($feed);
            $reply['feed_age_s'] = $feedTs ? $now - $feedTs : null;   // how old the TripUpdates feed is
        }
        $reply['buses'] = $buses;
        $json = json_encode($reply, JSON_UNESCAPED_UNICODE);
        if (!$debug) {
            @file_put_contents($cacheFile, $json);
        }
        echo $json;
    } catch (Throwable $e) {
        fail(500, $e->getMessage());
    }
}
