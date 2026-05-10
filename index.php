<?php
session_start();

// ===== FILE PATHS =====
$adminFile = __DIR__ . '/admin.json';
$expireFile = __DIR__ . '/expire.json';

// ===== LOGOUT =====
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location:index.php");
    exit;
}

// ===== ADMIN DEFAULT =====
if (!file_exists($adminFile)) {
    file_put_contents($adminFile, json_encode(['user' => 'APMON', 'pass' => '1234'], JSON_PRETTY_PRINT), LOCK_EX);
}
$adminData = json_decode(file_get_contents($adminFile), true);
if (!is_array($adminData)) $adminData = ['user' => 'APMON', 'pass' => '1234'];

// ===== EXPIRE: read safely from expire.json (if present) =====
$expire_at = 0;
$expire_exists = file_exists($expireFile);
if ($expire_exists) {
    $raw = file_get_contents($expireFile);
    $ed = json_decode($raw, true);
    if (is_array($ed) && isset($ed['expires_at'])) {
        $expire_at = intval($ed['expires_at']);
    } else {
        // invalid JSON -> treat as no expiry (do not break)
        error_log("[expire.json] invalid content or missing expires_at");
        $expire_at = 0;
    }
}

// PROSES LOGIN
$error = '';
if (isset($_POST['login'])) {
    $inputUser = $_POST['username'] ?? '';
    $inputPass = $_POST['password'] ?? '';
    if ($inputUser === ($adminData['user'] ?? '') && $inputPass === ($adminData['pass'] ?? '')) {
        $_SESSION['loggedin'] = true;
        // save expire timestamp to session for quick access
        $_SESSION['subscription_expires_at'] = $expire_at;
    } else {
        $error = 'Username atau password salah!';
    }
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true):
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Netwatch AP Monitoring</title>
<link rel="icon" href="favicon.png" />
<link rel="stylesheet" href="admin.css">
</head>
<body class="dashboard">
<main>
    <div class="panel" style="max-width:360px;margin:auto;">
        <h3>🔐 Admin Login</h3>
        <?php if ($error) echo "<p class='msg' style='color:#ff5555;'>" . htmlspecialchars($error) . "</p>"; ?>
        <form method="post" style="display:flex;flex-direction:column;gap:15px;">
            <input type="text" name="username" placeholder="👤 Username" required>
            <input type="password" name="password" placeholder="🔑 Password" required>
            <div class="login">
                <center><button type="submit" name="login" style="width:250px;"><i class="fas fa-sign-in-alt"></i> Login</button></center>
            </div>
        </form>
    </div>
</main>
</body>
</html>
<?php exit; endif; ?>

<?php
// ===== MIKROTIK DATA =====
$dataFile = __DIR__ . '/mikrotik_list.json';
if (!file_exists($dataFile)) file_put_contents($dataFile, json_encode([], JSON_PRETTY_PRINT), LOCK_EX);
$mikrotikList = json_decode(file_get_contents($dataFile), true);
if (!is_array($mikrotikList)) $mikrotikList = [];

// Tambah Mikrotik
if (isset($_POST['add_mikrotik'])) {
    $mikrotikList[] = [
        'name' => ($_POST['name'] ?? ''),
        'ip'   => ($_POST['ip'] ?? ''),
        'user' => ($_POST['user'] ?? ''),
        'pass' => ($_POST['pass'] ?? '')
    ];
    file_put_contents($dataFile, json_encode($mikrotikList, JSON_PRETTY_PRINT), LOCK_EX);
    header("Location:index.php");
    exit;
}

