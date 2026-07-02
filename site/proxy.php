<?php
/**
 * IGRS Blockchain API Proxy
 * Routes browser requests to Blockscout PRO API server-side (bypasses CORS)
 * Deploy to: igrs.xyz/proxy.php
 * 
 * Usage:
 *   /proxy.php?chain=1&path=addresses/0x.../transactions
 *   /proxy.php?chain=56&path=addresses/0x.../token-transfers&token=0x...
 */

// ============================================================
// CONFIG — paste your free Blockscout PRO key here
// Get free key (no card) at: https://dev.blockscout.com/
// ============================================================
define('BLOCKSCOUT_API_KEY', 'proapi_bDp0RnsbAYNjCPiPd8PYlTgHQGieSKhrcGfM1T41xfVJEUxdPdZdiQQWpAC7tN6e2_d9K4Ji');
define('BLOCKSCOUT_BASE',    'https://api.blockscout.com');

// ============================================================
// SECURITY — only allow IGRS origin
// ============================================================
$allowed_origins = ['https://igrs.xyz', 'https://www.igrs.xyz'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Also allow during local testing
    header("Access-Control-Allow-Origin: https://igrs.xyz");
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// VALIDATE — check key configured
// ============================================================
if (strpos(BLOCKSCOUT_API_KEY, 'PASTE_') === 0) {
    http_response_code(503);
    echo json_encode(['error' => 'proxy_not_configured', 'message' => 'Blockscout API key not set in proxy.php. Get free key at dev.blockscout.com']);
    exit;
}

// ============================================================
// INPUT — chain ID + API path
// ============================================================
$chain_id = isset($_GET['chain']) ? (int)$_GET['chain'] : 0;
$path     = isset($_GET['path'])  ? trim($_GET['path'], '/') : '';

// Whitelist chain IDs
$allowed_chains = [1, 56]; // Ethereum, BNB Smart Chain
if (!in_array($chain_id, $allowed_chains)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_chain', 'message' => "Chain ID $chain_id not supported. Use 1 (ETH) or 56 (BNB)."]);
    exit;
}

// Whitelist path patterns (security: only address lookup endpoints)
$allowed_patterns = [
    '#^addresses/0x[0-9a-fA-F]{40}$#',
    '#^addresses/0x[0-9a-fA-F]{40}/transactions$#',
    '#^addresses/0x[0-9a-fA-F]{40}/token-transfers$#',
];
$path_ok = false;
foreach ($allowed_patterns as $pattern) {
    if (preg_match($pattern, $path)) { $path_ok = true; break; }
}
if (!$path_ok || empty($path)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_path', 'message' => 'Path not allowed. Only address lookup endpoints permitted.']);
    exit;
}

// ============================================================
// BUILD URL — append allowed query params
// ============================================================
$url = BLOCKSCOUT_BASE . "/$chain_id/api/v2/$path?apikey=" . BLOCKSCOUT_API_KEY;

// Optional: token contract filter for token-transfers
if (isset($_GET['token']) && preg_match('/^0x[0-9a-fA-F]{40}$/', $_GET['token'])) {
    $url .= '&token=' . $_GET['token'];
}

// ============================================================
// FETCH — server-side call to Blockscout
// ============================================================
$ctx = stream_context_create([
    'http' => [
        'method'          => 'GET',
        'header'          => "User-Agent: IGRS-Proxy/1.0\r\n",
        'timeout'         => 15,
        'ignore_errors'   => true,
    ],
    'ssl' => ['verify_peer' => true]
]);

$response = @file_get_contents($url, false, $ctx);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream_failed', 'message' => 'Could not reach Blockscout API.']);
    exit;
}

// Pass through HTTP status from upstream
$status = 200;
if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
}
http_response_code($status);

// Rate limit headers passthrough (useful for debugging)
if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (stripos($h, 'x-credits-remaining') === 0 || stripos($h, 'x-ratelimit') === 0) {
            header($h);
        }
    }
}

echo $response;
