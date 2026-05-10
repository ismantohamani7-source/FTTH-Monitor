<?php
session_start();
require 'routeros_api.class.php';

// harus login
if (!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])) {
    die("Belum login.");
}

// ambil ID dari query string
$id = isset($_GET['id']) ? $_GET['id'] : '';
if ($id === '') {
    die("ID AP tidak ditemukan.");
}

// tentukan file JSON per router
$mt_ip = $_SESSION['ip'];
$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/','_',$mt_ip);
$dataFile = __DIR__ . "/ap_data_{$safe_ip}.json";

if(!file_exists($dataFile)) die("File data AP tidak ditemukan.");

$apList = json_decode(file_get_contents($dataFile), true) ?: [];

// cari AP by id
$foundKey = null;
$apName = '';
foreach($apList as $k => $ap){
    if(isset($ap['id']) && $ap['id'] === $id){
        $foundKey = $k;
        $apName = $ap['name'] ?? 'Unknown';
        break;
    }
}
if($foundKey === null) die("AP tidak ditemukan.");

$ap = $apList[$foundKey];
$apType = $ap['type'] ?? 'wifi';
$ip = $ap['ip'] ?? '';

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

// --- TRY HAPUS DARI MIKROTIK DULU ---
$mikrotikOk = false;
$mikrotikError = '';

$API = new RouterosAPI();
$API->debug = false;

if($API->connect($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])){
    
    try {
        if ($apType === 'wifi') {
            // ===== HAPUS WIFI DATA =====
            if($ip){
                // Netwatch
                $entries = $API->comm("/tool/netwatch/print", ["?host" => $ip]);
                checkMikrotikError($entries);
                
                foreach($entries as $e){
                    if(!empty($e['.id'])){
                        $removeResult = $API->comm("/tool/netwatch/remove", [".id"=>$e['.id']]);
                        checkMikrotikError($removeResult);
                    }
                }

                // IP Binding Hotspot
                $bindings = $API->comm("/ip/hotspot/ip-binding/print", ["?address"=>$ip]);
                checkMikrotikError($bindings);
                
                foreach($bindings as $b){
                    if(!empty($b['.id'])){
                        $removeBindingResult = $API->comm("/ip/hotspot/ip-binding/remove", [".id"=>$b['.id']]);
                        checkMikrotikError($removeBindingResult);
                    }
                }
            }
            
            $mikrotikOk = true;
            
        } else if ($apType === 'odp') {
            // ===== HAPUS ODP CERTIFICATE =====
            $allCerts = $API->comm('/certificate/print');
            checkMikrotikError($allCerts);
            
            foreach ($allCerts as $cert) {
                $certName = $cert['name'] ?? '';
                
                if (strpos($certName, $id . '#') === 0) {
                    if (!empty($cert['.id'])) {
                        $removeResult = $API->comm('/certificate/remove', ['.id' => $cert['.id']]);
                        checkMikrotikError($removeResult);
                    }
                }
            }
            
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

// --- HANYA HAPUS DARI JSON JIKA MIKROTIK BERHASIL ---
if(!$mikrotikOk){
    $_SESSION['delete_message'] = "❌ Gagal menghapus AP \"$apName\": $mikrotikError";
    $_SESSION['delete_status'] = 'error';
    header("Location: peta.php");
    exit;
}

// Hapus dari array dan simpan kembali
unset($apList[$foundKey]);
$apList = array_values($apList);

if(false === file_put_contents($dataFile, json_encode($apList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))){
    $_SESSION['delete_message'] = "❌ Gagal menyimpan perubahan JSON untuk AP \"$apName\"";
    $_SESSION['delete_status'] = 'error';
    header("Location: peta.php");
    exit;
}

// ✅ SUCCESS
$_SESSION['delete_message'] = "✅ AP \"$apName\" berhasil dihapus!";
$_SESSION['delete_status'] = 'success';

// redirect kembali ke peta
header("Location: peta.php");
exit;
?>
