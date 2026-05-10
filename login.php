<?php
session_start();

// Ambil username & password default dari index.php
require 'index.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputUser = $_POST['username'] ?? '';
    $inputPass = $_POST['password'] ?? '';

    if ($inputUser === $adminUser && $inputPass === $adminPass) {
        $_SESSION['loggedin'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Username atau password salah!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login Admin</title>
<style>
    body {
        font-family: 'Segoe UI', sans-serif;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background: #f1f5f9;
        margin: 0;
    }
    form {
        background: white;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        width: 320px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    input {
        padding: 12px;
        border-radius: 8px;
        border: 1px solid #d1d5db;
    }
    button {
        padding: 12px;
        border: none;
        border-radius: 8px;
        background: #2563eb;
        color: white;
        font-weight: 600;
        cursor: pointer;
    }
    button:hover { background: #1d4ed8; }
    .error { color: red; text-align: center; }
</style>
</head>
<body>
<form method="post">
    <h2 style="text-align:center;">🔐 Login Admin</h2>
    <?php if ($error) echo "<p class='error'>$error</p>"; ?>
    <input type="text" name="username" placeholder="Username" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit">Login</button>
</form>
</body>
</html>
