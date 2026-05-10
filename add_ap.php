<?php
session_start();
require 'routeros_api.class.php';
header('Content-Type: application/json');

if (!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])){
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'not_authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$apType = $data['type'] ?? 'wifi';
if(!$data || empty($data['name']) || !isset($data['lat']) || !isset($data['lng'])){
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'invalid_input']);
    exit;
}

if ($apType !== 'odp' && empty($data['ip'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'ip_required_for_wifi']);
    exit;
}

$mt_ip = $_SESSION['ip'];
$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/','_',$mt_ip);
$dataFile = __DIR__."/ap_data_{$safe_ip}.json";
if(!file_exists($dataFile)) file_put_contents($dataFile,json_encode([]));

$apList = json_decode(file_get_contents($dataFile), true) ?: [];

if ($apType === 'wifi') {
    foreach($apList as $ap){ 
        if(!empty($ap['ip']) && $ap['ip']===$data['ip']){
            http_response_code(400);
            echo json_encode(['success'=>false,'error'=>'duplicate_ip']); 
            exit;
        }
    }
}

$apId = uniqid('ap_');
$new = [
    'id'        => $apId,
    'name'      => $data['name'],
    'ip'        => ($apType === 'odp') ? '' : ($data['ip'] ?? ''),
    'lat'       => (float)$data['lat'],
    'lng'       => (float)$data['lng'],
    'line'      => !empty($data['line']) ? $data['line'] : null,
    'lineColor' => $data['lineColor'] ?? 'lime',
    'icon'      => $data['icon'] ?? 'wifi',
    'type'      => $apType
];

$apList[] = $new;

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

// --- TRY UPDATE MIKROTIK DULU ---
$mikrotikOk = false;
$mikrotikError = '';
$API = new RouterosAPI();

if ($API->connect($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])){
    try{
        if ($apType === 'wifi') {
            // ===== WIFI LOGIC =====
            $parentToken = null;
            if (!empty($new['line'])) {
                $foundParentName = null;
                foreach ($apList as $existingAp) {
                    if (isset($existingAp['id']) && $existingAp['id'] === $new['line']) {
                        $foundParentName = trim($existingAp['name']);
                        break;
                    }
                }
                $parentToken = $foundParentName ?: $new['line'];
            }

            if ($parentToken) {
                $commentValue = $new['name'] . "//" . $new['lineColor'] . "/" . $parentToken . "//" . $new['lat'] . "," . $new['lng'];
            } else {
                $commentValue = $new['name'] . "//" . $new['lineColor'] . "///" . $new['lat'] . "," . $new['lng'];
            }

            $existing = $API->comm('/tool/netwatch/print',['?host'=>$new['ip']]);
            checkMikrotikError($existing);
            
            if(count($existing)===0){
                $addResult = $API->comm('/tool/netwatch/add', [
                    'host'     => $new['ip'], 
                    'comment'  => $commentValue, 
                    'interval' => '10s',
                    'timeout'  => '5s'
                ]);
                checkMikrotikError($addResult);
            } else {
                $setResult = $API->comm('/tool/netwatch/set', [
                    '.id'     => $existing[0]['.id'],
                    'comment' => $commentValue,
                    'interval' => '10s',
                    'timeout'  => '5s'
                ]);
                checkMikrotikError($setResult);
            }

            $existsBinding = $API->comm('/ip/hotspot/ip-binding/print', ['?address'=>$new['ip']]);
            checkMikrotikError($existsBinding);
            
            if(count($existsBinding)===0){
                $bindingResult = $API->comm('/ip/hotspot/ip-binding/add', [
                    'address'    => $new['ip'],
                    'to-address' => $new['ip'],
                    'comment'    => $commentValue,
                    'type'       => 'bypassed',
                ]);
                checkMikrotikError($bindingResult);
            } else {
                $bindingSetResult = $API->comm('/ip/hotspot/ip-binding/set', [
                    '.id'       => $existsBinding[0]['.id'],
                    'comment'   => $commentValue,
                    'type'      => 'bypassed'
                ]);
                checkMikrotikError($bindingSetResult);
            }

            $mikrotikOk = true;

        } else if ($apType === 'odp') {
            // ===== ODP CERTIFICATE LOGIC =====
            
            $parentName = '';
            if (!empty($new['line'])) {
                foreach ($apList as $existingAp) {
                    if (isset($existingAp['id']) && $existingAp['id'] === $new['line']) {
                        $parentName = trim($existingAp['name']);
                        break;
                    }
                }
                if (empty($parentName)) {
                    $parentName = $new['line'];
                }
            }

            if (!empty($parentName)) {
                $certName = $new['id'] . '#' . (float)$new['lat'] . '#' . (float)$new['lng'] . '#' . $new['lineColor'] . '#' . $parentName . '#' . $new['name'];
            } else {
                $certName = $new['id'] . '#' . (float)$new['lat'] . '#' . (float)$new['lng'] . '#' . $new['lineColor'] . '#' . $new['name'];
            }

            $addCertResult = $API->comm('/certificate/add', [
                'name' => $certName,
                'common-name' => $new['name'],
                'days-valid' => 3650
            ]);
            checkMikrotikError($addCertResult);
            
            $mikrotikOk = true;
        }

    }catch(Exception $e){ 
        $mikrotikError = $e->getMessage();
        $mikrotikOk = false;
    }
    $API->disconnect();
}

// --- HANYA SAVE JSON JIKA MIKROTIK BERHASIL ---
if(!$mikrotikOk){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'mikrotik_update_failed','detail'=>$mikrotikError]);
    exit;
}

if(false === file_put_contents($dataFile, json_encode($apList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'gagal_simpan']); 
    exit;
}

echo json_encode([
    'success'   => true,
    'ap'        => $new,
    'mikrotik'  => 'ok',
    'ipBinding' => 'ok'
]);
?>