<?php
$dataFile = __DIR__ . '/mikrotik_list.json';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$id = intval($_GET['id']);
$mikrotikList = json_decode(file_get_contents($dataFile), true);

if (!isset($mikrotikList[$id])) {
    die("Data Mikrotik tidak ditemukan.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mikrotikList[$id]['name'] = $_POST['name'];
    $mikrotikList[$id]['ip']   = $_POST['ip'];
    $mikrotikList[$id]['user'] = $_POST['user'];
    $mikrotikList[$id]['pass'] = $_POST['pass'];

    file_put_contents($dataFile, json_encode($mikrotikList, JSON_PRETTY_PRINT));
    header("Location: index.php");
    exit;
}

$mt = $mikrotikList[$id];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Mikrotik</title>
<link rel="icon" href="favicon.png" />
<link rel="stylesheet" href="admin.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="dashboard">
<main>
    <div class="panel">
        <h3>✏️ Edit Mikrotik</h3>
        <form method="post" style="display:flex;flex-direction:column;gap:15px;">
            <input type="text" name="name" placeholder="🌐 Nama Mikrotik" value="<?= htmlspecialchars($mt['name']) ?>" required>
            <input type="text" name="ip" placeholder="🔗 IP / Host VPN API" value="<?= htmlspecialchars($mt['ip']) ?>" required>
            <input type="text" name="user" placeholder="👤 Username" value="<?= htmlspecialchars($mt['user']) ?>" required>
            <input type="password" name="pass" placeholder="🔑 Password" value="<?= htmlspecialchars($mt['pass']) ?>" required>
            <div class="actions">
                <button type="submit"><i class="fas fa-save"></i> Simpan</button>
                <a class="batal" href="index.php">⬅️ Kembali</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