// Edit admin (+ optional create expire.json if it doesn't exist)
$adminMsg = '';
if (isset($_POST['edit_admin'])) {
    $adminData['user'] = $_POST['new_user'] ?? ($adminData['user'] ?? '');
    $adminData['pass'] = $_POST['new_pass'] ?? ($adminData['pass'] ?? '');
    // save admin.json
    file_put_contents($adminFile, json_encode($adminData, JSON_PRETTY_PRINT), LOCK_EX);

    // Only allow creating expire.json if it does NOT exist (per request)
    if (!$expire_exists && !empty($_POST['expire_date'])) {
        $d = $_POST['expire_date']; // format YYYY-MM-DD
        $ts = strtotime($d . ' 23:59:59');
        if ($ts !== false) {
            $expireData = ['expires_at' => $ts];
            $written = @file_put_contents($expireFile, json_encode($expireData, JSON_PRETTY_PRINT), LOCK_EX);
            if ($written === false) {
                error_log("Failed to write expire.json - check permissions");
                $adminMsg = "⚠️ Pengaturan disimpan, tapi gagal membuat expire.json (cek permission).";
            } else {
                $expire_at = $ts;
                $expire_exists = true;
                if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
                    $_SESSION['subscription_expires_at'] = $expire_at;
                }
                $adminMsg = "✅ Pengaturan dan tanggal kadaluarsa berhasil dibuat!";
            }
        } else {
            $adminMsg = "⚠️ Format tanggal tidak valid.";
        }
    } else {
        if ($adminMsg === '') $adminMsg = "✅ Pengaturan berhasil disimpan!";
    }
}

// ===== SETTINGS (auto-reload) =====
$settingsFile = __DIR__ . '/settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode(['reload_minutes' => 0], JSON_PRETTY_PRINT), LOCK_EX);
}
$settings = json_decode(file_get_contents($settingsFile), true);
if (!is_array($settings)) $settings = ['reload_minutes' => 0];

// Simpan pilihan reload dari index.php
if (isset($_POST['set_reload'])) {
    $allowed = [0,1,5,10,20,30,40,50];
    $val = intval($_POST['reload_minutes'] ?? 0);
    if (!in_array($val, $allowed)) $val = 0;
    $settings['reload_minutes'] = $val;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX);
    header("Location:index.php");
    exit;
}

// Jika session belum punya info expires -> set dari file (jika ada)
if (isset($_SESSION['loggedin']) && (!isset($_SESSION['subscription_expires_at']) || $_SESSION['subscription_expires_at'] === null)) {
    $_SESSION['subscription_expires_at'] = $expire_at;
}

// Cek expired: jika expire_at ada (>0) dan waktu sekarang > expire_at => expired
$subscription_expires_at = isset($_SESSION['subscription_expires_at']) ? intval($_SESSION['subscription_expires_at']) : 0;
$expired = ($subscription_expires_at > 0) && (time() > $subscription_expires_at);

// Remaining seconds for JS (only set if > 0 to avoid immediate reload loops)
$remaining = ($subscription_expires_at > 0) ? max(0, $subscription_expires_at - time()) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitoring AP</title>
<link rel="icon" href="favicon.png" />
<link rel="stylesheet" href="admin.css">
<style>
/* small header session text */
.header-row {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding: 10px 16px;
}
.header-title {
    font-size:1.25rem;
    font-weight:600;
}
.header-session {
    font-size:0.85rem;
    color:#666;
    text-align:right;
}
.header-session strong {
    display:block;
    font-size:0.95rem;
    color:#222;
}
</style>
</head>
<body class="dashboard">

<header class="header-row">
    <div class="header-title">Monitoring AP</div>
    <div class="header-session">
        <div style="font-size:0.85rem;color:#666;">Sesi berakhir</div>
        <div id="subscription-top" <?= $remaining > 0 ? 'data-seconds="'.$remaining.'"' : '' ?> style="font-weight:600;color:#333;">
            <?= $remaining > 0 ? gmdate("H:i:s", $remaining) : ($subscription_expires_at > 0 ? '00:00:00' : 'Belum diatur') ?>
        </div>
    </div>
</header>

<main>
<?php if (isset($_GET['action']) && $_GET['action'] === 'add'): ?>

<div class="panel">
    <h3>➕ Tambah Mikrotik</h3>

    <form method="post" style="display:flex;flex-direction:column;gap:15px;">
        <input type="text" name="name" placeholder="🌐 Nama Mikrotik" required>
        <input type="text" name="ip" placeholder="🔗 IP / Host VPN API" required>
        <input type="text" name="user" placeholder="👤 Username" required>
        <input type="password" name="pass" placeholder="🔑 Password" required>

        <div class="actions">
            <button type="submit" name="add_mikrotik">
                <i class="fas fa-save"></i> Simpan
            </button>
            <a class="batal" href="index.php">
                <i class="fas fa-times"></i> Batal
            </a>
        </div>
    </form>
