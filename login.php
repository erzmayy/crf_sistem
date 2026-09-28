<?php
require_once __DIR__ . '/includes/session.php';

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/auth.php';
    $role = getCrfRole();

    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: user/pengajuan_saya.php');
    }
    exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login - CRF PPU</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #EAF4FB;
            font-family: Arial, Helvetica, sans-serif;
        }

        .login-box {
            width: 380px;
            background: #fff;
            padding: 32px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .login-title {
            text-align: center;
            margin-bottom: 8px;
            color: #1f2a3d;
            font-size: 24px;
            font-weight: bold;
        }

        .login-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            color: #333;
        }

        .form-group input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid #D9D9D9;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .login-button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }

        .login-button:hover {
            background: #1d4ed8;
        }

        .login-error {
            margin-bottom: 18px;
            padding: 10px 12px;
            background: #fdecec;
            color: #b3261e;
            border-radius: 6px;
            font-size: 13px;
        }

        .login-info {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>

<body>

<div class="login-box">

    <div class="login-title">
        CRF PPU
    </div>

    <div class="login-subtitle">
        Change Request Form
    </div>

    <?php if ($error): ?>
        <div class="login-error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <form action="actions/login.php" method="POST">

        <div class="form-group">
            <label for="userid">User ID</label>
            <input
                type="text"
                id="userid"
                name="userid"
                required
                autocomplete="username"
            >
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                required
                autocomplete="current-password"
            >
        </div>

        <button type="submit" class="login-button">
            Login
        </button>

    </form>

    <div class="login-info">
        <strong>Akun demo:</strong><br>
        USER001 / password — Pemohon<br>
        ADMIN001 / password — Admin
    </div>

</div>

</body>
</html>