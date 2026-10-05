<?php
/**
 * actions/forum_resolve.php
 * Admin/CMO menandai pembahasan Forum suatu CRF selesai (kesepakatan
 * tercapai) atau membukanya kembali. Komentar tetap dapat ditambahkan.
 */
require_once __DIR__ . '/../includes/forum.php';
requireCrfRole(['admin', 'cmo']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrf();

$crfId = filter_input(INPUT_POST, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$operation = $_POST['operation'] ?? '';
$note = trim((string) ($_POST['note'] ?? ''));
$redirect = '../forum/index.php?crf_id=' . max(0, $crfId);

if ($crfId <= 0 || !in_array($operation, ['resolve', 'reopen'], true) || mb_strlen($note) > 1000) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Permintaan tidak valid. Catatan kesimpulan maksimal 1.000 karakter.',
    ];
    header('Location: ' . $redirect);
    exit;
}

$pdo = getConnection();
$user = getCurrentUser();
$actor = crfActorName($user);

try {
    $pdo->beginTransaction();

    $crf = findForumCrf($pdo, $crfId, true);
    if (!$crf) {
        $pdo->rollBack();
        http_response_code(404);
        exit('CRF tidak ditemukan atau sudah tidak dalam proses.');
    }

    $isResolved = !empty($crf['forum_resolved_at']);
    if (($operation === 'resolve') === $isResolved) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'info',
            'message' => $isResolved ? 'Pembahasan sudah ditandai selesai.' : 'Pembahasan sudah terbuka.',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    if ($operation === 'resolve') {
        $pdo->prepare(
            'UPDATE change_requests
             SET forum_resolved_at = NOW(), forum_resolved_by_name = :actor
             WHERE id = :id'
        )->execute(['actor' => $actor, 'id' => $crfId]);

        $activity = 'Pembahasan Forum Selesai';
        $description = 'Pembahasan Forum ditandai selesai (kesepakatan tercapai) oleh '
            . crfRoleLabel(getCrfRole()) . ' ' . $actor . '.'
            . ($note !== '' ? ' Kesimpulan: ' . $note : '');
        $title = 'Pembahasan Forum selesai: ' . forumCrfLabel($crf);
    } else {
        $pdo->prepare(
            'UPDATE change_requests
             SET forum_resolved_at = NULL, forum_resolved_by_name = NULL
             WHERE id = :id'
        )->execute(['id' => $crfId]);

        $activity = 'Pembahasan Forum Dibuka Kembali';
        $description = 'Pembahasan Forum dibuka kembali oleh '
            . crfRoleLabel(getCrfRole()) . ' ' . $actor . '.'
            . ($note !== '' ? ' Alasan: ' . $note : '');
        $title = 'Pembahasan Forum dibuka kembali: ' . forumCrfLabel($crf);
    }

    logCrfActivity($pdo, $crfId, $activity, $description, $actor);
    $commentId = addForumSystemComment($pdo, $crfId, $user, $description);
    notifyForumParticipants($pdo, $crf, $user, $title, $description, $commentId);

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => $operation === 'resolve'
            ? 'Pembahasan ditandai selesai.'
            : 'Pembahasan dibuka kembali.',
    ];
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Forum resolve failed: ' . $exception->getMessage());
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Status pembahasan gagal disimpan. Silakan coba lagi.',
    ];
}

header('Location: ' . $redirect);
exit;
