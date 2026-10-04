<?php
/**
 * actions/update_crf.php (dinonaktifkan)
 * Dulu menyimpan perubahan status bebas dari admin/edit.php. Diganti
 * actions/admin_cancel_crf.php dan actions/crf_assign.php agar setiap
 * perubahan mengikuti workflow dan tercatat dengan alasan.
 */
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();
verifyCsrf();

$_SESSION['flash'] = [
    'type' => 'warning',
    'message' => 'Perubahan status langsung sudah tidak didukung. Gunakan Tindakan Admin di halaman detail CRF.',
];
header('Location: ../admin/detail.php?id=' . (int) ($_POST['id'] ?? 0));
exit;
