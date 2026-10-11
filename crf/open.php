<?php
/**
 * crf/open.php
 * Membuka detail CRF pada halaman yang sesuai dengan peran user
 * (dipakai tautan notifikasi).
 */
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getConnection();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, pengguna_id, status, tahap_alur, kategori_id FROM crf_pengajuan WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$crf = $stmt->fetch();

if (!$crf || !canAccessCrf($pdo, $id)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak ditemukan atau Anda tidak memiliki akses.'];
    header('Location: ../index.php');
    exit;
}

$role = getCrfRole();
$isOwner = (int) $crf['pengguna_id'] === (int) $_SESSION['user_id'];

if ($role === 'demo') {
    // Akun demo dibawa ke halaman peran yang sedang memegang CRF.
    $target = [
        'CMO_FILTER' => 'cmo/detail.php',
        'UAT' => 'cmo/detail.php',
        'PEMOHON_PIR' => 'cmo/detail.php',
        'CMO_FINAL' => 'cmo/detail.php',
        'OTOMASI' => 'otomasi/detail.php',
        'kadep_operasional' => 'kadep_operasional/detail.php',
    ][$crf['tahap_alur']] ?? 'admin/detail.php';
} elseif ($role === 'admin') {
    $target = 'admin/detail.php';
} elseif ($role === 'cmo') {
    $target = 'cmo/detail.php';
} elseif ($role === 'kadep_operasional') {
    $target = 'kadep_operasional/detail.php';
} elseif ($role === 'otomasi' && canHandleCrf($pdo, $crf)) {
    $target = $crf['tahap_alur'] === 'OTOMASI' ? 'otomasi/detail.php' : 'otomasi/view_detail.php';
} elseif (isAdmin()) {
    // Hak Admin tambahan: CRF di luar kategori yang ditangani dibuka di detail Admin.
    $target = 'admin/detail.php';
} else {
    $target = 'user/detail.php';
}

// Pemilik CRF yang juga punya peran lain tetap bisa membuka detail miliknya.
if ($isOwner && $crf['status'] === 'Draft') {
    $target = 'user/form_crf.php';
}

// Pemohon mengisi Post Implementation Review dari halaman detail miliknya.
if ($isOwner && $crf['tahap_alur'] === 'PEMOHON_PIR') {
    $target = 'user/detail.php';
}

header('Location: ../' . $target . '?id=' . $id);
exit;
