<?php
/**
 * Halaman lama: ubah status CRF secara bebas oleh Admin.
 * Dinonaktifkan karena status bisa berubah tanpa mengikuti tahap workflow
 * (mis. "Selesai" padahal belum ada PIR). Gantinya:
 *   - Tugaskan ulang handler & pembatalan administratif di admin/detail.php
 *   - Level Urgensi / SLA final lewat Forum (CMO/Admin)
 * Dipertahankan agar tautan/bookmark lama diarahkan ke halaman detail.
 */
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();

$_SESSION['flash'] = [
    'type' => 'info',
    'message' => 'Halaman edit status sudah tidak digunakan. Gunakan Tindakan Admin di halaman detail CRF.',
];
header('Location: detail.php?id=' . (int) ($_GET['id'] ?? 0));
exit;
