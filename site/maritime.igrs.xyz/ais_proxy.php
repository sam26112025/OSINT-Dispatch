<?php
/**
 * IGRS TradeMatrix — AIS WebSocket Proxy
 * Relays real-time AIS vessel data from aisstream.io to browser clients
 * 
 * Endpoint: GET /ais_proxy.php?bbox=lat1,lng1,lat2,lng2&limit=N
 * Returns: JSON array of real vessel positions
 * 
 * Deploy to: maritime.igrs.xyz/ais_proxy.php
 */

// ── CONFIG ──────────────────────────────────────────────────
define('AISSTREAM_KEY', '23ea6c9813b8dc0cc4751e0defecd87c800e41ac');
define('CACHE_FILE', sys_get_temp_dir() . '/ais_cache.json');
define('CACHE_TTL', 60); // seconds — refresh every 60s

// ── SECURITY ─────────────────────────────────────────────────
$allowed = ['https://maritime.igrs.xyz', 'https://www.maritime.igrs.xyz', 'https://igrs.xyz'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://maritime.igrs.xyz");
}
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── PARAMS ───────────────────────────────────────────────────
$limit = min((int)($_GET['limit'] ?? 80), 200);

// Bounding box: Indian Ocean + Arabian Sea + Bay of Bengal + surrounding
// Default: broad Indian Ocean coverage
$bbox_param = $_GET['bbox'] ?? '';
if ($bbox_param && preg_match('/^-?\d+\.?\d*,-?\d+\.?\d*,-?\d+\.?\d*,-?\d+\.?\d*$/', $bbox_param)) {
    [$lat1, $lng1, $lat2, $lng2] = array_map('floatval', explode(',', $bbox_param));
} else {
    // Default: Indian Ocean + Arabian Sea + Bay of Bengal + Persian Gulf + Malacca
    $lat1 = -10; $lng1 = 45; $lat2 = 35; $lng2 = 110;
}

// ── CACHE CHECK ───────────────────────────────────────────────
$cache_key = md5("$lat1,$lng1,$lat2,$lng2");
$cache_path = sys_get_temp_dir() . "/ais_cache_{$cache_key}.json";

if (file_exists($cache_path) && (time() - filemtime($cache_path)) < CACHE_TTL) {
    $cached = json_decode(file_get_contents($cache_path), true);
    if ($cached && count($cached) > 0) {
        echo json_encode(['source' => 'cache', 'vessels' => array_slice($cached, 0, $limit), 'count' => count($cached), 'cached_at' => date('c', filemtime($cache_path))]);
        exit;
    }
}

// ── WEBSOCKET VIA STREAM SOCKET ──────────────────────────────
// PHP doesn't have native WebSocket client — use stream_socket_client with WS handshake
$vessels = [];
$timeout = 12; // seconds to collect data

$host = 'stream.aisstream.io';
$port = 443;
$path = '/v0/stream';

$ctx = stream_context_create([
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]
]);

$socket = @stream_socket_client(
    "ssl://{$host}:{$port}",
    $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx
);

if (!$socket) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream_connect_failed', 'message' => "Cannot connect to AIS stream: $errstr ($errno)"]);
    exit;
}

// WebSocket handshake
$key = base64_encode(random_bytes(16));
$handshake = "GET $path HTTP/1.1\r\n"
    . "Host: $host\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: $key\r\n"
    . "Sec-WebSocket-Version: 13\r\n"
    . "Origin: https://maritime.igrs.xyz\r\n"
    . "\r\n";

fwrite($socket, $handshake);

// Read HTTP upgrade response
$response = '';
while (!feof($socket)) {
    $line = fgets($socket);
    $response .= $line;
    if ($line === "\r\n") break; // End of headers
}

if (strpos($response, '101') === false) {
    fclose($socket);
    http_response_code(502);
    echo json_encode(['error' => 'websocket_upgrade_failed', 'response' => substr($response, 0, 200)]);
    exit;
}

// Send subscription message
$subscribe = json_encode([
    'APIKey' => AISSTREAM_KEY,
    'BoundingBoxes' => [[[$lat1, $lng1], [$lat2, $lng2]]],
    'FilterMessageTypes' => ['PositionReport', 'ShipStaticData'],
]);