</div>

<?php return; endif; ?>

<?php
// Jika subscription kadaluarsa -> tampilkan halaman expire (ajakan hubungi WA)
if ($expired):
?>
<div class="panel" style="max-width:720px;margin:30px auto;text-align:center;">
    <h2 style="margin-bottom:6px;">🔒 Expired</h2>
    <p style="color:#fff;font-size:1.05em;">Maaf, masa berlangganan untuk akun ini telah berakhir pada <strong><?= date('Y-m-d H:i:s', $subscription_expires_at) ?></strong>.</p>
    <p style="color:#666;margin-top:6px;">Untuk melanjutkan penggunaan dan membuka kembali fitur monitoring (daftar MikroTik), silakan hubungi penjual/administrator.</p>
    <div style="margin-top:18px;">
        <a href="https://wa.me/6285242775539?text=Halo%20saya%20ingin%20memperpanjang%20Langganan%20Monitoring%20AP" target="_blank"
           style="display:inline-block;padding:12px 18px;background:#25D366;color:white;border-radius:8px;text-decoration:none;font-weight:bold;">
           Hubungi lewat WhatsApp: 0852-4277-5539
        </a>
    </div>
    <div style="margin-top:18px;">
        <form method="post" style="display:inline-block;margin-right:8px;">
            <button type="submit" name="logout" style="padding:8px 14px;border-radius:6px;background:#e53935;color:#fff;border:none;cursor:pointer;">Logout</button>
        </form>
    </div>
</div>

<?php
// Jangan tampilkan daftar Mikrotik di bawahnya — exit setelah pesan
exit;
endif;
?>

<?php
if (isset($_SESSION['toast_error'])): ?>
<div class="toast"><?= htmlspecialchars($_SESSION['toast_error']) ?></div>
<style>
.toast {
    background-color: #f44336;
    color: white;
    padding: 10px 15px;
    border-radius: 5px;
    margin-bottom: 15px;
    font-size: 0.95em;
    animation: fadein 0.5s, fadeout 0.5s 4s;
}
@keyframes fadein { from {opacity: 0; transform: translateY(-10px);} to {opacity: 1; transform: translateY(0);} }
@keyframes fadeout { from {opacity: 1;} to {opacity: 0;} }
</style>
<script>
setTimeout(() => {
    const toast = document.querySelector('.toast');
    if (toast) toast.remove();
}, 4500);
</script>
<?php
unset($_SESSION['toast_error']);
endif;
?>

<!-- Panel Daftar Mikrotik -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h3>📡 MikroTik</h3>
        <div style="display:flex; gap:10px;">
            <!-- Tombol Add Mikrotik -->
            <form method="get" action="" style="margin:0;">
                <button type="submit" name="action" value="add"
                    style="padding:5px 12px; font-size:0.9em; border-radius:6px; cursor:pointer; background:#26a69a; color:white; border:none; display:flex; align-items:center;">
                    <i class="fas fa-plus-circle" style="margin-right:5px;"></i> Add
                </button>
            </form>

            <!-- Tombol Logout -->
            <form method="post" style="margin:0;">
                <button type="submit" name="logout"
                    style="padding:5px 12px; font-size:0.9em; border-radius:6px; cursor:pointer; background:#e53935; color:white; border:none; display:flex; align-items:center;">
                    Logout
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($mikrotikList)): ?>
        <?php foreach ($mikrotikList as $index => $mt): ?>
            <div class="card">
                <div class="info"><?= htmlspecialchars($mt['name']) ?></div>
                <div class="actions">
                    <a class="connect" href="connect.php?id=<?= $index ?>"><i class="fas fa-plug"></i> Buka</a>
                    <a class="edit" href="edit.php?id=<?= $index ?>"><i class="fas fa-pen"></i> Edit</a>
                    <a class="delete" href="delete.php?id=<?= $index ?>" onclick="return confirm('Hapus Mikrotik ini?')"><i class="fas fa-trash"></i> Hapus</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="color:#00796b;">⚠️ Belum ada data Mikrotik.</p>
    <?php endif; ?>
</div>

