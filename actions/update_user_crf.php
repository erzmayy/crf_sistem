<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user/pengajuan_saya.php');
    exit;
}

verifyCsrf();

$id = (int) ($_POST['id'] ?? 0);
$_SESSION['flash'] = [
    'type' => 'warning',
    'message' => 'Implementasi diisi oleh Otomasi. Post Implementation Review diisi Pemohon setelah implementasi dicatat.'
];

header(
    $id > 0
        ? 'Location: ../user/detail.php?id=' . $id
        : 'Location: ../user/pengajuan_saya.php'
);
exit;
