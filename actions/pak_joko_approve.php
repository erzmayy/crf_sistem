<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';
require_once __DIR__ . '/../includes/forum_proposals.php';

requireCrfRole(['kadep_operasional']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pak_joko/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();

$id = (int) ($_POST['id'] ?? 0);
// Kepala Departemen Operasional hanya menyetujui (tidak ada jalur revisi).
$action = 'approve';
$note = trim($_POST['approval_note'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak valid.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, user_id, request_number, assigned_handler_id, crf_category_id, workflow_stage
    FROM change_requests
    WHERE id = :id
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

if (!$crf || $crf['workflow_stage'] !== 'kadep_operasional') {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak tersedia untuk persetujuan.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}


if ($note === '') {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Catatan persetujuan wajib diisi.'
    ];

    header('Location: ../pak_joko/detail.php?id=' . $id);
    exit;
}


try {

    $pdo->beginTransaction();

    $now = date('Y-m-d H:i:s');

    $actor = crfActorName($user);
    $crfLink = 'crf/open.php?id=' . $id;
    $crfNumber = (string) $crf['request_number'];

    // Handler pemegang CRF; bila belum ada, seluruh handler kategori.
    $handlerRecipients = !empty($crf['assigned_handler_id'])
        ? [(int) $crf['assigned_handler_id']]
        : (!empty($crf['crf_category_id']) ? crfCategoryHandlerIds($pdo, (int) $crf['crf_category_id']) : []);


    /* =====================================================
     * APPROVE
     * Kembali ke Otomasi untuk eksekusi
     * ===================================================== */

    if ($action === 'approve') {

        $stmt = $pdo->prepare("
            SELECT
                id,
                sla_value,
                sla_unit
            FROM change_requests
            WHERE id = :id
              AND workflow_stage = 'kadep_operasional'
            FOR UPDATE
        ");

        $stmt->execute(['id' => $id]);
        $approvalCrf = $stmt->fetch();

        if (!$approvalCrf) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk persetujuan.');
        }

        if (
            $approvalCrf['sla_value'] === null
            || $approvalCrf['sla_value'] === ''
            || !is_numeric($approvalCrf['sla_value'])
            || (float) $approvalCrf['sla_value'] <= 0
            || !in_array($approvalCrf['sla_unit'], ['Menit', 'Jam', 'Hari'], true)
        ) {
            $pdo->rollBack();
            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => 'SLA CRF ini belum tersedia (SLA standar kategori belum diatur). Minta Admin menetapkan Level Urgensi & SLA final di Forum sebelum disetujui.',
            ];
            header('Location: ../pak_joko/detail.php?id=' . $id);
            exit;
        }

        /*
         * Setelah disetujui CRF masuk antrean Otomasi. SLA belum berjalan:
         * dimulai saat PIC CRF menekan "Mulai Kerjakan".
         */
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                kadep_operasional_approved_by = :approved_by,
                kadep_operasional_approved_at = :approved_at,
                kadep_operasional_approval_note = :approval_note,
                sla_started_at = NULL,
                sla_due_at = NULL,
                automation_started_at = NULL,
                workflow_stage = 'OTOMASI',
                status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'kadep_operasional'
        ");

        $stmt->execute([
            'approved_by' => $user['id'],
            'approved_at' => $now,
            'approval_note' => $note !== ''
                ? $note
                : null,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk persetujuan.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Approval Kepala Departemen Operasional',
            'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. CRF masuk antrean Otomasi; SLA dimulai saat PIC CRF mulai mengerjakan.'
                . ($note !== '' ? ' Catatan: ' . $note : ''),
            $actor,
            'Menunggu Persetujuan',
            'Disetujui · Antrean'
        );

        // Usulan urgensi/SLA yang belum diputuskan ditutup: nilai yang berlaku adalah nilai saat persetujuan.
        forumCloseOpenProposals(
            $pdo,
            $id,
            'kedaluwarsa',
            'Ditutup otomatis saat CRF disetujui Kepala Departemen Operasional; nilai yang berlaku mengikuti saat persetujuan.'
        );

        notifyUsers(
            $pdo,
            $handlerRecipients ?: crfUserIdsForRole($pdo, 'otomasi'),
            'CRF masuk antrean: ' . $crfNumber,
            'CRF ' . $crfNumber . ' disetujui Kepala Departemen Operasional dan masuk antrean Otomasi. Tekan "Mulai Kerjakan" saat mulai mengerjakan; SLA dihitung sejak saat itu.',
            $crfLink,
            $id,
            null,
            (int) $user['id']
        );
        notifyUsers(
            $pdo,
            [(int) $crf['user_id']],
            'CRF disetujui: ' . $crfNumber,
            'CRF ' . $crfNumber . ' disetujui Kepala Departemen Operasional dan masuk antrean pengerjaan Divisi Otomasi.',
            $crfLink,
            $id,
            null,
            (int) $user['id']
        );

        syncHelpdeskTicketFromCrf($pdo, $id, $actor);

        $pdo->commit();
        dispatchPendingNotificationEmails($pdo);


        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'CRF berhasil disetujui dan masuk antrean Otomasi.'
        ];


        header('Location: ../pak_joko/index.php');
        exit;
    }


    throw new RuntimeException(
        'Aksi persetujuan tidak dikenal.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'pak_joko_approve error: '
        . $e->getMessage()
    );

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat memproses persetujuan.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}