// Encode as WebSocket frame (text frame, FIN=1, opcode=1)
$frame = ws_encode($subscribe);
fwrite($socket, $frame);

// ── COLLECT MESSAGES ─────────────────────────────────────────
stream_set_timeout($socket, $timeout);
$vessel_map = []; // MMSI => vessel data
$start = time();
$static_data = []; // MMSI => ship name/type from ShipStaticData

while (!feof($socket) && (time() - $start) < $timeout) {
    $msg = ws_read($socket);
    if ($msg === false || $msg === '') continue;

    $data = json_decode($msg, true);
    if (!$data || !isset($data['MessageType'])) continue;

    $meta = $data['Metadata'] ?? [];
    $mmsi = $meta['MMSI'] ?? ($data['Message']['PositionReport']['UserID'] ?? null);
    if (!$mmsi) continue;

    if ($data['MessageType'] === 'PositionReport') {
        $pos = $data['Message']['PositionReport'] ?? [];
        $lat = $meta['latitude'] ?? $meta['Latitude'] ?? ($pos['Latitude'] ?? null);
        $lng = $meta['longitude'] ?? $meta['Longitude'] ?? ($pos['Longitude'] ?? null);

        if ($lat === null || $lng === null || $lat == 0 || $lng == 0) continue;
        // Filter out invalid positions (91.0 = not available per AIS spec)
        if (abs($lat) > 90 || abs($lng) > 180 || $lat == 91.0) continue;

        $vessel_map[$mmsi] = [
            'mmsi'   => (string)$mmsi,
            'name'   => $meta['ShipName'] ?? $static_data[$mmsi]['name'] ?? 'UNKNOWN',
            'lat'    => round((float)$lat, 5),
            'lng'    => round((float)$lng, 5),
            'speed'  => round(($pos['SpeedOverGround'] ?? 0) * 0.1, 1), // 1/10 knot
            'course' => $pos['CourseOverGround'] ?? 0,
            'heading'=> $pos['TrueHeading'] ?? 511, // 511 = not available
            'status' => nav_status($pos['NavigationalStatus'] ?? 15),
            'type'   => $static_data[$mmsi]['type'] ?? 'Unknown',
            'dest'   => $static_data[$mmsi]['dest'] ?? '',
            'flag'   => $static_data[$mmsi]['flag'] ?? '',
            'imo'    => $static_data[$mmsi]['imo'] ?? '',
            'ts'     => time(),
        ];
    } elseif ($data['MessageType'] === 'ShipStaticData') {
        $sd = $data['Message']['ShipStaticData'] ?? [];
        $static_data[$mmsi] = [
            'name'  => trim($sd['Name'] ?? $meta['ShipName'] ?? ''),
            'type'  => ship_type($sd['Type'] ?? 0),
            'dest'  => trim($sd['Destination'] ?? ''),
            'flag'  => flag_from_mmsi($mmsi),
            'imo'   => isset($sd['ImoNumber']) && $sd['ImoNumber'] > 0 ? (string)$sd['ImoNumber'] : '',
        ];
        // Update existing position record if already captured
        if (isset($vessel_map[$mmsi])) {
            $vessel_map[$mmsi] = array_merge($vessel_map[$mmsi], [
                'name' => $static_data[$mmsi]['name'] ?: $vessel_map[$mmsi]['name'],
                'type' => $static_data[$mmsi]['type'],
                'dest' => $static_data[$mmsi]['dest'],
                'flag' => $static_data[$mmsi]['flag'],
                'imo'  => $static_data[$mmsi]['imo'],
            ]);
        }
    }

    if (count($vessel_map) >= $limit * 2) break; // Got enough
}

fclose($socket);

// ── FINALISE ─────────────────────────────────────────────────
$vessels = array_values($vessel_map);

// Sort by most recently updated, filter unnamed if we have enough
usort($vessels, fn($a,$b) => $b['ts'] - $a['ts']);

// Prefer named vessels
$named = array_filter($vessels, fn($v) => $v['name'] !== 'UNKNOWN' && $v['name'] !== '');
$unnamed = array_filter($vessels, fn($v) => $v['name'] === 'UNKNOWN' || $v['name'] === '');
$final = array_merge(array_values($named), array_values($unnamed));
$final = array_slice($final, 0, $limit);

// Cache result
if (count($final) > 0) {
    file_put_contents($cache_path, json_encode($final));
}

