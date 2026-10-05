<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

const FORUM_ROOMS_PER_PAGE = 15;
const FORUM_COMMENTS_PAGE_SIZE = 30;
const FORUM_COMMENT_MAX_LENGTH = 5000;
const FORUM_MAX_ATTACHMENTS = 5;

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

/**
 * Admin & CMO dapat menandai pembahasan selesai / membukanya kembali.
 */
function canResolveForum(): bool
{
    return canManageForumFinalSla();
}

function forumActiveCrfCondition(string $alias = 'cr'): string
{
    return "{$alias}.status NOT IN ('Draft', 'Solve', 'Cancel')"
        . " AND {$alias}.workflow_stage <> 'SELESAI'";
}

/**
 * Urgensi & SLA final terkunci setelah Kepala Departemen Operasional
 * menyetujui CRF (tahap eksekusi), sama seperti aturan di
 * actions/automation_action.php.
 */
function forumSlaLocked(array $crf): bool
{
    return !empty($crf['kadep_operasional_approved_at'])
        || in_array($crf['workflow_stage'] ?? '', ['CMO_FINAL', 'SELESAI'], true);
}

/**
 * Komentar yang dihitung sebagai "belum dibaca": bukan komentar sistem
 * dan belum dihapus.
 */
function forumCountableCommentCondition(string $alias = 'comments'): string
{
    return "{$alias}.is_system = 0 AND {$alias}.deleted_at IS NULL";
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
           AND ' . forumCountableCommentCondition('comments') . '
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
    try {
        return array_sum(forumUnreadCounts($pdo, $userId));
    } catch (Throwable $e) {
        // Migrasi forum belum dijalankan: jangan sampai header ikut error.
        error_log('forumUnreadTotal: ' . $e->getMessage());
        return 0;
    }
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
 * CRF aktif untuk Forum, dikunci (FOR UPDATE) bila $forUpdate.
 */
function findForumCrf(PDO $pdo, int $crfId, bool $forUpdate = false): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, request_number, full_name, status, workflow_stage,
                level, impact_category, final_urgency_level,
                sla_value, sla_unit, sla_started_at, sla_due_at,
                crf_category_id, assigned_handler_id,
                kadep_operasional_approved_at,
                forum_resolved_at, forum_resolved_by_name
         FROM change_requests cr
         WHERE cr.id = :id
           AND ' . forumActiveCrfCondition('cr') . '
         LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['id' => $crfId]);
    $crf = $stmt->fetch();

    return $crf ?: null;
}

function forumCrfLabel(array $crf): string
{
    return (string) ($crf['request_number'] ?: 'CRF #' . (int) $crf['id']);
}

function forumCommentUrl(int $crfId, int $commentId = 0): string
{
    return 'forum/index.php?crf_id=' . $crfId . ($commentId > 0 ? '#comment-' . $commentId : '');
}

/**
 * Peserta pembahasan: semua yang pernah berkomentar (bukan komentar
 * sistem) ditambah Handler yang memegang CRF.
 *
 * @return int[]
 */
function forumParticipantIds(PDO $pdo, array $crf): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT user_id
         FROM forum_comments
         WHERE change_request_id = :crf_id
           AND is_system = 0
           AND deleted_at IS NULL'
    );
    $stmt->execute(['crf_id' => (int) $crf['id']]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($crf['assigned_handler_id'])) {
        $ids[] = (int) $crf['assigned_handler_id'];
    }

    return array_values(array_unique($ids));
}

/**
 * Pengguna yang dapat di-mention (@Nama) pada Forum suatu CRF:
 * pemegang role Forum, Handler kategori CRF, Handler yang ditugaskan,
 * dan peserta pembahasan.
 *
 * @return array<int, string> [user_id => nama]
 */
function forumMentionCandidates(PDO $pdo, array $crf): array
{
    $ids = forumParticipantIds($pdo, $crf);

    foreach (['cmo', 'otomasi', 'kadep_operasional', 'admin'] as $role) {
        $ids = array_merge($ids, crfUserIdsForRole($pdo, $role));
    }

    if (!empty($crf['crf_category_id'])) {
        $ids = array_merge($ids, crfCategoryHandlerIds($pdo, (int) $crf['crf_category_id']));
    }

    return forumUserNames($pdo, $ids);
}

