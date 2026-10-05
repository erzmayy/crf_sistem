<?php
require_once __DIR__ . '/auth.php';

function forumRoles(): array
{
    return ['cmo', 'otomasi', 'admin', 'demo', 'kadep_operasional'];
}

function requireForumAccess(): void
{
    requireCrfRole(forumRoles());
}

function canManageForumFinalSla(): bool
{
    return isAdmin() || getCrfRole() === 'cmo';
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
