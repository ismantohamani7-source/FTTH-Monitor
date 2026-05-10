<?php
session_start();
require 'routeros_api.class.php';
header('Content-Type: application/json');

if(!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])){
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'not_authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$apType = $data['type'] ?? 'wifi';
if(!$data || empty($data['id']) || empty($data['name'])){
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'invalid_input']);
    exit;
}

if ($apType !== 'odp' && empty($data['ip'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'ip_required_for_wifi']);
    exit;
}

$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/','_', $_SESSION['ip']);
$dataFile = __DIR__."/ap_data_{$safe_ip}.json";
$apList = json_decode(file_get_contents($dataFile), true) ?: [];

$idx = null;
foreach($apList as $k=>$ap){ 
    if($ap['id']===$data['id']){ 
        $idx=$k; 
        break; 
    } 
}
if($idx===null){ 
    http_response_code(404); 
    echo json_encode(['success'=>false,'error'=>'not_found']); 
    exit; 
}

$oldIp = $apList[$idx]['ip'];
$oldName = $apList[$idx]['name']; // SIMPAN NAMA LAMA
$oldType = $apList[$idx]['type']; // SIMPAN TYPE LAMA

if ($apType === 'wifi') {
    foreach($apList as $k=>$ap){ 
        if($k!=$idx && !empty($ap['ip']) && $ap['ip']===$data['ip']){ 
            echo json_encode(['success'=>false,'error'=>'duplicate_ip']); 
            exit; 
        }
    }
}

if(empty($data['lineColor']) && isset($apList[$idx]['lineColor'])){
    $data['lineColor'] = $apList[$idx]['lineColor'];
}

$apList[$idx] = [
    'id'        => $data['id'],
    'name'      => $data['name'],
    'ip'        => ($apType === 'odp') ? '' : ($data['ip'] ?? ''),
    'lat'       => (float)$data['lat'],
    'lng'       => (float)$data['lng'],
    'line'      => $data['line'] ?: null,
    'lineColor' => $data['lineColor'] ?? 'lime',
    'icon'      => $apList[$idx]['icon'] ?? 'wifi',
    'type'      => $apType
];

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