/**
 * @param int[] $userIds
 * @return array<int, string> [user_id => nama]
 */
function forumUserNames(PDO $pdo, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) {
        return [];
    }

    $userTable = crfUserTable();
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT id, nama, userid FROM {$userTable} WHERE id IN ({$placeholders})");
    $stmt->execute($userIds);

    $names = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string) ($row['nama'] ?? '')) ?: trim((string) ($row['userid'] ?? ''));
        if ($name !== '') {
            $names[(int) $row['id']] = $name;
        }
    }
    asort($names, SORT_NATURAL | SORT_FLAG_CASE);

    return $names;
}

/**
 * Cari "@Nama" di komentar. Nama terpanjang dicocokkan lebih dulu agar
 * "@Budi Santoso" tidak terbaca sebagai "@Budi".
 *
 * @param array<int, string> $candidates [user_id => nama]
 * @return int[]
 */
function forumExtractMentions(string $comment, array $candidates): array
{
    uasort($candidates, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

    $mentioned = [];
    $remaining = $comment;
    foreach ($candidates as $userId => $name) {
        $pattern = '/@' . preg_quote($name, '/') . '(?![\p{L}\p{N}_])/iu';
        if (preg_match($pattern, $remaining)) {
            $mentioned[] = (int) $userId;
            $remaining = preg_replace($pattern, ' ', $remaining);
        }
    }

    return $mentioned;
}

/**
 * @param int[] $userIds
 */
function saveForumMentions(PDO $pdo, int $commentId, array $userIds): void
{
    $pdo->prepare('DELETE FROM forum_comment_mentions WHERE forum_comment_id = :id')
        ->execute(['id' => $commentId]);

    $stmt = $pdo->prepare(
        'INSERT INTO forum_comment_mentions (forum_comment_id, user_id)
         VALUES (:comment_id, :user_id)'
    );
    foreach (array_unique($userIds) as $userId) {
        $stmt->execute(['comment_id' => $commentId, 'user_id' => (int) $userId]);
    }
}

/**
 * Isi komentar siap tampil: di-escape, baris baru dipertahankan, dan
 * mention (@Nama) disorot.
 *
 * @param string[] $mentionNames
 */
function forumRenderComment(string $comment, array $mentionNames = []): string
{
    $html = h($comment);
    $mentionNames = array_values(array_unique(array_filter($mentionNames, 'strlen')));

    if ($mentionNames) {
        // Satu pola alternasi (nama terpanjang dulu) supaya tidak ada sorotan bertumpuk.
        usort($mentionNames, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $alternatives = array_map(static fn (string $name): string => preg_quote(h($name), '/'), $mentionNames);
        $pattern = '/@(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{N}_])/iu';
        $html = preg_replace($pattern, '<span class="crf-forum-mention">$0</span>', $html) ?? $html;
    }

    return nl2br($html);
}

/**
 * Simpan komentar sistem (tidak dihitung sebagai komentar belum dibaca).
 */
function addForumSystemComment(PDO $pdo, int $crfId, array $user, string $comment): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO forum_comments
            (change_request_id, user_id, user_name, user_role, comment, is_system)
         VALUES
            (:change_request_id, :user_id, :user_name, :user_role, :comment, 1)'
    );
    $stmt->execute([
        'change_request_id' => $crfId,
        'user_id' => (int) $user['id'],
        'user_name' => crfActorName($user),
        'user_role' => getCrfRole(),
        'comment' => $comment,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Notifikasi komentar baru. Penerima dibagi tiga kelompok agar
 * judulnya relevan: yang di-mention, penulis komentar yang ditanggapi,
 * dan peserta pembahasan lainnya. Penulis komentar tidak diberi tahu.
 *
 * @param int[] $mentionedIds
 */
function notifyForumComment(
    PDO $pdo,
    array $crf,
    int $commentId,
    array $author,
    string $comment,
    array $mentionedIds,
    ?int $replyToUserId
): void {
    $crfId = (int) $crf['id'];
    $authorId = (int) $author['id'];
    $actor = crfActorName($author);
    $label = forumCrfLabel($crf);
    $url = forumCommentUrl($crfId, $commentId);
    $excerpt = $actor . ': ' . mb_strimwidth($comment, 0, 200, '…');

    $mentionedIds = array_values(array_unique(array_map('intval', $mentionedIds)));
    notifyUsers($pdo, $mentionedIds, 'Anda disebut di Forum ' . $label, $excerpt, $url, $crfId, null, $authorId);

    $notified = $mentionedIds;
    if ($replyToUserId !== null && !in_array($replyToUserId, $notified, true)) {
        notifyUsers($pdo, [$replyToUserId], 'Komentar Anda ditanggapi di Forum ' . $label, $excerpt, $url, $crfId, null, $authorId);
        $notified[] = $replyToUserId;
    }

    $others = array_diff(forumParticipantIds($pdo, $crf), $notified);
    notifyUsers($pdo, $others, 'Komentar baru di Forum ' . $label, $excerpt, $url, $crfId, null, $authorId);
}

/**
 * Notifikasi perubahan penting pada Forum (kesepakatan SLA, status
 * pembahasan) kepada peserta pembahasan.
 */
function notifyForumParticipants(PDO $pdo, array $crf, array $actorUser, string $title, string $message, int $commentId = 0): void
{
    notifyUsers(
        $pdo,
        forumParticipantIds($pdo, $crf),
        $title,
        $message,
        forumCommentUrl((int) $crf['id'], $commentId),
        (int) $crf['id'],
        null,
        (int) $actorUser['id']
    );
}

/**
 * Upload lampiran komentar Forum (validasi & penyimpanan sama dengan
 * lampiran CRF).
 *
 * @return string[] daftar pesan error
 */
function handleForumAttachmentUploads(PDO $pdo, int $commentId, array $filesInput): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO forum_attachments
        (forum_comment_id, original_name, stored_name, file_path, file_type, file_size)
        VALUES
        (:owner_id, :original_name, :stored_name, :file_path, :file_type, :file_size)'
    );

    return uploadAttachmentsToStorage($filesInput, 'forum_' . $commentId . '_', $stmt, $commentId);
}

/**
 * Jumlah file yang benar-benar dipilih pada input multiple.
 */
function forumSelectedFileCount(array $filesInput): int
{
    if (empty($filesInput['error']) || !is_array($filesInput['error'])) {
        return 0;
    }

    return count(array_filter(
        $filesInput['error'],
        static fn ($error): bool => (int) $error !== UPLOAD_ERR_NO_FILE
    ));
}

/**
 * Lampiran per komentar.
 *
 * @param int[] $commentIds
 * @return array<int, array[]> [comment_id => [attachment, ...]]
 */
function forumAttachmentsByComment(PDO $pdo, array $commentIds): array
{
    $commentIds = array_values(array_filter(array_map('intval', $commentIds)));
    if (!$commentIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, forum_comment_id, original_name, file_type, file_size
         FROM forum_attachments
         WHERE forum_comment_id IN ({$placeholders})
         ORDER BY id"
    );
    $stmt->execute($commentIds);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['forum_comment_id']][] = $row;
    }

    return $grouped;
}

/**
 * Nama user yang di-mention per komentar.
 *
 * @param int[] $commentIds
 * @return array<int, string[]> [comment_id => [nama, ...]]
 */
function forumMentionNamesByComment(PDO $pdo, array $commentIds): array
{
    $commentIds = array_values(array_filter(array_map('intval', $commentIds)));
    if (!$commentIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT forum_comment_id, user_id
         FROM forum_comment_mentions
         WHERE forum_comment_id IN ({$placeholders})"
    );
    $stmt->execute($commentIds);
    $rows = $stmt->fetchAll();

    $names = forumUserNames($pdo, array_column($rows, 'user_id'));
    $grouped = [];
    foreach ($rows as $row) {
        $name = $names[(int) $row['user_id']] ?? null;
        if ($name !== null) {
            $grouped[(int) $row['forum_comment_id']][] = $name;
        }
    }

    return $grouped;
}

function forumFileSizeLabel($bytes): string
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes < 1024 * 1024) {
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}
