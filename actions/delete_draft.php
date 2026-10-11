<?php
/**
 * actions/delete_draft.php
 * Menghapus draft milik user yang sedang login.
 * Hanya berlaku untuk CRF berstatus Draft.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user/pengajuan_saya.php');
    exit;
}

verifyCsrf();

$pdo  = getConnection();
$user = getCurrentUser();

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id
     FROM crf_pengajuan
     WHERE id = :id
       AND pengguna_id = :pengguna_id
       AND status = \'Draft\'
     LIMIT 1'
);

$stmt->execute([
    'id'      => $id,
    'pengguna_id' => $user['id'],
]);

$draft = $stmt->fetch();

if (!$draft) {
    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'Draft tidak ditemukan atau tidak dapat dihapus.',
    ];

    header('Location: ../user/pengajuan_saya.php');
    exit;
}

try {
    $del = $pdo->prepare(
        'DELETE FROM crf_pengajuan
         WHERE id = :id
           AND pengguna_id = :pengguna_id
           AND status = \'Draft\''
    );

    $del->execute([
        'id'      => $id,
        'pengguna_id' => $user['id'],
    ]);

    // Lampiran dan riwayat timeline ikut terhapus otomatis
    // (ON DELETE CASCADE), tetapi file di Wasabi tidak ikut terhapus.

    $_SESSION['flash'] = [
        'type'    => 'success',
        'message' => 'Draft berhasil dihapus.',
    ];

} catch (Throwable $e) {
    error_log('delete_draft error: ' . $e->getMessage());

    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'Gagal menghapus draft. Silakan coba lagi.',
    ];
}

header('Location: ../user/pengajuan_saya.php');
exit;