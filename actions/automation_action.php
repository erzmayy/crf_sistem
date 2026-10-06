<?php
/**
 * actions/automation_action.php
 * PIC CRF menyelesaikan eksekusi setelah CRF disetujui Kepala Departemen
 * Operasional (SLA sudah berjalan sejak persetujuan): catat tanggal dan hasil
 * implementasi, lalu CRF diteruskan ke Pemohon untuk PIR.
 * PIC CRF tidak menentukan atau mengubah Level Urgensi & SLA.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';

requireCrfRole(['otomasi']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../otomasi/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$implementation = trim($_POST['implementation'] ?? '');
$implementationDate = trim($_POST['implementation_date'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak valid.'];
    header('Location: ../otomasi/index.php');
    exit;
}

$failRedirect = static function (string $message, string $location) use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $message];
    header('Location: ' . $location);
    exit;
};

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            request_number,
            crf_category_id,
            assigned_handler_id,
            assigned_handler_name,
            workflow_stage,
            kadep_operasional_approved_at
        FROM change_requests
        WHERE id = :id
        FOR UPDATE
    ");
    $stmt->execute(['id' => $id]);
    $crf = $stmt->fetch();

    if (!$crf || $crf['workflow_stage'] !== 'OTOMASI' || empty($crf['kadep_operasional_approved_at'])) {
        $failRedirect('CRF tidak ditemukan pada daftar eksekusi.', '../otomasi/index.php');
    }

    // Hanya PIC CRF kategori CRF ini.
    if (!canHandleCrf($pdo, $crf)) {
        $failRedirect('CRF ini bukan kategori yang Anda tangani.', '../otomasi/index.php');
    }

    if (!empty($crf['assigned_handler_id']) && !isAssignedCrfHandler($crf)) {
        $failRedirect(
            'CRF ini sedang ditangani oleh ' . ($crf['assigned_handler_name'] ?? 'PIC CRF lain') . '.',
            '../otomasi/detail.php?id=' . $id
        );
    }

    $parsedImplementationDate = DateTime::createFromFormat('!Y-m-d', $implementationDate);
    $implementationDateErrors = DateTime::getLastErrors();
    $isValidImplementationDate = $parsedImplementationDate !== false
        && $parsedImplementationDate->format('Y-m-d') === $implementationDate
        && (
            $implementationDateErrors === false
            || (
                $implementationDateErrors['warning_count'] === 0
                && $implementationDateErrors['error_count'] === 0
            )
        );

    // PIR tidak diisi PIC CRF; Pemohon mengisinya setelah implementasi.
    if (!$isValidImplementationDate || $implementation === '') {
        $failRedirect(
            'Tanggal Implementasi dan Implementasi / Hasil Perubahan wajib diisi dengan benar sebelum eksekusi diselesaikan.',
            '../otomasi/detail.php?id=' . $id
        );
    }

    $now = date('Y-m-d H:i:s');
    $actor = crfActorName($user);

    // PIC CRF yang menyelesaikan tercatat sebagai pemegang CRF.
    if (empty($crf['assigned_handler_id'])) {
        $pdo->prepare('
            UPDATE change_requests
            SET assigned_handler_id = :handler_id, assigned_handler_name = :handler_name, assigned_at = NOW()
            WHERE id = :id
        ')->execute(['handler_id' => (int) $user['id'], 'handler_name' => $actor, 'id' => $id]);
    }

    $stmt = $pdo->prepare("
        UPDATE change_requests
        SET
            automation_completed_at = :automation_completed_at,
            implementation_date = :implementation_date,
            implementation = :implementation,
            workflow_stage = 'PEMOHON_PIR',
            status = 'Dalam Proses'
        WHERE id = :id
          AND workflow_stage = 'OTOMASI'
          AND kadep_operasional_approved_at IS NOT NULL
    ");
    $stmt->execute([
        'automation_completed_at' => $now,
        'implementation_date' => $parsedImplementationDate->format('Y-m-d'),
        'implementation' => $implementation,
        'id' => $id,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('CRF sudah tidak tersedia untuk diselesaikan.');
    }

    logCrfActivity(
        $pdo,
        $id,
        'Otomasi Selesai',
        'Eksekusi diselesaikan oleh ' . $actor . ' dengan mencatat Tanggal Implementasi serta Implementasi / Hasil Perubahan. CRF diteruskan ke Pemohon untuk Post Implementation Review.',
        $actor,
        'Disetujui · Eksekusi',
        'Menunggu PIR Pemohon'
    );
    finalizeCrfSla($pdo, $id);
    notifyUsers(
        $pdo,
        [(int) $crf['user_id']],
        'Isi PIR CRF: ' . $crf['request_number'],
        'Implementasi CRF ' . $crf['request_number'] . ' sudah selesai. Silakan isi Post Implementation Review.',
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
        'message' => 'Hasil implementasi berhasil disimpan. CRF diteruskan ke Pemohon untuk mengisi Post Implementation Review.',
    ];
    header('Location: ../otomasi/index.php?queue=history');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('automation_action error: ' . $e->getMessage());
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat memproses Otomasi.'
    ];
    header('Location: ../otomasi/detail.php?id=' . $id);
    exit;
}
