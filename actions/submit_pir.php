<?php
/**
 * actions/submit_pir.php
 * Pemohon mengisi Post Implementation Review (PIR) setelah Otomasi
 * mencatat hasil implementasi. CRF lalu diteruskan ke CMO untuk finalisasi.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user/pengajuan_saya.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$pir = trim($_POST['post_implementation_review'] ?? '');
$pirDate = trim($_POST['pir_date'] ?? '');
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
        SELECT id, user_id, request_number, workflow_stage, implementation_date
        FROM change_requests
        WHERE id = :id
          AND user_id = :user_id
        FOR UPDATE
    ");
    $stmt->execute(['id' => $id, 'user_id' => (int) $user['id']]);
    $crf = $stmt->fetch();

    if (!$crf || $crf['workflow_stage'] !== 'PEMOHON_PIR') {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'CRF tidak ditemukan atau belum/tidak lagi pada tahap Post Implementation Review.',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    if (!empty($crf['implementation_date']) && $parsedPirDate->format('Y-m-d') < $crf['implementation_date']) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Tanggal PIR tidak boleh sebelum Tanggal Implementasi ('
                . date('d-m-Y', strtotime($crf['implementation_date'])) . ').',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    $update = $pdo->prepare("
        UPDATE change_requests
        SET pir_date = :pir_date,
            post_implementation_review = :pir,
            workflow_stage = 'CMO_FINAL'
        WHERE id = :id
          AND workflow_stage = 'PEMOHON_PIR'
    ");
    $update->execute([
        'pir_date' => $parsedPirDate->format('Y-m-d'),
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
        'Finalisasi CRF: ' . $crf['request_number'],
        'Pemohon sudah mengisi PIR untuk CRF ' . $crf['request_number'] . '. CRF menunggu finalisasi CMO.',
        'crf/open.php?id=' . $id,
        $id,
        null,
        (int) $user['id']
    );

    syncHelpdeskTicketFromCrf($pdo, $id, $actor);

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
