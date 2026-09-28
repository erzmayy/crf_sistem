<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['kadep_operasional']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pak_joko/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();

$id = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'approve';
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
    SELECT id, workflow_stage
    FROM change_requests
    WHERE id = :id
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

if (!$crf || $crf['workflow_stage'] !== 'kadep_operasional') {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak tersedia untuk approval.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}


if ($action === 'revision' && $note === '') {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Catatan revisi wajib diisi.'
    ];

    header('Location: ../pak_joko/detail.php?id=' . $id);
    exit;
}


try {

    $pdo->beginTransaction();

    $now = date('Y-m-d H:i:s');

    $actor = !empty($user['nama'])
        ? $user['nama']
        : $user['userid'];


    /* =====================================================
     * PERLU REVISI
     * ===================================================== */

    if ($action === 'revision') {

        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                kadep_operasional_approved_by = NULL,
                kadep_operasional_approved_at = NULL,
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
            'approval_note' => $note,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk approval.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Perlu Revisi',
            'Kepala Departemen Operasional mengembalikan CRF ke Otomasi untuk revisi sebelum approval. Catatan: ' . $note,
            $actor
        );


        $pdo->commit();


        $_SESSION['flash'] = [
            'type' => 'warning',
            'message' => 'CRF berhasil dikembalikan ke Otomasi untuk revisi.'
        ];


        header('Location: ../pak_joko/index.php');
        exit;
    }


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
            throw new RuntimeException('CRF sudah tidak tersedia untuk approval.');
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
            throw new RuntimeException('CRF sudah tidak tersedia untuk approval.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Approval Kepala Departemen Operasional',
            $note !== ''
                ? 'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. CRF diteruskan ke Otomasi untuk eksekusi. Catatan: ' . $note
                : 'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. CRF diteruskan ke Otomasi untuk eksekusi.',
            $actor
        );


        $pdo->commit();


        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'CRF berhasil di-approve dan diteruskan ke Otomasi untuk eksekusi.'
        ];


        header('Location: ../pak_joko/index.php');
        exit;
    }


    throw new RuntimeException(
        'Aksi approval tidak dikenal.'
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
        'message' => 'Terjadi kesalahan saat memproses approval.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}
