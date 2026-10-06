<?php
/**
 * actions/automation_action.php
 * Aksi PIC CRF setelah CRF disetujui Kepala Departemen Operasional:
 *   - start    : "Mulai Kerjakan" — CRF keluar dari antrean, SLA mulai dihitung.
 *   - complete : "Selesaikan" — catat hasil implementasi, lanjut ke PIR Pemohon.
 * PIC CRF tidak menentukan/mengubah Level Urgensi & SLA (hanya Admin di Forum).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';
require_once __DIR__ . '/../includes/forum_proposals.php';

requireCrfRole(['otomasi']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../otomasi/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$implementation = trim($_POST['implementation'] ?? '');
$implementationDate = trim($_POST['implementation_date'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak valid.'];
    header('Location: ../otomasi/index.php');
    exit;
}

if (!in_array($action, ['start', 'complete'], true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Aksi Otomasi tidak dikenal.'];
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
            automation_started_at,
            kadep_operasional_approved_at,
            sla_value,
            sla_unit
        FROM change_requests
        WHERE id = :id
        FOR UPDATE
    ");
    $stmt->execute(['id' => $id]);
    $crf = $stmt->fetch();

    if (!$crf || $crf['workflow_stage'] !== 'OTOMASI' || empty($crf['kadep_operasional_approved_at'])) {
        $failRedirect('CRF tidak ditemukan pada antrean Otomasi.', '../otomasi/index.php');
    }

    // Hanya handler kategori CRF ini.
    if (!canHandleCrf($pdo, $crf)) {
        $failRedirect('CRF ini bukan kategori yang Anda tangani.', '../otomasi/index.php');
    }

    if (!empty($crf['assigned_handler_id']) && !isAssignedCrfHandler($crf)) {
        $failRedirect(
            'CRF ini sedang ditangani oleh ' . ($crf['assigned_handler_name'] ?? 'PIC CRF lain') . '.',
            '../otomasi/detail.php?id=' . $id
        );
    }

    $now = date('Y-m-d H:i:s');
    $actor = crfActorName($user);
    $isStarted = !empty($crf['automation_started_at']);

    if ($action === 'start') {
        if ($isStarted) {
            $failRedirect('CRF ini sudah mulai dikerjakan.', '../otomasi/detail.php?id=' . $id);
        }

        $slaDueAt = slaDueAt($now, $crf['sla_value'], $crf['sla_unit']);
        if ($slaDueAt === null) {
            $failRedirect(
                'SLA CRF ini belum valid. Hubungi Admin untuk menetapkan SLA di Forum.',
                '../otomasi/detail.php?id=' . $id
            );
        }

        // CRF yang belum punya PIC CRF otomatis dipegang oleh yang memulai.
        if (empty($crf['assigned_handler_id'])) {
            $pdo->prepare('
                UPDATE change_requests
                SET assigned_handler_id = :handler_id, assigned_handler_name = :handler_name, assigned_at = NOW()
                WHERE id = :id
            ')->execute([
                'handler_id' => (int) $user['id'],
                'handler_name' => $actor,
                'id' => $id,
            ]);
            logCrfActivity($pdo, $id, 'CRF Diambil Handler', 'CRF diterima dan diproses oleh ' . $actor . '.', $actor);
        }

        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                automation_started_at = :started_at,
                sla_started_at = :sla_started_at,
                sla_due_at = :sla_due_at
            WHERE id = :id
              AND workflow_stage = 'OTOMASI'
              AND automation_started_at IS NULL
        ");
        $stmt->execute([
            'started_at' => $now,
            'sla_started_at' => $now,
            'sla_due_at' => $slaDueAt,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk dikerjakan.');
        }

        $waitSeconds = slaWorkingSecondsBetween(
            new DateTimeImmutable($crf['kadep_operasional_approved_at']),
            new DateTimeImmutable($now)
        );
        logCrfActivity(
            $pdo,
            $id,
            'Mulai Dikerjakan',
            'PIC CRF mulai mengerjakan CRF. SLA ' . slaLabel($crf['sla_value'], $crf['sla_unit'])
                . ' mulai dihitung, batas ' . date('d-m-Y H:i', strtotime($slaDueAt))
                . '. Waktu tunggu di antrean: ' . formatSlaDuration($waitSeconds) . '.',
            $actor,
            'Disetujui · Antrean',
            'Disetujui · Eksekusi'
        );
        notifyUsers(
            $pdo,
            [(int) $crf['user_id']],
            'CRF mulai dikerjakan: ' . $crf['request_number'],
            'CRF ' . $crf['request_number'] . ' mulai dikerjakan oleh ' . $actor . '. Target selesai '
                . date('d-m-Y H:i', strtotime($slaDueAt)) . '.',
            'crf/open.php?id=' . $id,
            $id,
            null,
            (int) $user['id']
        );

        $message = 'CRF mulai dikerjakan. SLA mulai dihitung sejak sekarang.';
        $redirect = '../otomasi/detail.php?id=' . $id;
    } else {
        if (!$isStarted) {
            $failRedirect('Tekan "Mulai Kerjakan" terlebih dahulu sebelum menyelesaikan CRF.', '../otomasi/detail.php?id=' . $id);
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

        // PIR tidak diisi Otomasi; Pemohon mengisinya setelah implementasi.
        if (!$isValidImplementationDate || $implementation === '') {
            $failRedirect(
                'Tanggal Implementasi dan Implementasi / Hasil Perubahan wajib diisi dengan benar sebelum eksekusi diselesaikan.',
                '../otomasi/detail.php?id=' . $id
            );
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
              AND automation_started_at IS NOT NULL
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
            'Otomasi menyelesaikan eksekusi dan mencatat Tanggal Implementasi serta Implementasi / Hasil Perubahan. CRF diteruskan ke Pemohon untuk Post Implementation Review.',
            $actor,
            'Disetujui · Eksekusi',
            'Menunggu PIR Pemohon'
        );
        finalizeCrfSla($pdo, $id);
        forumCloseOpenProposals($pdo, $id, 'kedaluwarsa', 'CRF selesai dikerjakan.');
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
        $message = 'Hasil implementasi berhasil disimpan. CRF diteruskan ke Pemohon untuk mengisi Post Implementation Review.';
        $redirect = '../otomasi/index.php?queue=history';
    }

    syncHelpdeskTicketFromCrf($pdo, $id, $actor);

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
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
