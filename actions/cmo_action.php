<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';
requireCrfRole(['cmo']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../cmo/index.php');
    exit;
}
verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$tanggapan = trim($_POST['tanggapan'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak valid.'];
    header('Location: ../cmo/index.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT id, user_id, request_number, crf_category_id, assigned_handler_id,
           status, workflow_stage, kadep_operasional_approved_at,
           level, impact_category, final_urgency_level, sla_value, sla_unit
    FROM change_requests
    WHERE id = :id
    LIMIT 1
');
$stmt->execute(['id' => $id]);
$crf = $stmt->fetch();

if (!$crf) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak ditemukan.'];
    header('Location: ../cmo/index.php');
    exit;
}

$allowed = [
    'to_automation' => 'CMO_FILTER',
    'revision' => 'CMO_FILTER',
    'cancel' => ['CMO_FILTER','CMO_FINAL'],
    'complete' => 'CMO_FINAL',
];

$expected = $allowed[$action] ?? null;
if ($expected === null || (is_array($expected) ? !in_array($crf['workflow_stage'], $expected, true) : $crf['workflow_stage'] !== $expected)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Aksi tidak tersedia pada tahap CRF saat ini.'];
    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

if (in_array($action, ['revision','cancel'], true) && $tanggapan === '') {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Tanggapan / Tindak Lanjut wajib diisi untuk aksi ini.'];
    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');
    $actor = crfActorName($user);
    $oldDisplayStatus = crfDisplayStatus($crf)['label'];
    $crfLink = 'crf/open.php?id=' . $id;
    $crfNumber = (string) $crf['request_number'];

    if ($action === 'to_automation') {
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                status = 'Dalam Proses',
                workflow_stage = 'OTOMASI',
                automation_started_at = NULL,
                sla_started_at = NULL,
                sla_due_at = NULL
            WHERE id = :id
            AND workflow_stage = 'CMO_FILTER'
        ");

        $stmt->execute([
            'id' => $id
        ]);

        logCrfActivity(
            $pdo,
            $id,
            'Lolos Filter CMO',
            'CRF lolos filter CMO dan diteruskan ke Handler kategori.',
            $actor,
            $oldDisplayStatus,
            'Diproses'
        );

        /*
         * SLA otomatis dari matriks Kategori x Urgensi. SLA final yang
         * sudah disepakati di Forum tidak ditimpa.
         */
        $urgency = crfEffectiveUrgency($crf);
        $standardSla = empty($crf['final_urgency_level'])
            ? crfStandardSla($pdo, (int) ($crf['crf_category_id'] ?? 0), $urgency)
            : null;
        if ($standardSla !== null) {
            $pdo->prepare('
                UPDATE change_requests
                SET level = :level, sla_value = :sla_value, sla_unit = :sla_unit
                WHERE id = :id
            ')->execute([
                'level' => $urgency,
                'sla_value' => $standardSla['value'],
                'sla_unit' => $standardSla['unit'],
                'id' => $id,
            ]);
            logCrfActivity(
                $pdo,
                $id,
                'SLA Otomatis',
                'SLA standar kategori untuk urgensi ' . $urgency . ': ' . slaLabel($standardSla['value'], $standardSla['unit']) . ' (hari kerja).',
                'Sistem'
            );
        }

        // Handler kategori; bila kategori belum punya handler, tim Otomasi lama.
        $handlerIds = !empty($crf['crf_category_id'])
            ? crfCategoryHandlerIds($pdo, (int) $crf['crf_category_id'])
            : [];
        notifyUsers(
            $pdo,
            $handlerIds ?: crfUserIdsForRole($pdo, 'otomasi'),
            'CRF masuk: ' . $crfNumber,
            'CRF ' . $crfNumber . ' lolos review CMO dan siap diambil Handler.',
            $crfLink,
            $id,
            null,
            (int) $user['id']
        );

        $message = 'CRF berhasil diteruskan ke Handler kategori.';
    } elseif ($action === 'revision') {
        $stmt = $pdo->prepare("UPDATE change_requests SET status = 'Perlu Revisi', workflow_stage = 'PEMOHON', tanggapan_tindak_lanjut = :tanggapan WHERE id = :id AND workflow_stage = 'CMO_FILTER'");
        $stmt->execute(['tanggapan' => $tanggapan, 'id' => $id]);
        logCrfActivity($pdo, $id, 'Perlu Revisi', $tanggapan, $actor, $oldDisplayStatus, 'Ditolak / Perlu Revisi');
        notifyUsers($pdo, [(int) $crf['user_id']], 'CRF perlu revisi: ' . $crfNumber,
            'CMO mengembalikan CRF Anda untuk diperbaiki: ' . $tanggapan, $crfLink, $id, null, (int) $user['id']);
        $message = 'CRF dikembalikan ke Pemohon untuk revisi.';
    } elseif ($action === 'cancel') {
        $stmt = $pdo->prepare("UPDATE change_requests SET status = 'Cancel', workflow_stage = 'SELESAI', tanggapan_tindak_lanjut = :tanggapan, cancelled_at = :now, solved_at = NULL WHERE id = :id");
        $stmt->execute(['tanggapan' => $tanggapan, 'now' => $now, 'id' => $id]);
        logCrfActivity($pdo, $id, 'Cancel', $tanggapan, $actor, $oldDisplayStatus, 'Dibatalkan');
        notifyUsers($pdo, [(int) $crf['user_id'], (int) $crf['assigned_handler_id']], 'CRF dibatalkan: ' . $crfNumber,
            'CRF ' . $crfNumber . ' dibatalkan oleh CMO: ' . $tanggapan, $crfLink, $id, null, (int) $user['id']);
        $message = 'CRF berhasil dibatalkan.';
    } else {
        $stmt = $pdo->prepare("UPDATE change_requests SET status = 'Solve', workflow_stage = 'SELESAI', solved_at = :now, cancelled_at = NULL WHERE id = :id AND workflow_stage = 'CMO_FINAL'");
        $stmt->execute(['now' => $now, 'id' => $id]);
        logCrfActivity($pdo, $id, 'Solve', 'CMO menyelesaikan dan menutup CRF setelah approval Kepala Departemen Operasional.', $actor, $oldDisplayStatus, 'Selesai');
        notifyUsers($pdo, [(int) $crf['user_id'], (int) $crf['assigned_handler_id']], 'CRF selesai: ' . $crfNumber,
            'CRF ' . $crfNumber . ' telah selesai dan ditutup. Ticket Helpdesk terkait ikut diperbarui.', $crfLink, $id, null, (int) $user['id']);
        $message = 'CRF berhasil ditandai selesai.';
    }

    syncHelpdeskTicketFromCrf($pdo, $id, $actor);

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('cmo_action error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat memproses CRF.'];
}

if ($action === 'to_automation' && isAdmin()) {
    header('Location: ../otomasi/index.php');
} else {
    header('Location: ../cmo/index.php');
}
exit;