<?php
/**
 * actions/submit_pir.php
 * Pemohon mengisi Post Implementation Review (PIR) setelah Otomasi
 * mencatat hasil implementasi. CRF lalu diteruskan ke CMO untuk finalisasi.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user/pengajuan_saya.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$pir = trim($_POST['tinjauan_pasca_implementasi'] ?? '');
$pirDate = trim($_POST['tgl_pir'] ?? '');
$redirect = $id > 0 ? '../user/detail.php?id=' . $id . '#implementation-review' : '../user/pengajuan_saya.php';

$parsedPirDate = DateTime::createFromFormat('!Y-m-d', $pirDate);
$pirDateErrors = DateTime::getLastErrors();
$isValidPirDate = $parsedPirDate !== false
    && $parsedPirDate->format('Y-m-d') === $pirDate
    && (
        $pirDateErrors === false
        || ($pirDateErrors['warning_count'] === 0 && $pirDateErrors['error_count'] === 0)
    );

if ($id <= 0 || !$isValidPirDate || $pir === '') {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Tanggal PIR dan Post Implementation Review wajib diisi dengan benar.',
    ];
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, pengguna_id, nomor_register, tahap_alur, tgl_implementasi
        FROM crf_pengajuan
        WHERE id = :id
          AND pengguna_id = :pengguna_id
        FOR UPDATE
    ");
    $stmt->execute(['id' => $id, 'pengguna_id' => (int) $user['id']]);
    $crf = $stmt->fetch();

    if (!$crf || $crf['tahap_alur'] !== 'PEMOHON_PIR') {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'CRF tidak ditemukan atau belum/tidak lagi pada tahap Post Implementation Review.',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    if (!empty($crf['tgl_implementasi']) && $parsedPirDate->format('Y-m-d') < $crf['tgl_implementasi']) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Tanggal PIR tidak boleh sebelum Tanggal Implementasi ('
                . date('d-m-Y', strtotime($crf['tgl_implementasi'])) . ').',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    $update = $pdo->prepare("
        UPDATE crf_pengajuan
        SET tgl_pir = :tgl_pir,
            tinjauan_pasca_implementasi = :pir,
            tahap_alur = 'CMO_FINAL'
        WHERE id = :id
          AND tahap_alur = 'PEMOHON_PIR'
    ");
    $update->execute([
        'tgl_pir' => $parsedPirDate->format('Y-m-d'),
        'pir' => $pir,
        'id' => $id,
    ]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException('CRF sudah tidak tersedia untuk PIR.');
    }

    $actor = crfActorName($user);

    logCrfActivity(
        $pdo,
        $id,
        'PIR Diisi Pemohon',
        'Pemohon mengisi Tanggal PIR dan Post Implementation Review. CRF diteruskan ke CMO untuk finalisasi.',
        $actor,
        'Menunggu PIR Pemohon',
        'Menunggu Finalisasi'
    );
    notifyUsers(
        $pdo,
        crfCmoRecipients($pdo, $id),
        'Finalisasi CRF: ' . $crf['nomor_register'],
        'Pemohon sudah mengisi PIR untuk CRF ' . $crf['nomor_register'] . '. CRF menunggu finalisasi CMO.',
        'crf/open.php?id=' . $id,
        $id,
        (int) $user['id']
    );

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => 'Post Implementation Review berhasil dikirim. CRF diteruskan ke CMO untuk finalisasi.',
    ];
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('submit_pir error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat menyimpan Post Implementation Review.'];
    header('Location: ' . $redirect);
    exit;
}
