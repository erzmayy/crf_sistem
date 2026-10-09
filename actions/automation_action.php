<?php
/**
 * actions/automation_action.php
 * PIC CRF memproses CRF yang sudah disetujui Kepala Departemen Operasional
 * (SLA berjalan sejak persetujuan). Tahap OTOMASI punya tiga fase
 * (lihat crfOtomasiPhase()); aksi yang diterima mengikuti fase CRF:
 *
 *   eksekusi     -> execute      : eksekusi selesai, SLA berhenti, CRF dikirim ke UAT (CMO)
 *   perbaikan    -> resubmit_uat : perbaikan hasil UAT selesai, kirim ulang ke UAT
 *   implementasi -> complete     : setelah UAT lulus, catat tanggal dan hasil implementasi,
 *                                  lalu CRF diteruskan ke Pemohon untuk PIR
 *
 * Waktu eksekusi selesai (automation_completed_at) hanya diisi sekali, sehingga
 * UAT dan perbaikannya tidak dihitung dalam SLA.
 * PIC CRF tidak menentukan atau mengubah Level Urgensi & SLA.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireCrfRole(['otomasi']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../otomasi/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$note = trim($_POST['note'] ?? '');
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
            kadep_operasional_approved_at,
            automation_completed_at,
            uat_passed_at
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

    // Aksi harus sesuai fase; UAT tidak dapat dilewati.
    $phase = crfOtomasiPhase($crf);
    $actionByPhase = ['eksekusi' => 'execute', 'perbaikan' => 'resubmit_uat', 'implementasi' => 'complete'];
    if (($actionByPhase[$phase] ?? null) !== $action) {
        $failRedirect('Aksi ini tidak tersedia pada fase CRF saat ini.', '../otomasi/detail.php?id=' . $id);
    }

    if ($action === 'complete') {
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
                'Tanggal Implementasi dan Implementasi / Hasil Perubahan wajib diisi dengan benar.',
                '../otomasi/detail.php?id=' . $id
            );
        }
    }

    $now = date('Y-m-d H:i:s');
    $actor = crfActorName($user);
    $crfNumber = (string) ($crf['request_number'] ?: 'CRF #' . $id);
    $noteText = $note !== '' ? ' Catatan PIC: ' . $note : '';

    // PIC CRF yang memproses tercatat sebagai pemegang CRF.
    if (empty($crf['assigned_handler_id'])) {
        $pdo->prepare('
            UPDATE change_requests
            SET assigned_handler_id = :handler_id, assigned_handler_name = :handler_name, assigned_at = NOW()
            WHERE id = :id
        ')->execute(['handler_id' => (int) $user['id'], 'handler_name' => $actor, 'id' => $id]);
    }

    if ($action === 'execute') {
        // Eksekusi selesai: SLA berhenti di sini dan tidak dihitung ulang.
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET automation_completed_at = :now, workflow_stage = 'UAT', status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'OTOMASI'
              AND kadep_operasional_approved_at IS NOT NULL
              AND automation_completed_at IS NULL
        ");
        $stmt->execute(['now' => $now, 'id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk diselesaikan.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Eksekusi Selesai',
            'Eksekusi diselesaikan oleh ' . $actor . '. SLA berhenti; CRF diteruskan ke CMO untuk UAT (tidak dihitung dalam SLA).' . $noteText,
            $actor,
            'Disetujui · Eksekusi',
            'Menunggu UAT'
        );
        finalizeCrfSla($pdo, $id);
        $uatTitle = 'UAT CRF: ' . $crfNumber;
        $uatMessage = 'Eksekusi CRF ' . $crfNumber . ' sudah selesai. Silakan lakukan UAT bersama PIC CRF.' . $noteText;
        $flash = 'Eksekusi diselesaikan dan SLA berhenti. CRF diteruskan ke CMO untuk UAT.';
        $redirect = '../otomasi/index.php';
    } elseif ($action === 'resubmit_uat') {
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET workflow_stage = 'UAT', status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'OTOMASI'
              AND automation_completed_at IS NOT NULL
              AND uat_passed_at IS NULL
        ");
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk dikirim ulang.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'UAT Dikirim Ulang',
            'Perbaikan hasil UAT selesai dikerjakan oleh ' . $actor . '. CRF dikirim ulang ke CMO untuk UAT.' . $noteText,
            $actor,
            'Perbaikan Hasil UAT',
            'Menunggu UAT'
        );
        $uatTitle = 'UAT ulang CRF: ' . $crfNumber;
        $uatMessage = 'Perbaikan CRF ' . $crfNumber . ' sudah selesai. Silakan lakukan UAT ulang.' . $noteText;
        $flash = 'Perbaikan selesai. CRF dikirim ulang ke CMO untuk UAT.';
        $redirect = '../otomasi/index.php';
    } else {
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                implementation_date = :implementation_date,
                implementation = :implementation,
                implementation_submitted_at = :now,
                workflow_stage = 'PEMOHON_PIR',
                status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'OTOMASI'
              AND kadep_operasional_approved_at IS NOT NULL
              AND uat_passed_at IS NOT NULL
        ");
        $stmt->execute([
            'implementation_date' => $parsedImplementationDate->format('Y-m-d'),
            'implementation' => $implementation,
            'now' => $now,
            'id' => $id,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk diselesaikan.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Otomasi Selesai',
            'Implementasi dicatat oleh ' . $actor . ' (Tanggal Implementasi serta Implementasi / Hasil Perubahan) setelah UAT lulus. CRF diteruskan ke Pemohon untuk Post Implementation Review.',
            $actor,
            'Isi Implementasi',
            'Menunggu PIR Pemohon'
        );
        $flash = 'Hasil implementasi berhasil disimpan. CRF diteruskan ke Pemohon untuk mengisi Post Implementation Review.';
        $redirect = '../otomasi/index.php?queue=history';
    }

    if ($action === 'complete') {
        notifyUsers(
            $pdo,
            [(int) $crf['user_id']],
            'Isi PIR CRF: ' . $crfNumber,
            'Implementasi CRF ' . $crfNumber . ' sudah selesai. Silakan isi Post Implementation Review.',
            'crf/open.php?id=' . $id,
            $id,
            (int) $user['id']
        );
    } else {
        notifyUsers(
            $pdo,
            crfCmoRecipients($pdo, $id),
            $uatTitle,
            $uatMessage,
            'crf/open.php?id=' . $id,
            $id,
            (int) $user['id']
        );
    }

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $flash];
    header('Location: ' . $redirect);
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