if ($API->connect($_SESSION['ip'],$_SESSION['user'],$_SESSION['pass'])){

    try {
        // ============================================================================
        // 🔥 JIKA TYPE BERUBAH DARI WIFI KE ODP - HAPUS NETWATCH & IP-BINDING LAMA
        // ============================================================================
        if($oldType === 'wifi' && $apType === 'odp' && !empty($oldIp)) {
            // Hapus netwatch lama
            $oldNetwatch = $API->comm('/tool/netwatch/print', ['?host' => $oldIp]);
            checkMikrotikError($oldNetwatch);
            
            if(!empty($oldNetwatch[0]['.id'])) {
                $removeOldNetwatch = $API->comm('/tool/netwatch/remove', ['.id' => $oldNetwatch[0]['.id']]);
                checkMikrotikError($removeOldNetwatch);
            }
            
            // Hapus ip-binding lama
            $oldBinding = $API->comm('/ip/hotspot/ip-binding/print', ['?address' => $oldIp]);
            checkMikrotikError($oldBinding);
            
            if(!empty($oldBinding[0]['.id'])) {
                $removeOldBinding = $API->comm('/ip/hotspot/ip-binding/remove', ['.id' => $oldBinding[0]['.id']]);
                checkMikrotikError($removeOldBinding);
            }
        }

        // ============================================================================
        // 🔥 JIKA TYPE BERUBAH DARI ODP KE WIFI - HAPUS CERTIFICATE LAMA
        // ============================================================================
        if($oldType === 'odp' && $apType === 'wifi') {
            // Hapus certificate lama
            $oldCerts = $API->comm('/certificate/print');
            checkMikrotikError($oldCerts);
            
            $foundOldCert = null;
            foreach ($oldCerts as $cert) {
                $certName = $cert['name'] ?? '';
                if (strpos($certName, $data['id'] . '#') === 0) {
                    $foundOldCert = $cert;
                    break;
                }
            }
            
            if ($foundOldCert && !empty($foundOldCert['.id'])) {
                $removeOldCert = $API->comm('/certificate/remove', ['.id' => $foundOldCert['.id']]);
                checkMikrotikError($removeOldCert);
            }
        }

        if ($apType === 'wifi') {
            // ===== WIFI LOGIC =====
            $parentToken = null;
            if (!empty($data['line'])) {
                $foundParentName = null;
                foreach ($apList as $existingAp) {
                    if (isset($existingAp['id']) && $existingAp['id'] === $data['line']) {
                        $foundParentName = trim($existingAp['name']);
                        break;
                    }
                }
                $parentToken = $foundParentName ?: $data['line'];
            }

            if ($parentToken) {
                $commentValue = $data['name']."//".$data['lineColor']."/".$parentToken."//".$data['lat'].",".$data['lng'];
            } else {
                $commentValue = $data['name']."//".$data['lineColor']."///".$data['lat'].",".$data['lng'];
            }

            if ($oldIp !== $data['ip']) {
                $entries = $API->comm('/tool/netwatch/print',['?host'=>$oldIp]);
                checkMikrotikError($entries);
                
                if(!empty($entries[0]['.id'])){
                    $removeResult = $API->comm('/tool/netwatch/remove', ['.id' => $entries[0]['.id']]);
                    checkMikrotikError($removeResult);
                }
                
                $addResult = $API->comm('/tool/netwatch/add',[
                    'host'     => $data['ip'],
                    'comment'  => $commentValue,
                    'interval' => '10s',
                    'timeout'  => '5s'
                ]);
                checkMikrotikError($addResult);
            } else {
                $entries = $API->comm('/tool/netwatch/print',['?host'=>$data['ip']]);
                checkMikrotikError($entries);
                
                if(!empty($entries[0]['.id'])){
                    $setResult = $API->comm('/tool/netwatch/set', [
                        '.id'     => $entries[0]['.id'],
                        'comment' => $commentValue,
                        'interval' => '10s',
                        'timeout'  => '5s'
                    ]);
                    checkMikrotikError($setResult);
                }
            }

            if ($oldIp !== $data['ip']) {
                $binding = $API->comm('/ip/hotspot/ip-binding/print',['?address'=>$oldIp]);
                checkMikrotikError($binding);
                
                if(!empty($binding[0]['.id'])){
                    $removeBindingResult = $API->comm('/ip/hotspot/ip-binding/remove',['.id' => $binding[0]['.id']]);
                    checkMikrotikError($removeBindingResult);
                }
                
                $addBindingResult = $API->comm('/ip/hotspot/ip-binding/add',[
                    'address'   => $data['ip'],
                    'to-address'=> $data['ip'],
                    'comment'   => $commentValue,
                    'type'      => 'bypassed'
                ]);
                checkMikrotikError($addBindingResult);
            } else {
                $binding = $API->comm('/ip/hotspot/ip-binding/print',['?address'=>$data['ip']]);
                checkMikrotikError($binding);
                
                if(!empty($binding[0]['.id'])){
                    $setBindingResult = $API->comm('/ip/hotspot/ip-binding/set',[
                        '.id'       => $binding[0]['.id'],
                        'comment'   => $commentValue,
                        'type'      => 'bypassed'
                    ]);
                    checkMikrotikError($setBindingResult);
                }
            }

            $mikrotikOk = true;

        } else if ($apType === 'odp') {
            // ===== ODP CERTIFICATE LOGIC =====
            
            $parentName = '';
            if (!empty($data['line'])) {
                foreach ($apList as $existingAp) {
                    if (isset($existingAp['id']) && $existingAp['id'] === $data['line']) {
                        $parentName = trim($existingAp['name']);
                        break;
                    }
                }
                if (empty($parentName)) {
                    $parentName = $data['line'];
                }
            }

            // Hapus cert lama (jika ada)
            $oldCerts = $API->comm('/certificate/print');
            checkMikrotikError($oldCerts);
            
            $foundCert = null;
            foreach ($oldCerts as $cert) {
                $certName = $cert['name'] ?? '';
                if (strpos($certName, $data['id'] . '#') === 0) {
                    $foundCert = $cert;
                    break;
                }
            }

            if ($foundCert && !empty($foundCert['.id'])) {
                $removeResult = $API->comm('/certificate/remove', ['.id' => $foundCert['.id']]);
                checkMikrotikError($removeResult);
            }

            // Format: ID#LAT#LNG#COLOR#PARENT#NAMA atau ID#LAT#LNG#COLOR#NAMA
            if (!empty($parentName)) {
                $certName = $data['id'] . '#' . (float)$data['lat'] . '#' . (float)$data['lng'] . '#' . $data['lineColor'] . '#' . $parentName . '#' . $data['name'];
            } else {
                $certName = $data['id'] . '#' . (float)$data['lat'] . '#' . (float)$data['lng'] . '#' . $data['lineColor'] . '#' . $data['name'];
            }

            // Buat cert baru
            $addResult = $API->comm('/certificate/add', [
                'name' => $certName,
                'common-name' => $data['name'],
                'days-valid' => 3650
            ]);
            checkMikrotikError($addResult);

            $mikrotikOk = true;
        }

        // ============================================================================
        // 🔥 UPDATE CHILD AP (WIFI & ODP) YANG MEREFERENSI PARENT INI
        // ============================================================================
        if($oldName !== $data['name']) {
            foreach($apList as $childIdx => $childAp) {
                // Cek apakah child ini mereferensi parent yang sedang diedit
                if(!empty($childAp['line']) && $childAp['line'] === $data['id']) {
                    
                    if($childAp['type'] === 'wifi') {
                        // ===== UPDATE CHILD WIFI =====
                        $childComment = $childAp['name']."//".$childAp['lineColor']."/".$data['name']."//".$childAp['lat'].",".$childAp['lng'];
                        
                        // Update netwatch
                        $childNetwatch = $API->comm('/tool/netwatch/print', ['?host' => $childAp['ip']]);
                        checkMikrotikError($childNetwatch);
                        
                        if(!empty($childNetwatch[0]['.id'])) {
                            $updateChildNetwatch = $API->comm('/tool/netwatch/set', [
                                '.id'     => $childNetwatch[0]['.id'],
                                'comment' => $childComment
                            ]);
                            checkMikrotikError($updateChildNetwatch);
                        }
                        
                        // Update ip-binding
                        $childBinding = $API->comm('/ip/hotspot/ip-binding/print', ['?address' => $childAp['ip']]);
                        checkMikrotikError($childBinding);
                        
                        if(!empty($childBinding[0]['.id'])) {
                            $updateChildBinding = $API->comm('/ip/hotspot/ip-binding/set', [
                                '.id'     => $childBinding[0]['.id'],
                                'comment' => $childComment
                            ]);
                            checkMikrotikError($updateChildBinding);
                        }
                        
                    } else if($childAp['type'] === 'odp') {
                        // ===== UPDATE CHILD ODP =====
                        // Hapus cert child lama dan buat baru dengan nama parent yang updated
                        
                        // Cari dan hapus cert child lama
                        $oldChildCerts = $API->comm('/certificate/print');
                        checkMikrotikError($oldChildCerts);
                        
                        $foundChildCert = null;
                        foreach ($oldChildCerts as $cert) {
                            $certName = $cert['name'] ?? '';
                            if (strpos($certName, $childAp['id'] . '#') === 0) {
                                $foundChildCert = $cert;
                                break;
                            }
                        }
                        
                        if ($foundChildCert && !empty($foundChildCert['.id'])) {
                            $removeChildCert = $API->comm('/certificate/remove', ['.id' => $foundChildCert['.id']]);
                            checkMikrotikError($removeChildCert);
                        }
                        
                        // Buat cert child baru dengan nama parent yang sudah diupdate
                        // Format: ID#LAT#LNG#COLOR#PARENT(NAMA BARU)#NAMA_CHILD
                        $newChildCertName = $childAp['id'] . '#' . (float)$childAp['lat'] . '#' . (float)$childAp['lng'] . '#' . $childAp['lineColor'] . '#' . $data['name'] . '#' . $childAp['name'];
                        
                        $addChildCert = $API->comm('/certificate/add', [
                            'name' => $newChildCertName,
                            'common-name' => $childAp['name'],
                            'days-valid' => 3650
                        ]);
                        checkMikrotikError($addChildCert);
                    }
                }
            }
        }

        // ============================================================================
        // 🔥 UPDATE CERTIFICATE DI MIKROTIK - GANTI SEMUA YANG BERISI NAMA LAMA
        // ============================================================================
        if($oldName !== $data['name']) {
            $allCerts = $API->comm('/certificate/print');
            checkMikrotikError($allCerts);
            
            foreach ($allCerts as $cert) {
                $certName = $cert['name'] ?? '';
                // Cek apakah certificate ini mengandung nama lama (tapi bukan cert yang sedang diedit)
                if (strpos($certName, '#') !== false && strpos($certName, $oldName) !== false && strpos($certName, $data['id'] . '#') !== 0) {
                    // Ganti nama lama dengan nama baru di semua posisi
                    $newCertName = str_replace('#' . $oldName . '#', '#' . $data['name'] . '#', $certName);
                    
                    // Jika nama lama ada di akhir
                    if (substr($certName, -strlen($oldName)) === $oldName) {
                        $newCertName = str_replace($oldName, $data['name'], $certName);
                    }
                    
                    // Hanya update jika nama benar-benar berubah
                    if ($newCertName !== $certName) {
                        // Hapus cert lama
                        $removeCert = $API->comm('/certificate/remove', ['.id' => $cert['.id']]);
                        checkMikrotikError($removeCert);
                        
                        // Buat cert baru dengan nama yang sudah diupdate
                        $addNewCert = $API->comm('/certificate/add', [
                            'name' => $newCertName,
                            'common-name' => $cert['common-name'] ?? '',
                            'days-valid' => 3650
                        ]);
                        checkMikrotikError($addNewCert);
                    }
                }
            }
        }

    } catch (Exception $e) {
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
    echo json_encode(['success'=>false,'error'=>'failed_save']); 
    exit;
}

echo json_encode([
    'success'        => true,
    'ap'             => $apList[$idx],
    'mikrotik'       => 'ok',
    'hotspotBinding' => 'ok'
]);
?>
