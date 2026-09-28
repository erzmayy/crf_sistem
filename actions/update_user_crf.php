<?php
/**
 * Menyimpan Implementasi / Hasil Perubahan dan
 * Post Implementation Review yang diisi Pemohon.
 *
 * Setelah keduanya diisi:
 * PEMOHON_PIR -> PAK_JOKO
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user/pengajuan_saya.php');
    exit;
}

verifyCsrf();

$user = getCurrentUser();
$pdo = getConnection();

$id = (int) ($_POST['id'] ?? 0);

$implementation = trim($_POST['implementation'] ?? '');
$pir = trim($_POST['post_implementation_review'] ?? '');


/*
 * Validasi input
 */
if ($id <= 0 || $implementation === '' || $pir === '') {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Implementasi / Hasil Perubahan dan Post Implementation Review wajib diisi.'
    ];

    header('Location: ../user/detail.php?id=' . $id);
    exit;
}


/*
 * Ambil CRF milik user yang sedang login
 */
$stmt = $pdo->prepare('
    SELECT id, status, workflow_stage
    FROM change_requests
    WHERE id = :id
      AND user_id = :user_id
    LIMIT 1
');

$stmt->execute([
    'id' => $id,
    'user_id' => $user['id']
]);

$crf = $stmt->fetch();


if (!$crf) {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Pengajuan CRF tidak ditemukan atau bukan milik Anda.'
    ];

    header('Location: ../user/pengajuan_saya.php');
    exit;
}


/*
 * Hanya boleh diisi pada tahap PEMOHON_PIR
 */
if ($crf['workflow_stage'] !== 'PEMOHON_PIR') {

    $_SESSION['flash'] = [
        'type' => 'warning',
        'message' => 'Implementasi dan Post Implementation Review belum dapat diisi pada tahap saat ini.'
    ];

    header('Location: ../user/detail.php?id=' . $id);
    exit;
}


try {

    $pdo->beginTransaction();


    /*
     * Simpan Implementasi + PIR
     * lalu teruskan ke kepala departemen operasional 
     */
    $update = $pdo->prepare("
        UPDATE change_requests
        SET
            implementation = :implementation,
            post_implementation_review = :pir,
            workflow_stage = 'pak_joko'
        WHERE id = :id
          AND user_id = :user_id
          AND workflow_stage = 'PEMOHON_PIR'
    ");

    $update->execute([
        'implementation' => $implementation,
        'pir' => $pir,
        'id' => $id,
        'user_id' => $user['id'],
    ]);


    /*
     * Timeline
     */
    $actor = !empty($user['nama'])
        ? $user['nama']
        : $user['userid'];

    logCrfActivity(
        $pdo,
        $id,
        'Implementasi & PIR Diisi',
        'Pemohon telah mengisi Implementasi / Hasil Perubahan dan Post Implementation Review. CRF diteruskan ke Kepala Departemen Operasional untuk approval.',
        $actor
    );


    $pdo->commit();


    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => 'Implementasi dan Post Implementation Review berhasil dikirim. CRF menunggu approval Kepala Departemen Operasional.'
    ];

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('update_user_crf error: ' . $e->getMessage());

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat menyimpan Implementasi dan Post Implementation Review.'
    ];
}


header('Location: ../user/detail.php?id=' . $id);
exit;