<?php
// api_update_ap_list.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require 'routeros_api.class.php';

$mt_ip = $_SESSION['ip'];
$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $mt_ip);
$dataFile = __DIR__ . "/ap_data_{$safe_ip}.json";

// Baca AP list dari JSON
$apList = [];
if (file_exists($dataFile)) {
    $apList = json_decode(file_get_contents($dataFile), true) ?: [];
}

$netwatch = [];
$hotspotActive = [];

// Baca Netwatch dari Mikrotik
$API = new RouterosAPI();
$API->debug = false;

if ($API->connect($mt_ip, $_SESSION['user'], $_SESSION['pass'])) {
    $rows = $API->comm('/tool/netwatch/print');
    
    foreach ($rows as $r) {
        if (!isset($r['host'])) continue;
        $hostIp = $r['host'];
        $status = strtolower($r['status'] ?? 'unknown');
        $since = $r['since'] ?? '';
        
        $netwatch[$hostIp] = ['status' => $status, 'since' => $since];
    }
    
    // Ambil hotspot aktif
    $hs = $API->comm('/ip/hotspot/active/print');
    if (is_array($hs)) {
        foreach ($hs as $u) {
            $username = $u['user'] ?? $u['name'] ?? '';
            $address = $u['address'] ?? '';
            $uptime = $u['uptime'] ?? '';
            if ($username || $address) {
                $hotspotActive[] = ['user' => $username, 'address' => $address, 'uptime' => $uptime];
            }
        }
    }
    
    $API->disconnect();
}

// Hitung status
$countUp = 0;
$countDown = 0;
$countUnknown = 0;

foreach ($apList as $ap) {
    $nw = $netwatch[$ap['ip']] ?? null;
    $st = $nw['status'] ?? ($ap['status'] ?? 'unknown');
    if ($st === 'up') $countUp++;
    elseif ($st === 'down') $countDown++;
    else $countUnknown++;
}

// Return JSON
echo json_encode([
    'apList' => $apList,
    'netwatch' => $netwatch,
    'hotspotActive' => $hotspotActive,
    'counts' => [
        'up' => $countUp,
        'down' => $countDown,
        'unknown' => $countUnknown,
        'total' => $countUp + $countDown + $countUnknown
    ]
]);
?>