echo json_encode([
    'source'  => 'live',
    'vessels' => $final,
    'count'   => count($final),
    'bbox'    => [$lat1, $lng1, $lat2, $lng2],
    'fetched_at' => date('c'),
    'feed'    => 'aisstream.io',
]);

// ── HELPERS ──────────────────────────────────────────────────
function ws_encode(string $data): string {
    $len = strlen($data);
    $mask = random_bytes(4);
    $header = chr(0x81); // FIN + text frame
    if ($len < 126) {
        $header .= chr($len | 0x80); // masked
    } elseif ($len < 65536) {
        $header .= chr(126 | 0x80) . pack('n', $len);
    } else {
        $header .= chr(127 | 0x80) . pack('J', $len);
    }
    $header .= $mask;
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $data[$i] ^ $mask[$i % 4];
    }
    return $header . $masked;
}

function ws_read($socket): string|false {
    $header = fread($socket, 2);
    if (strlen($header) < 2) return false;

    $byte1 = ord($header[0]);
    $byte2 = ord($header[1]);
    // $fin = ($byte1 & 0x80) !== 0;
    $opcode = $byte1 & 0x0f;
    $masked = ($byte2 & 0x80) !== 0;
    $len = $byte2 & 0x7f;

    if ($opcode === 8) return false; // close frame
    if ($opcode === 9) { // ping → send pong
        fwrite($socket, chr(0x8a) . chr(0));
        return '';
    }

    if ($len === 126) {
        $ext = fread($socket, 2);
        if (strlen($ext) < 2) return false;
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($socket, 8);
        if (strlen($ext) < 8) return false;
        $len = unpack('J', $ext)[1];
    }

    if ($len === 0) return '';
    if ($len > 1048576) return false; // 1MB max

    $mask_bytes = $masked ? fread($socket, 4) : '';
    $payload = '';
    $remaining = $len;
    while ($remaining > 0) {
        $chunk = fread($socket, min($remaining, 8192));
        if ($chunk === false || $chunk === '') break;
        $payload .= $chunk;
        $remaining -= strlen($chunk);
    }

    if ($masked && $mask_bytes) {
        $result = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $result .= $payload[$i] ^ $mask_bytes[$i % 4];
        }
        return $result;
    }
    return $payload;
}

function nav_status(int $s): string {
    $map = [0=>'Under way using engine',1=>'At anchor',2=>'Not under command',3=>'Restricted manoeuvrability',
            4=>'Constrained by her draught',5=>'Moored',6=>'Aground',7=>'Engaged in fishing',
            8=>'Under way sailing',15=>'Not defined'];
    return $map[$s] ?? 'Unknown';
}

function ship_type(int $t): string {
    if ($t >= 70 && $t <= 79) return 'Cargo Ship';
    if ($t >= 80 && $t <= 89) return 'Tanker';
    if ($t >= 60 && $t <= 69) return 'Passenger Ship';
    if ($t >= 30 && $t <= 39) return 'Fishing';
    if ($t >= 40 && $t <= 49) return 'High Speed Craft';
    if ($t >= 50 && $t <= 59) return 'Special Craft';
    if ($t === 36 || $t === 37) return 'Sailing Vessel';
    if ($t >= 20 && $t <= 29) return 'WIG';
    if ($t === 31 || $t === 32) return 'Towing';
    return 'Unknown';
}

function flag_from_mmsi(int $mmsi): string {
    $prefix = intdiv($mmsi, 1000000);
    $flags = [
        338=>'USA',319=>'Cayman Islands',311=>'Bahamas',352=>'Panama',636=>'Liberia',
        477=>'Hong Kong',566=>'Singapore',357=>'Panama',432=>'Japan',416=>'Taiwan',
        440=>'South Korea',412=>'China',413=>'China',419=>'India',339=>'Jamaica',
        235=>'UK',229=>'Malta',248=>'Malta',255=>'Portugal',241=>'Greece',237=>'Greece',
        240=>'Greece',273=>'Russia',272=>'Ukraine',374=>'Trinidad',305=>'Antigua',
        325=>'Dominica',341=>'St Vincent',503=>'Australia',512=>'NZ',
        548=>'Philippines',525=>'Indonesia',533=>'Malaysia',
    ];
    return $flags[$prefix] ?? "MID-$prefix";
}
