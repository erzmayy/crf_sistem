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
    'SELECT id
     FROM change_requests cr
     WHERE cr.id = :id
       AND ' . forumActiveCrfCondition('cr') . '
     LIMIT 1'
);
$crfStmt->execute(['id' => $crfId]);

if (!$crfStmt->fetchColumn()) {
    http_response_code(404);
    exit('CRF tidak ditemukan atau sudah tidak dalam proses.');
}

if ($replyToId > 0) {
    $replyStmt = $pdo->prepare(
        'SELECT id
         FROM forum_comments
         WHERE id = :comment_id AND change_request_id = :crf_id
         LIMIT 1'
    );
    $replyStmt->execute([
        'comment_id' => $replyToId,
        'crf_id' => $crfId,
    ]);
    if (!$replyStmt->fetchColumn()) {
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
$insertStmt = $pdo->prepare(
    'INSERT INTO forum_comments
        (change_request_id, user_id, user_name, user_role, comment, reply_to_comment_id)
     VALUES
        (:change_request_id, :user_id, :user_name, :user_role, :comment, :reply_to_comment_id)'
);
try {
    $insertStmt->execute([
        'change_request_id' => $crfId,
        'user_id' => (int) $user['id'],
        'user_name' => (string) ($user['nama'] ?? 'User'),
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

$_SESSION['flash'] = [
    'type' => 'success',
    'message' => 'Komentar berhasil ditambahkan.',
];
header('Location: ../forum/index.php?crf_id=' . $crfId . '#comment-' . (int) $pdo->lastInsertId());
exit;
