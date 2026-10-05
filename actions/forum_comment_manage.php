<?php
/**
 * actions/forum_comment_manage.php
 * Ubah atau hapus komentar Forum.
 * - Ubah: hanya penulis komentar (bukan komentar sistem).
 * - Hapus: penulis komentar atau Admin (moderasi). Komentar tidak
 *   benar-benar dihapus dari database (soft delete) dan tercatat di
 *   riwayat CRF.
 */
require_once __DIR__ . '/../includes/forum.php';
requireForumAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrf();

$commentId = filter_input(INPUT_POST, 'comment_id', FILTER_VALIDATE_INT) ?: 0;
$operation = $_POST['operation'] ?? '';
$newComment = $_POST['comment'] ?? null;

$pdo = getConnection();
$user = getCurrentUser();
$userId = (int) $user['id'];
$actor = crfActorName($user);

$stmt = $pdo->prepare(
    'SELECT id, change_request_id, user_id, user_name, comment, is_system, deleted_at
     FROM forum_comments
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $commentId]);
$target = $stmt->fetch();

if (!$target || !in_array($operation, ['edit', 'delete'], true)) {
    http_response_code(404);
    exit('Komentar tidak ditemukan.');
}

$crfId = (int) $target['change_request_id'];
$redirect = '../forum/index.php?crf_id=' . $crfId . '#comment-' . $commentId;
$isOwner = (int) $target['user_id'] === $userId;

$fail = static function (string $message) use ($redirect): void {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $message];
    header('Location: ' . $redirect);
    exit;
};

if (!empty($target['deleted_at'])) {
    $fail('Komentar sudah dihapus.');
}

if ((bool) $target['is_system']) {
    $fail('Catatan sistem tidak dapat diubah atau dihapus.');
}

if ($operation === 'edit') {
    if (!$isOwner) {
        $fail('Anda hanya dapat mengubah komentar Anda sendiri.');
    }
    if (!is_string($newComment) || trim($newComment) === '' || mb_strlen($newComment) > FORUM_COMMENT_MAX_LENGTH) {
        $fail('Komentar wajib diisi dan maksimal 5.000 karakter.');
    }
} elseif (!$isOwner && !isAdmin()) {
    $fail('Anda tidak berhak menghapus komentar ini.');
}

try {
    $pdo->beginTransaction();

    $crf = findForumCrf($pdo, $crfId, true);
    if (!$crf) {
        $pdo->rollBack();
        $fail('Forum hanya dapat diubah selama CRF masih dalam proses.');
    }

    if ($operation === 'edit') {
        $newComment = trim($newComment);
        $pdo->prepare(
            'UPDATE forum_comments
             SET comment = :comment, edited_at = NOW()
             WHERE id = :id AND deleted_at IS NULL'
        )->execute(['comment' => $newComment, 'id' => $commentId]);

        $oldMentionStmt = $pdo->prepare('SELECT user_id FROM forum_comment_mentions WHERE forum_comment_id = :id');
        $oldMentionStmt->execute(['id' => $commentId]);
        $oldMentions = array_map('intval', $oldMentionStmt->fetchAll(PDO::FETCH_COLUMN));

        $mentionedIds = array_values(array_diff(
            forumExtractMentions($newComment, forumMentionCandidates($pdo, $crf)),
            [$userId]
        ));
        saveForumMentions($pdo, $commentId, $mentionedIds);

        // Hanya yang baru di-mention yang diberi tahu.
        notifyUsers(
            $pdo,
            array_diff($mentionedIds, $oldMentions),
            'Anda disebut di Forum ' . forumCrfLabel($crf),
            $actor . ': ' . mb_strimwidth($newComment, 0, 200, '…'),
            forumCommentUrl($crfId, $commentId),
            $crfId,
            null,
            $userId
        );

        logCrfActivity(
            $pdo,
            $crfId,
            'Komentar Forum Diubah',
            'Komentar Forum #' . $commentId . ' diubah oleh penulisnya, ' . $actor . '.',
            $actor
        );
        $message = 'Komentar berhasil diubah.';
    } else {
        $pdo->prepare(
            'UPDATE forum_comments
             SET deleted_at = NOW(), deleted_by_name = :deleted_by
             WHERE id = :id AND deleted_at IS NULL'
        )->execute(['deleted_by' => $actor, 'id' => $commentId]);

        $description = $isOwner
            ? 'Komentar Forum #' . $commentId . ' dihapus oleh penulisnya, ' . $actor . '.'
            : 'Komentar Forum #' . $commentId . ' milik ' . $target['user_name']
                . ' dihapus oleh Admin ' . $actor . ' (moderasi).';
        logCrfActivity($pdo, $crfId, 'Komentar Forum Dihapus', $description, $actor);
        $message = 'Komentar berhasil dihapus.';
    }

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Forum comment manage failed: ' . $exception->getMessage());
    $fail('Perubahan komentar gagal disimpan. Silakan coba lagi.');
}

$_SESSION['flash'] = ['type' => 'success', 'message' => $message];
header('Location: ' . $redirect);
exit;
