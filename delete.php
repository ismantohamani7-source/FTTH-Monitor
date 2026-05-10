<?php
session_start();

// ===== Cek Login =====
if(!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true){
    header("Location: index.php");
    exit;
}

$dataFile = __DIR__ . '/mikrotik_list.json';
if(!file_exists($dataFile)) file_put_contents($dataFile,json_encode([]));

$mikrotikList = json_decode(file_get_contents($dataFile), true);

// Ambil ID dari query string
$id = isset($_GET['id']) ? (int)$_GET['id'] : -1;

if($id >= 0 && isset($mikrotikList[$id])){
    // Hapus data
    array_splice($mikrotikList, $id, 1);
    file_put_contents($dataFile, json_encode($mikrotikList, JSON_PRETTY_PRINT));
}

// Kembali ke index.php
header("Location: index.php");
exit;
?>
