<?php
/**
 * actions/admin_cancel_crf.php
 * Pembatalan administratif oleh Admin, untuk CRF yang tidak bisa
 * dilanjutkan lewat alur normal (duplikat, salah input, pemohon keluar,
 * dsb). Alasan wajib diisi dan tercatat di timeline.
 *
 * Menggantikan halaman edit status lama (admin/edit.php) yang dapat
 * mengubah status tanpa mengikuti tahap workflow.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin/dashboard.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$redirect = '../admin/detail.php?id=' . $id;

if ($id <= 0 || $reason === '' || mb_strlen($reason) > 1000) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Alasan pembatalan wajib diisi (maks. 1000 karakter).'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        SELECT id, user_id, request_number, status, workflow_stage, assigned_handler_id, kadep_operasional_approved_at
        FROM change_requests
        WHERE id = :id
        FOR UPDATE
    ');
    $stmt->execute(['id' => $id]);
    $crf = $stmt->fetch();

    if ($crf && isOwnHandledCrf($crf)) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Anda sedang memegang CRF ini sebagai Handler. Pembatalan harus dilakukan Admin lain.'];
        header('Location: ' . $redirect);
        exit;
    }

    if (!$crf || in_array($crf['status'], ['Draft', 'Solve', 'Cancel'], true)) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak dapat dibatalkan (draft, sudah selesai, atau sudah dibatalkan).'];
        header('Location: ' . $redirect);
        exit;
    }

    $actor = crfActorName($user);
    $oldDisplayStatus = crfDisplayStatus($crf)['label'];

    $pdo->prepare("
        UPDATE change_requests
        SET status = 'Cancel', workflow_stage = 'SELESAI',
            tanggapan_tindak_lanjut = :reason, cancelled_at = NOW(), solved_at = NULL
        WHERE id = :id
    ")->execute(['reason' => $reason, 'id' => $id]);

    logCrfActivity(
        $pdo,
        $id,
        'Cancel',
        'Dibatalkan secara administratif oleh Admin (tahap terakhir: ' . workflowStageLabel((string) $crf['workflow_stage']) . '). Alasan: ' . $reason,
        $actor,
        $oldDisplayStatus,
        'Dibatalkan'
    );

    notifyUsers(
        $pdo,
        [(int) $crf['user_id'], (int) $crf['assigned_handler_id']],
        'CRF dibatalkan: ' . $crf['request_number'],
        'CRF ' . $crf['request_number'] . ' dibatalkan oleh Admin. Alasan: ' . $reason,
        'crf/open.php?id=' . $id,
        $id,
        null,
        (int) $user['id']
    );

    syncHelpdeskTicketFromCrf($pdo, $id, $actor);

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'CRF ' . $crf['request_number'] . ' berhasil dibatalkan.'];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('admin_cancel_crf error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat membatalkan CRF.'];
}

header('Location: ' . $redirect);
exit;