<!-- Panel Auto-reload (letak setelah daftar Mikrotik) -->
<div class="panel">
    <h3>⏱️ Auto-reload Peta</h3>
    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <label for="reload_minutes" style="min-width:90px;">Reload tiap</label>
        <select name="reload_minutes" id="reload_minutes" style="padding:6px;border-radius:6px;">
            <?php
            $options = [
                0 => 'Disabled',
                1 => '1 menit',
                5 => '5 menit',
                10 => '10 menit',
                20 => '20 menit',
                30 => '30 menit',
                40 => '40 menit',
                50 => '50 menit'
            ];
            foreach ($options as $val => $label):
            ?>
                <option value="<?= $val ?>" <?= (isset($settings['reload_minutes']) && intval($settings['reload_minutes']) === $val) ? 'selected' : '' ?>>
                    <?= $label ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="set_reload" style="padding:6px 12px;border-radius:6px;background:#26a69a;color:#fff;border:none;cursor:pointer;">
            Simpan
        </button>
    </form>
    <p style="font-size:0.9em;color:#555;margin-top:8px;">Pengaturan ini akan diterapkan pada halaman Monitoring. Pilih 0/Disabled untuk mematikan auto-reload.</p>
</div>

<!-- Panel Admin Settings -->
<div class="panel" >
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3>⚙️ Admin Setting</h3>
        <button id="toggle-admin" style="padding:5px 10px; font-size:0.9em; border-radius:6px; cursor:pointer; background:#26a69a; color:white; border:none;">
            Show/Hide
        </button>
    </div>

    <div id="admin-panel" style="display:none; margin-top:15px;">
        <?php if ($adminMsg) echo "<p class='msg'>" . htmlspecialchars($adminMsg) . "</p>"; ?>
        <form method="post" style="display:flex;flex-direction:column;gap:15px;">
            <input type="text" name="new_user" placeholder="Username" value="<?= htmlspecialchars($adminData['user']) ?>" required>
            <input type="password" name="new_pass" placeholder="Password" value="<?= htmlspecialchars($adminData['pass']) ?>" required>

            <!-- show the expire_date input ONLY if expire.json does NOT exist -->
            <?php if (!$expire_exists): ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <label for="expire_date" style="min-width:140px;margin:0;">Tanggal kadaluarsa :</label>
                <input type="date" id="expire_date" name="expire_date" value="" style="padding:6px;border-radius:6px;">
            </div>
            <?php endif; ?>

            <button type="submit" name="edit_admin"><i class="fas fa-save"></i> Simpan</button>
        </form>
        <?php if ($expire_exists): ?>
            <p style="margin-top:10px;color:#666;font-size:0.9em;">Tanggal kadaluarsa saat ini: <strong><?= $expire_at > 0 ? date('Y-m-d', $expire_at) : 'Belum diatur' ?></strong></p>
        <?php endif; ?>
    </div>
</div>

</main>
</body>
<script>
// Toggle panel admin
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('toggle-admin');
    const adminPanel = document.getElementById('admin-panel');

    toggleBtn.addEventListener('click', () => {
        if (adminPanel.style.display === "none" || adminPanel.style.display === "") {
            adminPanel.style.display = "block";
        } else {
            adminPanel.style.display = "none";
        }
    });

    // Countdown for top-right session display
    const topEl = document.getElementById('subscription-top');
    if (topEl) {
        const secondsAttr = topEl.getAttribute('data-seconds');
        const secondsInit = (secondsAttr && !isNaN(parseInt(secondsAttr,10))) ? parseInt(secondsAttr, 10) : null;
        if (secondsInit !== null && secondsInit > 0) {
            let seconds = secondsInit;
            const updateTop = () => {
                if (seconds <= 0) {
                    topEl.textContent = '00:00:00';
                    // reload so server will show expired page
                    window.location.reload();
                    return;
                }
                const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
                const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
                const s = String(seconds % 60).padStart(2, '0');
                topEl.textContent = `${h}:${m}:${s}`;
                seconds--;
            };
            updateTop();
            setInterval(updateTop, 1000);
        } else {
            // no expiry set — show 'Belum diatur' (already set by server) and do nothing
        }
    }
});
</script>
</html>