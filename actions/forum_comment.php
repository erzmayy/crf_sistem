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

if ($crfId <= 0 || !is_string($comment) || trim($comment) === '' || mb_strlen($comment) > 5000) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Komentar wajib diisi dan maksimal 5.000 karakter.',
    ];
    header('Location: ../forum/index.php?crf_id=' . max(0, $crfId));
    exit;
}

$pdo = getConnection();
$crfStmt = $pdo->prepare(
    'SELECT id, request_number, assigned_handler_id
     FROM change_requests cr
     WHERE cr.id = :id
       AND ' . forumActiveCrfCondition('cr') . '
     LIMIT 1'
);
$crfStmt->execute(['id' => $crfId]);
$crf = $crfStmt->fetch();

if (!$crf) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Komentar tidak terkirim: CRF tidak ditemukan atau sudah tidak dalam proses.',
    ];
    header('Location: ../forum/index.php');
    exit;
}

$replyTarget = null;
if ($replyToId > 0) {
    $replyStmt = $pdo->prepare(
        'SELECT id, user_id
         FROM forum_comments
         WHERE id = :comment_id AND change_request_id = :crf_id
         LIMIT 1'
    );
    $replyStmt->execute([
        'comment_id' => $replyToId,
        'crf_id' => $crfId,
    ]);
    $replyTarget = $replyStmt->fetch() ?: null;
    if (!$replyTarget) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Komentar yang akan ditanggapi tidak ditemukan.',
        ];
        header('Location: ../forum/index.php?crf_id=' . $crfId);
        exit;
    }
} else {
    $replyToId = null;
}

$user = getCurrentUser();
$actorId = (int) $user['id'];
$actorName = (string) ($user['nama'] ?? 'User');
$insertStmt = $pdo->prepare(
    'INSERT INTO forum_comments
        (change_request_id, user_id, user_name, user_role, comment, reply_to_comment_id)
     VALUES
        (:change_request_id, :user_id, :user_name, :user_role, :comment, :reply_to_comment_id)'
);
try {
    $insertStmt->execute([
        'change_request_id' => $crfId,
        'user_id' => $actorId,
        'user_name' => $actorName,
        'user_role' => getCrfRole(),
        'comment' => trim($comment),
        'reply_to_comment_id' => $replyToId,
    ]);
} catch (PDOException $exception) {
    error_log('Forum comment insert failed: ' . $exception->getMessage());
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Komentar gagal disimpan. Silakan coba lagi atau hubungi administrator.',
    ];
    header('Location: ../forum/index.php?crf_id=' . $crfId);
    exit;
}

$commentId = (int) $pdo->lastInsertId();
$crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
$preview = mb_strimwidth(trim($comment), 0, 160, '...');
$replyAuthorId = $replyTarget ? (int) $replyTarget['user_id'] : 0;

// Penulis komentar yang dibalas mendapat notifikasi khusus balasan.
if ($replyAuthorId > 0) {
    notifyForumUsers(
        $pdo,
        [$replyAuthorId],
        'Balasan Forum: ' . $crfNumber,
        $actorName . ' membalas komentar Anda di Forum ' . $crfNumber . ': ' . $preview,
        $crfId,
        $commentId,
        $actorId
    );
}

$newCommentTitle = 'Komentar baru di Forum: ' . $crfNumber;
notifyForumUsers(
    $pdo,
    filterUsersWithoutUnread(
        $pdo,
        array_diff(forumParticipantIds($pdo, $crfId), [$replyAuthorId]),
        $crfId,
        $newCommentTitle
    ),
    $newCommentTitle,
    $actorName . ' menulis di Forum ' . $crfNumber . ': ' . $preview,
    $crfId,
    $commentId,
    $actorId
);
dispatchPendingNotificationEmails($pdo);

$_SESSION['flash'] = [
    'type' => 'success',
    'message' => 'Komentar berhasil ditambahkan.',
];
header('Location: ../forum/index.php?crf_id=' . $crfId . '#comment-' . $commentId);
exit;
