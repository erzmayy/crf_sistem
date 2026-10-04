<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';

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
            throw new RuntimeException('SLA belum ditentukan dengan benar.');
        }

        // SLA baru dimulai setelah Kepala Departemen Operasional menyetujui CRF.
        $slaStartedAt = $now;
        $slaDueAt = slaDueAt(
            $slaStartedAt,
            $approvalCrf['sla_value'],
            $approvalCrf['sla_unit']
        );

        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                kadep_operasional_approved_by = :approved_by,
                kadep_operasional_approved_at = :approved_at,
                kadep_operasional_approval_note = :approval_note,
                sla_started_at = :sla_started_at,
                sla_due_at = :sla_due_at,
                automation_started_at = :automation_started_at,
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
            'sla_started_at' => $slaStartedAt,
            'sla_due_at' => $slaDueAt,
            'automation_started_at' => $slaStartedAt,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk persetujuan.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Approval Kepala Departemen Operasional',
            $note !== ''
                ? 'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. CRF diteruskan ke Otomasi untuk eksekusi. Catatan: ' . $note
                : 'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. CRF diteruskan ke Otomasi untuk eksekusi.',
            $actor,
            'Menunggu Persetujuan',
            'Disetujui · Eksekusi'
        );

        notifyUsers(
            $pdo,
            array_merge($handlerRecipients, [(int) $crf['user_id']]),
            'CRF disetujui: ' . $crfNumber,
            'CRF ' . $crfNumber . ' disetujui Kepala Departemen Operasional. SLA dimulai dan eksekusi dapat dilakukan.',
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
            'message' => 'CRF berhasil di-approve dan diteruskan ke Otomasi untuk eksekusi.'
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
