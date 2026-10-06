<?php
/**
 * actions/forum_discussion.php
 * Aksi pembahasan Level Urgensi & SLA di Forum (logika di includes/forum_discussions.php).
 *   op=result : penentu (Admin) mencatat hasil — Tetap atau Diubah — dan menerapkannya ke CRF.
 *   op=cancel : batalkan pembahasan yang masih terbuka (CMO atau Admin).
 * Pembahasan diajukan CMO dari halaman verifikasi CMO (actions/cmo_action.php).
 * Level/SLA tidak dapat diubah dari form diskusi Forum.
 */
require_once __DIR__ . '/../includes/forum_discussions.php';
requireForumAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$op = is_string($_POST['op'] ?? null) ? $_POST['op'] : '';
$crfId = filter_input(INPUT_POST, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$discussionId = filter_input(INPUT_POST, 'discussion_id', FILTER_VALIDATE_INT) ?: 0;

$back = static function (int $id): string {
    return '../forum/index.php' . ($id > 0 ? '?crf_id=' . $id . '#forum-pembahasan' : '');
};

try {
    switch ($op) {
        case 'result':
            $outcome = is_string($_POST['outcome'] ?? null) ? $_POST['outcome'] : '';
            $note = forumParseText($_POST['note'] ?? null, 1000, true, 'Alasan / ringkasan kesepakatan');
            $result = forumRecordResult(
                $pdo,
                $discussionId,
                $user,
                $outcome,
                is_string($_POST['urgency'] ?? null) ? $_POST['urgency'] : null,
                $_POST['sla_value'] ?? null,
                $_POST['sla_unit'] ?? null,
                $note
            );
            $crfId = $result['crf_id'];
            $message = $result['message'];
            break;

        case 'cancel':
            $crfId = forumCancelDiscussion($pdo, $discussionId, $user);
            $message = 'Pembahasan dibatalkan. Level Urgensi dan SLA tetap memakai nilai sistem.';
            break;

        default:
            throw new DomainException('Aksi tidak dikenal.');
    }

    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
} catch (DomainException $e) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('forum_discussion [' . $op . '] error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Aksi gagal diproses. Silakan coba lagi atau hubungi administrator.'];
}

header('Location: ' . $back($crfId));
exit;
