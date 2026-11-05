<?php
/**
 * actions/queue_counts.php
 * ---------------------------------------------------------------
 * Angka antrean CRF milik user yang sedang login, format JSON.
 * Dipakai skrip badge merah di menu SIAP (lihat siap_badge_crf.js).
 *
 * Hanya membaca. Tanpa login atau tanpa angka: { "ok": false } / semua 0.
 * Kunci hasil = nama submodul SIAP (sama dengan crfRoutes()):
 *   index             Verifikasi CMO (verifikasi + UAT + finalisasi)
 *   tindak_lanjut_otm Tindak Lanjut Otomasi
 *   kadep_operasional Persetujuan Kadep Operasional
 *   forum             komentar Forum belum dibaca
 * total = jumlah tiga antrean tindakan pertama (Forum tidak dihitung).
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crf_nav.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $pdo = getConnection();
    $userId = (int) $_SESSION['user_id'];

    $role = getCrfRole();
    $demo = $role === 'demo';
    $can = static fn(string $r): bool => $demo || $role === $r;

    $q = crfQueueCounts($pdo, $can('cmo'), $can('kadep_operasional'), $can('otomasi'));
    $forum = in_array($role, forumRoles(), true) ? forumUnreadTotal($pdo, $userId) : 0;

    $counts = [
        'index'             => $q['cmo'],
        'tindak_lanjut_otm' => $q['pic'],
        'kadep_operasional' => $q['kadep'],
        'forum'             => (int) $forum,
    ];

    echo json_encode([
        'ok'     => true,
        'total'  => $q['cmo'] + $q['pic'] + $q['kadep'],
        'counts' => $counts,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
