<?php
require_once __DIR__ . '/includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

switch (getCrfRole()) {
    case 'admin':
        header('Location: admin/dashboard.php');
        break;
    case 'cmo':
        header('Location: cmo/dashboard.php');
        break;
    case 'otomasi':
        header('Location: otomasi/dashboard.php');
        break;
    case 'kadep_operasional':
        header('Location: pak_joko/dashboard.php');
        break;
    default:
        header('Location: user/pengajuan_saya.php');
        break;
}
exit;