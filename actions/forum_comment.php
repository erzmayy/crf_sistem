<?php
require_once __DIR__ . '/../includes/forum.php';
requireForumAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrf();

$crfId = filter_input(INPUT_POST, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$replyToId = filter_input(INPUT_POST, 'reply_to_comment_id', FILTER_VALIDATE_INT) ?: 0;
$comment = $_POST['comment'] ?? null;
$filesInput = $_FILES['attachments'] ?? [];
$redirect = '../forum/index.php?crf_id=' . max(0, $crfId);

if (
    $crfId <= 0
    || !is_string($comment)
    || trim($comment) === ''
    || mb_strlen($comment) > FORUM_COMMENT_MAX_LENGTH
) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Komentar wajib diisi dan maksimal 5.000 karakter.',
    ];
    header('Location: ' . $redirect);
    exit;
}

if (forumSelectedFileCount($filesInput) > FORUM_MAX_ATTACHMENTS) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Lampiran maksimal ' . FORUM_MAX_ATTACHMENTS . ' file per komentar.',
    ];
    header('Location: ' . $redirect);
    exit;
}

$pdo = getConnection();
$crf = findForumCrf($pdo, $crfId);

if (!$crf) {
    http_response_code(404);
    exit('CRF tidak ditemukan atau sudah tidak dalam proses.');
}

$replyToUserId = null;
if ($replyToId > 0) {
    $replyStmt = $pdo->prepare(
        'SELECT id, user_id
         FROM forum_comments
         WHERE id = :comment_id
           AND change_request_id = :crf_id
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $replyStmt->execute([
        'comment_id' => $replyToId,
        'crf_id' => $crfId,
    ]);
    $replyRow = $replyStmt->fetch();
    if (!$replyRow) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Komentar yang akan ditanggapi tidak ditemukan.',
        ];
        header('Location: ' . $redirect);
        exit;
    }
    $replyToUserId = (int) $replyRow['user_id'];
} else {
    $replyToId = null;
}

$user = getCurrentUser();
$comment = trim($comment);
$uploadErrors = [];

try {
    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare(
        'INSERT INTO forum_comments
            (change_request_id, user_id, user_name, user_role, comment, reply_to_comment_id)
         VALUES
            (:change_request_id, :user_id, :user_name, :user_role, :comment, :reply_to_comment_id)'
    );
    $insertStmt->execute([
        'change_request_id' => $crfId,
        'user_id' => (int) $user['id'],
        'user_name' => (string) ($user['nama'] ?? 'User'),
        'user_role' => getCrfRole(),
        'comment' => $comment,
        'reply_to_comment_id' => $replyToId,
    ]);
    $commentId = (int) $pdo->lastInsertId();

    $mentionedIds = array_values(array_diff(
        forumExtractMentions($comment, forumMentionCandidates($pdo, $crf)),
        [(int) $user['id']]
    ));
    saveForumMentions($pdo, $commentId, $mentionedIds);

    $uploadErrors = handleForumAttachmentUploads($pdo, $commentId, $filesInput);

    notifyForumComment($pdo, $crf, $commentId, $user, $comment, $mentionedIds, $replyToUserId);

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Forum comment insert failed: ' . $exception->getMessage());
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Komentar gagal disimpan. Silakan coba lagi atau hubungi administrator.',
    ];
    header('Location: ' . $redirect);
    exit;
}

$_SESSION['flash'] = $uploadErrors
    ? [
        'type' => 'warning',
        'message' => 'Komentar berhasil ditambahkan, namun ada lampiran yang gagal diupload: ' . implode(' ', $uploadErrors),
    ]
    : [
        'type' => 'success',
        'message' => 'Komentar berhasil ditambahkan.',
    ];
header('Location: ' . $redirect . '#comment-' . $commentId);
exit;
