<?php
/**
 * Endpoint lama untuk pengisian Implementasi / Post Implementation Review oleh User.
 * Pengisian sekarang dilakukan oleh Otomasi sebelum eksekusi CRF diselesaikan.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$_SESSION['flash'] = [
    'type' => 'warning',
    'message' => 'Implementasi dan Post Implementation Review sekarang diisi oleh Otomasi.'
];

header('Location: ../user/detail.php?id=' . $id);
exit;
