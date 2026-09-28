<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

$userid = trim($_POST['userid'] ?? '');
$password = $_POST['password'] ?? '';

if ($userid === '' || $password === '') {
    $_SESSION['login_error'] = 'User ID dan password wajib diisi.';
    header('Location: ../login.php');
    exit;
}

$pdo = getConnection();
$user = findCrfUserByUserid($pdo, $userid);

if (!$user || !verifyCrfUserPassword($password, $user)) {
    $_SESSION['login_error'] = 'User ID atau password salah.';
    header('Location: ../login.php');
    exit;
}

// Buat session login baru.
session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['active_user'] = $user;

switch (getCrfRole()) {
    case 'admin':
        header('Location: ../admin/dashboard.php');
        break;
    case 'cmo':
        header('Location: ../cmo/dashboard.php');
        break;
    case 'otomasi':
        header('Location: ../otomasi/dashboard.php');
        break;
    case 'kadep_operasional':
        header('Location: ../pak_joko/dashboard.php');
        break;
    default:
        header('Location: ../user/pengajuan_saya.php');
        break;
}

exit;