<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

session_start();

if (!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$cableFile = __DIR__ . '/cables.json';
if (!file_exists($cableFile)) {
    file_put_contents($cableFile, json_encode([]), LOCK_EX);
}

$cables = json_decode(file_get_contents($cableFile), true);
if (!is_array($cables)) {
    $cables = [];
}

if ($method === 'GET' && $action === 'list') {
    // 📋 GET: Tampilkan semua kabel
    echo json_encode(['ok' => true, 'cables' => array_values($cables)]);
    exit;
}

if ($method === 'POST' && $action === 'add') {
    // ➕ POST: Tambah kabel baru
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name']) || !isset($data['lat1']) || !isset($data['lng1']) || !isset($data['lat2']) || !isset($data['lng2'])) {
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        exit;
    }

    $id = 'cable_' . uniqid();
    $cables[$id] = [
        'id' => $id,
        'name' => $data['name'],
        'lat1' => floatval($data['lat1']),
        'lng1' => floatval($data['lng1']),
        'lat2' => floatval($data['lat2']),
        'lng2' => floatval($data['lng2']),
        'color' => $data['color'] ?? 'red',
        'width' => intval($data['width'] ?? 3),
        'created_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($cableFile, json_encode($cables, JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['ok' => true, 'cable' => $cables[$id]]);
    exit;
}

if ($method === 'POST' && $action === 'update') {
    // ✏️ POST: Update kabel
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id']) || !isset($cables[$data['id']])) {
        echo json_encode(['ok' => false, 'error' => 'Cable not found']);
        exit;
    }

    $cable = &$cables[$data['id']];
    $cable['name'] = $data['name'] ?? $cable['name'];
    $cable['lat1'] = isset($data['lat1']) ? floatval($data['lat1']) : $cable['lat1'];
    $cable['lng1'] = isset($data['lng1']) ? floatval($data['lng1']) : $cable['lng1'];
    $cable['lat2'] = isset($data['lat2']) ? floatval($data['lat2']) : $cable['lat2'];
    $cable['lng2'] = isset($data['lng2']) ? floatval($data['lng2']) : $cable['lng2'];
    $cable['color'] = $data['color'] ?? $cable['color'];
    $cable['width'] = isset($data['width']) ? intval($data['width']) : $cable['width'];
    $cable['updated_at'] = date('Y-m-d H:i:s');

    file_put_contents($cableFile, json_encode($cables, JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['ok' => true, 'cable' => $cable]);
    exit;
}

if ($method === 'DELETE' && $action === 'delete') {
    // 🗑️ DELETE: Hapus kabel
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id']) || !isset($cables[$data['id']])) {
        echo json_encode(['ok' => false, 'error' => 'Cable not found']);
        exit;
    }

    unset($cables[$data['id']]);
    file_put_contents($cableFile, json_encode($cables, JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['ok' => true]);
    exit;
}

// Default response
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid action']);
?>
