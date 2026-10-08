<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';
require_once __DIR__ . '/../includes/forum_discussions.php';
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
           status, workflow_stage, kadep_operasional_approved_at, automation_completed_at,
           level, impact_category, final_urgency_level, sla_value, sla_unit, forum_discussion_open
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
    'to_approval' => 'CMO_FILTER',
    'request_discussion' => 'CMO_FILTER',
    'cancel_discussion' => 'CMO_FILTER',
    'revision' => 'CMO_FILTER',
    'cancel' => 'CMO_FILTER',
    'complete' => 'CMO_FINAL',
    'remind_pir' => 'PEMOHON_PIR',
];

$expected = $allowed[$action] ?? null;
if ($expected === null || (is_array($expected) ? !in_array($crf['workflow_stage'], $expected, true) : $crf['workflow_stage'] !== $expected)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Aksi tidak tersedia pada tahap CRF saat ini.'];
    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

if (in_array($action, ['revision','cancel','request_discussion'], true) && $tanggapan === '') {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Tanggapan / Tindak Lanjut wajib diisi untuk aksi ini.'];
    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

/*
 * Pengingat PIR: hanya notifikasi + catatan timeline, tidak mengubah tahap.
 */
if ($action === 'remind_pir') {
    $reminder = crfPirReminderInfo($pdo, $crf);
    if (!$reminder['can_send']) {
        $_SESSION['flash'] = [
            'type' => 'warning',
            'message' => 'Pengingat sudah dikirim. Pengingat berikutnya bisa dikirim setelah '
                . date('d-m-Y H:i', strtotime($reminder['next_at'])) . '.',
        ];
        header('Location: ../cmo/detail.php?id=' . $id);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $actor = crfActorName($user);
        $note = $tanggapan !== '' ? ' Pesan CMO: ' . $tanggapan : '';
        logCrfActivity(
            $pdo,
            $id,
            'Pengingat PIR',
            'CMO mengingatkan Pemohon untuk mengisi Post Implementation Review (pengingat ke-' . ($reminder['count'] + 1) . ').' . $note,
            $actor
        );
        notifyUsers(
            $pdo,
            [(int) $crf['user_id']],
            'Pengingat: isi PIR CRF ' . $crf['request_number'],
            'Implementasi CRF ' . $crf['request_number'] . ' sudah selesai'
                . ($reminder['waiting_days'] ? ' sejak ' . $reminder['waiting_days'] . ' hari kerja lalu' : '')
                . '. Mohon segera isi Post Implementation Review agar CRF dapat ditutup.' . $note,
            'crf/open.php?id=' . $id,
            $id,
            null,
            (int) $user['id']
        );
        $pdo->commit();
        dispatchPendingNotificationEmails($pdo);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Pengingat PIR berhasil dikirim ke Pemohon.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('cmo_action remind_pir error: ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat mengirim pengingat.'];
    }

    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

/*
 * Screening CMO: "Ajukan Pembahasan Forum" / batalkan pembahasan.
 * Selama pembahasan terbuka, CRF tidak dapat diteruskan ke Kepala Departemen.
 */
if (in_array($action, ['request_discussion', 'cancel_discussion'], true)) {
    try {
        if ($action === 'request_discussion') {
            forumRequestDiscussion($pdo, $id, $user, $tanggapan);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Pembahasan Forum diajukan. Setelah CMO atau Admin mencatat hasil pembahasan, CRF langsung diteruskan ke Kepala Departemen Operasional.',
            ];
        } else {
            $openDiscussion = forumOpenDiscussion($pdo, $id);
            if (!$openDiscussion) {
                throw new DomainException('Tidak ada pembahasan Forum yang sedang terbuka.');
            }
            forumCancelDiscussion($pdo, (int) $openDiscussion['id'], $user);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Pembahasan Forum dibatalkan. Level Urgensi dan SLA tetap memakai nilai sistem.',
            ];
        }
        dispatchPendingNotificationEmails($pdo);
    } catch (DomainException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('cmo_action ' . $action . ' error: ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat memproses pembahasan Forum.'];
    }

    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

if ($action === 'to_approval') {
    if (!empty($crf['forum_discussion_open'])) {
        $_SESSION['flash'] = [
            'type' => 'warning',
            'message' => 'CRF ini menunggu pembahasan Forum. Setelah hasil pembahasan dicatat (CMO atau Admin), CRF otomatis diteruskan ke Kepala Departemen.',
        ];
        header('Location: ../cmo/detail.php?id=' . $id);
        exit;
    }

    if (!crfHasValidSla($crf)) {
        // SLA default belum ada (mis. matriks kategori baru diisi): coba isi, atau buka pembahasan Forum otomatis.
        try {
            $pdo->beginTransaction();
            forumApplyDefaultSla($pdo, $id, $user);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('cmo_action default SLA error: ' . $e->getMessage());
        }

        $freshStmt = $pdo->prepare('SELECT sla_value, sla_unit, forum_discussion_open FROM change_requests WHERE id = :id');
        $freshStmt->execute(['id' => $id]);
        $fresh = $freshStmt->fetch() ?: [];
        if (!crfHasValidSla($fresh)) {
            dispatchPendingNotificationEmails($pdo);
            $_SESSION['flash'] = [
                'type' => 'warning',
                'message' => 'SLA CRF ini belum tersedia karena SLA standar kategori belum diatur. Pembahasan Forum dibuka otomatis; CMO atau Admin akan menetapkan SLA sebelum CRF dapat diteruskan.',
            ];
            header('Location: ../cmo/detail.php?id=' . $id);
            exit;
        }
        $crf = array_merge($crf, $fresh);
    }
}

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');
    $actor = crfActorName($user);
    $oldDisplayStatus = crfDisplayStatus($crf)['label'];
    $crfLink = 'crf/open.php?id=' . $id;
    $crfNumber = (string) $crf['request_number'];

    if ($action === 'to_approval') {
        // SLA belum berjalan: dimulai saat Kepala Departemen Operasional menyetujui.
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                status = 'Dalam Proses',
                workflow_stage = 'kadep_operasional',
                automation_started_at = NULL,
                sla_started_at = NULL,
                sla_due_at = NULL
            WHERE id = :id
            AND workflow_stage = 'CMO_FILTER'
            AND forum_discussion_open = 0
        ");

        $stmt->execute([
            'id' => $id
        ]);

        logCrfActivity(
            $pdo,
            $id,
            'Lolos Filter CMO',
            'CRF lolos verifikasi CMO dan diteruskan ke Kepala Departemen Operasional untuk persetujuan.',
            $actor,
            $oldDisplayStatus,
            'Menunggu Persetujuan'
        );

        notifyUsers(
            $pdo,
            crfUserIdsForRole($pdo, 'kadep_operasional'),
            'Persetujuan CRF: ' . $crfNumber,
            'CRF ' . $crfNumber . ' lolos verifikasi CMO dan menunggu persetujuan Anda.',
            $crfLink,
            $id,
            null,
            (int) $user['id']
        );

        $message = 'CRF berhasil diteruskan ke Kepala Departemen Operasional untuk persetujuan.';
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
        forumCloseOpenDiscussions($pdo, $id, 'CRF dibatalkan oleh CMO.');
        logCrfActivity($pdo, $id, 'Cancel', $tanggapan, $actor, $oldDisplayStatus, 'Dibatalkan');
        notifyUsers($pdo, [(int) $crf['user_id']], 'CRF dibatalkan: ' . $crfNumber,
            'CRF ' . $crfNumber . ' dibatalkan oleh CMO: ' . $tanggapan, $crfLink, $id, null, (int) $user['id']);
        $message = 'CRF berhasil dibatalkan.';
    } else {
        $stmt = $pdo->prepare("UPDATE change_requests SET status = 'Solve', workflow_stage = 'SELESAI', solved_at = :now, cancelled_at = NULL WHERE id = :id AND workflow_stage = 'CMO_FINAL'");
        $stmt->execute(['now' => $now, 'id' => $id]);
        logCrfActivity($pdo, $id, 'Solve', 'CMO menyelesaikan dan menutup CRF setelah persetujuan Kepala Departemen Operasional.', $actor, $oldDisplayStatus, 'Selesai');
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

if ($action === 'to_approval' && isDemoUser()) {
    header('Location: ../pak_joko/index.php');
} else {
    header('Location: ../cmo/index.php');
}
exit;