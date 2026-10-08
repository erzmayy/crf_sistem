<?php
require_once __DIR__ . '/includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

switch (getCrfRole()) {
    case 'admin':
    case 'demo':
        header('Location: admin/dashboard.php');
        break;
    case 'cmo':
        header('Location: cmo/index.php');
        break;
    case 'otomasi':
        header('Location: otomasi/index.php');
        break;
    case 'kadep_operasional':
        header('Location: pak_joko/index.php');
        break;
    default:
        // Helpdesk = pintu masuk seluruh permintaan.
        header('Location: helpdesk/form.php');
        break;
}
exit;