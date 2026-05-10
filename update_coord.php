<?php
session_start();
require 'routeros_api.class.php';
header('Content-Type: application/json');

// --- Step 1: Check Authentication ---
if(!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])){
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'not_authenticated']);
    exit;
}

// --- Step 2: Parse Request ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if(!$data || !isset($data['id'], $data['lat'], $data['lng'])){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_payload']);
    exit;
}

$id  = $data['id'];
$lat = (float)$data['lat'];
$lng = (float)$data['lng'];

// --- Step 3: Load JSON ---
$mt_ip = $_SESSION['ip'];
$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/','_', $mt_ip);
$dataFile = __DIR__ . "/ap_data_{$safe_ip}.json";

if(!file_exists($dataFile)){
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'file_not_found']);
    exit;
}

$apList = json_decode(file_get_contents($dataFile), true) ?: [];

// --- Step 4: Find & Prepare Updated AP ---
$found = false;
$targetAp = null;

foreach($apList as &$ap){
    if(isset($ap['id']) && $ap['id'] === $id){
        $targetAp = $ap;
        $ap['lat'] = $lat;
        $ap['lng'] = $lng;
        $found = true;
        break;
    }
}
unset($ap);

if(!$found){
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'ap_not_found']);
    exit;
}

// ✅ Helper: Check if result contains trap/error
function checkMikrotikError($result) {
    if(is_array($result) && isset($result['!trap'])) {
        $trapMsg = '';
        if(is_array($result['!trap']) && count($result['!trap']) > 0) {
            $trapMsg = $result['!trap'][0]['message'] ?? 'Unknown trap';
        }
        throw new Exception("Mikrotik: $trapMsg");
    }
    return $result;
}

// --- Step 5: UPDATE MIKROTIK DULU (SEBELUM SAVE JSON) ---
$mikrotikOk = false;
$mikrotikError = '';

$API = new RouterosAPI();
$API->debug = false;

if($API->connect($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])){
    
    try {
        $apType = $targetAp['type'] ?? 'wifi';
        
        if ($apType === 'wifi') {
            
            if($targetAp && isset($targetAp['ip']) && !empty($targetAp['ip'])){
                $apIp = $targetAp['ip'];
                
                // BUILD NEW COMMENT
                $parentToken = null;
                if (!empty($targetAp['line'])) {
                    foreach ($apList as $existingAp) {
                        if (isset($existingAp['id']) && $existingAp['id'] === $targetAp['line']) {
                            $parentToken = trim($existingAp['name']);
                            break;
                        }
                    }
                    if (empty($parentToken)) {
                        $parentToken = $targetAp['line'];
                    }
                }
                
                if ($parentToken) {
                    $newComment = $targetAp['name'] . "//" . $targetAp['lineColor'] . "/" . $parentToken . "//" . $lat . "," . $lng;
                } else {
                    $newComment = $targetAp['name'] . "//" . $targetAp['lineColor'] . "///" . $lat . "," . $lng;
                }
                
                // UPDATE NETWATCH
                $rows = $API->comm('/tool/netwatch/print', ['?host'=>$apIp]);
                checkMikrotikError($rows);
                
                if(count($rows) > 0 && isset($rows[0]['.id'])){
                    $result = $API->comm('/tool/netwatch/set', [
                        '.id'     => $rows[0]['.id'],
                        'comment' => $newComment
                    ]);
                    checkMikrotikError($result);
                } else {
                    throw new Exception("Netwatch entry not found for IP: $apIp");
                }
                
                // UPDATE HOTSPOT BINDING
                $binding = $API->comm('/ip/hotspot/ip-binding/print', ['?address'=>$apIp]);
                checkMikrotikError($binding);
                
                if(is_array($binding) && count($binding) > 0 && isset($binding[0]['.id'])){
                    $bindingResult = $API->comm('/ip/hotspot/ip-binding/set', [
                        '.id'     => $binding[0]['.id'],
                        'comment' => $newComment
                    ]);
                    checkMikrotikError($bindingResult);
                }
                
                $mikrotikOk = true;
                
            } else {
                throw new Exception("Invalid AP: no IP");
            }
            
        } else if ($apType === 'odp') {
            
            // Cari parent name
            $parentName = '';
            if (!empty($targetAp['line'])) {
                foreach ($apList as $existingAp) {
                    if (isset($existingAp['id']) && $existingAp['id'] === $targetAp['line']) {
                        $parentName = trim($existingAp['name']);
                        break;
                    }
                }
                if (empty($parentName)) {
                    $parentName = $targetAp['line'];
                }
            }

            if (!empty($parentName)) {
                $newCertName = $targetAp['id'] . '#' . (float)$lat . '#' . (float)$lng . '#' . $targetAp['lineColor'] . '#' . $parentName . '#' . $targetAp['name'];
            } else {
                $newCertName = $targetAp['id'] . '#' . (float)$lat . '#' . (float)$lng . '#' . $targetAp['lineColor'] . '#' . $targetAp['name'];
            }

            $allCerts = $API->comm('/certificate/print');
            checkMikrotikError($allCerts);
            
            $foundCert = null;
            foreach ($allCerts as $cert) {
                $certName = $cert['name'] ?? '';
                if (strpos($certName, $targetAp['id'] . '#') === 0) {
                    $foundCert = $cert;
                    break;
                }
            }

            if ($foundCert && !empty($foundCert['.id'])) {
                $removeResult = $API->comm('/certificate/remove', ['.id' => $foundCert['.id']]);
                checkMikrotikError($removeResult);
            }

            $addResult = $API->comm('/certificate/add', [
                'name' => $newCertName,
                'common-name' => $targetAp['name'],
                'days-valid' => 3650
            ]);
            checkMikrotikError($addResult);
            
            $mikrotikOk = true;
        }
        
    } catch (Exception $e) {
        $mikrotikError = $e->getMessage();
        $mikrotikOk = false;
    }
    
    $API->disconnect();
    
} else {
    $mikrotikError = "Failed to connect to Mikrotik";
    $mikrotikOk = false;
}

// --- HANYA SAVE JSON JIKA MIKROTIK BERHASIL ---
if(!$mikrotikOk){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'mikrotik_update_failed','detail'=>$mikrotikError]);
    exit;
}

// --- SAVE JSON ---
if(false === file_put_contents($dataFile, json_encode($apList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'failed_to_save_json']);
    exit;
}

// --- RESPONSE OK ---
echo json_encode(['ok'=>true,'lat'=>$lat,'lng'=>$lng]);
?>