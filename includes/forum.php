<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

function forumRoles(): array
{
    return ['cmo', 'otomasi', 'admin', 'demo', 'kadep_operasional'];
}

function requireForumAccess(): void
{
    requireCrfRole(forumRoles());
}

/**
 * Hanya role Admin yang boleh menetapkan Level Urgensi dan SLA final di Forum.
 * Sengaja tidak memakai isAdmin(): akun demo dan hak Admin tambahan pada role lain tidak termasuk.
 */
function canManageForumFinalSla(): bool
{
    return getCrfRole() === 'admin';
}

function forumActiveCrfCondition(string $alias = 'cr'): string
{
    return "{$alias}.status NOT IN ('Draft', 'Solve', 'Cancel')"
        . " AND {$alias}.workflow_stage <> 'SELESAI'";
}

function forumUnreadCounts(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT comments.change_request_id, COUNT(*) AS unread_count
         FROM forum_comments comments
         INNER JOIN change_requests cr
            ON cr.id = comments.change_request_id
         LEFT JOIN forum_read_states read_state
            ON read_state.change_request_id = comments.change_request_id
           AND read_state.user_id = :read_user_id
         WHERE ' . forumActiveCrfCondition('cr') . '
           AND comments.user_id <> :comment_user_id
           AND comments.id > COALESCE(read_state.last_read_comment_id, 0)
         GROUP BY comments.change_request_id'
    );
    $stmt->execute([
        'read_user_id' => $userId,
        'comment_user_id' => $userId,
    ]);

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['change_request_id']] = (int) $row['unread_count'];
    }

    return $counts;
}

function forumUnreadTotal(PDO $pdo, int $userId): int
{
    return array_sum(forumUnreadCounts($pdo, $userId));
}

function markForumRead(PDO $pdo, int $crfId, int $userId, int $lastCommentId): void
{
    if ($lastCommentId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO forum_read_states
            (user_id, change_request_id, last_read_comment_id)
         VALUES
            (:user_id, :change_request_id, :last_read_comment_id)
         ON DUPLICATE KEY UPDATE
            last_read_comment_id = GREATEST(
                last_read_comment_id,
                VALUES(last_read_comment_id)
            ),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'user_id' => $userId,
        'change_request_id' => $crfId,
        'last_read_comment_id' => $lastCommentId,
    ]);
}

/**
 * SLA final Forum dikunci setelah Kadep Operasional menyetujui (tahap eksekusi),
 * sama seperti aturan SLA di sisi Handler.
 */
function isForumFinalSlaLocked(array $crf): bool
{
    return !empty($crf['kadep_operasional_approved_at']);
}

/**
 * Komentar otomatis dari penyimpanan kesepakatan urgensi & SLA.
 */
function forumSystemCommentPrefix(): string
{
    return 'Kesepakatan Forum diperbarui oleh ';
}

function isForumSystemComment(string $comment): bool
{
    return str_starts_with($comment, forumSystemCommentPrefix());
}

/**
 * Peserta ruang Forum: semua yang pernah berkomentar ditambah Handler yang memegang CRF.
 *
 * @return int[]
 */
function forumParticipantIds(PDO $pdo, int $crfId, ?int $assignedHandlerId = null): array
{
    $stmt = $pdo->prepare('SELECT DISTINCT user_id FROM forum_comments WHERE change_request_id = :crf_id');
    $stmt->execute(['crf_id' => $crfId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($assignedHandlerId) {
        $ids[] = $assignedHandlerId;
    }

    return array_values(array_unique($ids));
}

/**
 * Kirim notifikasi Forum tanpa menggagalkan aksi utama bila terjadi error.
 */
function notifyForumUsers(
    PDO $pdo,
    array $userIds,
    string $title,
    string $message,
    int $crfId,
    int $commentId,
    int $actorId
): void {
    try {
        notifyUsers(
            $pdo,
            $userIds,
            $title,
            $message,
            'forum/index.php?crf_id=' . $crfId . '#comment-' . $commentId,
            $crfId,
            null,
            $actorId
        );
    } catch (Throwable $exception) {
        error_log('Forum notification failed: ' . $exception->getMessage());
    }
}

/**
 * Waktu relatif singkat untuk daftar ruang Forum.
 */
function forumRelativeTime(?string $datetime): string
{
    if (empty($datetime)) {
        return '';
    }

    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' mnt';
    }
    if (date('Y-m-d', $time) === date('Y-m-d')) {
        return date('H:i', $time);
    }
    if (date('Y-m-d', $time) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Kemarin';
    }

    return date('d M', $time);
}

/**
 * Label pemisah tanggal di daftar komentar.
 */
function forumDateLabel(string $datetime): string
{
    $date = date('Y-m-d', strtotime($datetime));

    if ($date === date('Y-m-d')) {
        return 'Hari ini';
    }
    if ($date === date('Y-m-d', strtotime('-1 day'))) {
        return 'Kemarin';
    }

    return date('d M Y', strtotime($datetime));
}

function forumInitials(string $name): string
{
    $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $name))) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }

    return $initials !== '' ? $initials : '?';
}
