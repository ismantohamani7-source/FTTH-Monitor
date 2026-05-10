<?php
session_start();
require('routeros_api.class.php');

$dataFile = __DIR__ . '/mikrotik_list.json';
if (!file_exists($dataFile)) {
    $_SESSION['toast_error'] = "File data tidak ditemukan";
    header("Location: index.php");
    exit;
}
$mikrotikList = json_decode(file_get_contents($dataFile), true);

if (!isset($_GET['id']) || !isset($mikrotikList[$_GET['id']])) {
    $_SESSION['toast_error'] = "Data Mikrotik tidak ditemukan";
    header("Location: index.php");
    exit;
}

$mt = $mikrotikList[$_GET['id']];

// Parse IP dan Port dari format "host:port"
$hostport = $mt['ip'];
$host = $hostport;
$port = 8728;

if (strpos($hostport, ':') !== false) {
    list($host, $port) = explode(':', $hostport, 2);
    $port = intval($port);
}

$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 5;

// Coba koneksi plain API dulu
$connected = $API->connect($host, $mt['user'], $mt['pass'], $port, false);

// Jika gagal, coba dengan SSL
if (!$connected) {
    $API = new RouterosAPI();
    $API->debug = false;
    $API->timeout = 5;
    $connected = $API->connect($host, $mt['user'], $mt['pass'], $port, true);
}

if ($connected) {
    $_SESSION['ip']   = $mt['ip'];
    $_SESSION['user'] = $mt['user'];
    $_SESSION['pass'] = $mt['pass'];

    $API->disconnect();
    header("Location: peta.php");
    exit;
} else {
    $_SESSION['toast_error'] = "Gagal terhubung ke Mikrotik ({$mt['ip']}) - Cek IP, port, username dan password";
    header("Location: index.php");
    exit;
}
?>
