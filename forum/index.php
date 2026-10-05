<?php
require_once __DIR__ . '/../includes/forum.php';
requireForumAccess();

$pdo = getConnection();
$user = getCurrentUser();
$canManageFinalSla = canManageForumFinalSla();
$canResolve = canResolveForum();
$canModerate = isAdmin();
$userId = (int) $user['id'];
$crfId = filter_input(INPUT_GET, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$search = mb_substr($search, 0, 100);
$roomStatus = in_array($_GET['status'] ?? '', ['open', 'resolved'], true) ? $_GET['status'] : '';
$roomPage = max(1, (int) ($_GET['page'] ?? 1));
$commentLimit = (int) ($_GET['limit'] ?? FORUM_COMMENTS_PAGE_SIZE);
$commentLimit = max(FORUM_COMMENTS_PAGE_SIZE, min(1000, $commentLimit));
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/**
 * URL halaman Forum dengan mempertahankan pencarian, filter, halaman
 * ruang, dan jumlah komentar yang sedang ditampilkan.
 */
$forumUrl = static function (array $overrides = [], string $anchor = '') use ($crfId, $search, $roomStatus, $roomPage, $commentLimit): string {
    $params = array_merge([
        'crf_id' => $crfId ?: null,
        'q' => $search !== '' ? $search : null,
        'status' => $roomStatus !== '' ? $roomStatus : null,
        'page' => $roomPage > 1 ? $roomPage : null,
        'limit' => $commentLimit > FORUM_COMMENTS_PAGE_SIZE ? $commentLimit : null,
    ], $overrides);
    $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

    return 'index.php' . ($params ? '?' . http_build_query($params) : '') . ($anchor !== '' ? '#' . $anchor : '');
};

$selectedCrf = null;
$comments = [];
$hasOlderComments = false;
$attachmentsByComment = [];
$mentionNamesByComment = [];
$mentionCandidates = [];
$replyTo = null;
$errorMessage = null;

if ($crfId > 0) {
    $selectedCrf = findForumCrf($pdo, $crfId);

    if (!$selectedCrf) {
        http_response_code(404);
        $crfId = 0;
        $errorMessage = 'Forum hanya tersedia untuk CRF yang masih dalam proses.';
    } else {
        $replyId = filter_input(INPUT_GET, 'reply_to', FILTER_VALIDATE_INT) ?: 0;
        if ($replyId > 0) {
            $replyStmt = $pdo->prepare(
                'SELECT id, user_name, comment
                 FROM forum_comments
                 WHERE id = :id
                   AND change_request_id = :crf_id
                   AND deleted_at IS NULL
                 LIMIT 1'
            );
            $replyStmt->execute(['id' => $replyId, 'crf_id' => $crfId]);
            $replyTo = $replyStmt->fetch() ?: null;
        }

        $fetchLimit = $commentLimit + 1;
        $commentStmt = $pdo->prepare(
            "SELECT comments.id, comments.user_id, comments.user_name,
                    comments.user_role, comments.comment, comments.is_system,
                    comments.reply_to_comment_id, comments.created_at,
                    comments.edited_at, comments.deleted_at, comments.deleted_by_name,
                    parent.user_name AS reply_user_name,
                    parent.comment AS reply_comment,
                    parent.deleted_at AS reply_deleted_at
             FROM forum_comments comments
             LEFT JOIN forum_comments parent
                ON parent.id = comments.reply_to_comment_id
             WHERE comments.change_request_id = :crf_id
             ORDER BY comments.id DESC
             LIMIT {$fetchLimit}"
        );
        $commentStmt->execute(['crf_id' => $crfId]);
        $comments = $commentStmt->fetchAll();
        $hasOlderComments = count($comments) > $commentLimit;
        if ($hasOlderComments) {
            array_pop($comments);
        }
        $comments = array_reverse($comments);

        $commentIds = array_map(
            static fn (array $comment): int => (int) $comment['id'],
            $comments
        );
        $attachmentsByComment = forumAttachmentsByComment($pdo, $commentIds);
        $mentionNamesByComment = forumMentionNamesByComment($pdo, $commentIds);
        $mentionCandidates = forumMentionCandidates($pdo, $selectedCrf);
        unset($mentionCandidates[$userId]);

        markForumRead($pdo, $crfId, $userId, $commentIds ? max($commentIds) : 0);
    }
}

/*
 * Daftar ruang pembahasan: ruang dengan komentar belum dibaca tampil
 * paling atas, lalu yang terakhir diperbarui.
 */
$activeCondition = forumActiveCrfCondition('cr');
$roomWhere = $activeCondition;
$roomParams = [];
if ($search !== '') {
    $roomWhere .= ' AND (cr.request_number LIKE :search_number
                         OR cr.full_name LIKE :search_name
                         OR CAST(cr.id AS CHAR) LIKE :search_id)';
    $searchTerm = '%' . $search . '%';
    $roomParams = [
        'search_number' => $searchTerm,
        'search_name' => $searchTerm,
        'search_id' => $searchTerm,
    ];
}
if ($roomStatus === 'open') {
    $roomWhere .= ' AND cr.forum_resolved_at IS NULL';
} elseif ($roomStatus === 'resolved') {
    $roomWhere .= ' AND cr.forum_resolved_at IS NOT NULL';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM change_requests cr WHERE {$roomWhere}");
$countStmt->execute($roomParams);
$totalRooms = (int) $countStmt->fetchColumn();
$totalRoomPages = max(1, (int) ceil($totalRooms / FORUM_ROOMS_PER_PAGE));
$roomPage = min($roomPage, $totalRoomPages);
$roomOffset = ($roomPage - 1) * FORUM_ROOMS_PER_PAGE;
$roomLimit = FORUM_ROOMS_PER_PAGE;

$roomStmt = $pdo->prepare(
    "SELECT cr.id, cr.request_number, cr.full_name, cr.status, cr.workflow_stage,
            cr.forum_resolved_at,
            COALESCE(unread.unread_count, 0) AS unread_count
     FROM change_requests cr
     LEFT JOIN (
        SELECT comments.change_request_id, COUNT(*) AS unread_count
        FROM forum_comments comments
        LEFT JOIN forum_read_states read_state
           ON read_state.change_request_id = comments.change_request_id
          AND read_state.user_id = :read_user_id
        WHERE " . forumCountableCommentCondition('comments') . "
          AND comments.user_id <> :comment_user_id
          AND comments.id > COALESCE(read_state.last_read_comment_id, 0)
        GROUP BY comments.change_request_id
     ) unread ON unread.change_request_id = cr.id
     WHERE {$roomWhere}
     ORDER BY (COALESCE(unread.unread_count, 0) > 0) DESC, cr.updated_at DESC, cr.id DESC
     LIMIT {$roomLimit} OFFSET {$roomOffset}"
);
$roomStmt->execute($roomParams + [
    'read_user_id' => $userId,
    'comment_user_id' => $userId,
]);
$activeCrfs = $roomStmt->fetchAll();

$pageTitle = 'Forum CRF';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page crf-forum-page">
    <div class="container">
        <div class="crf-page-header">
            <div>
                <h1>Forum CRF</h1>
                <p>Ruang diskusi lintas fungsi untuk cross-check data, prioritas, urgensi, SLA, persetujuan, dan implementasi.</p>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert" role="alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-warning crf-alert" role="alert"><?= h($errorMessage) ?></div>
        <?php endif; ?>

        <div class="crf-forum-layout">
            <aside class="crf-section crf-forum-rooms">
                <div class="crf-section-header">
                    <span class="crf-section-number"><i class="bi bi-chat-square-text"></i></span>
                    <h2>Ruang Pembahasan</h2>
                </div>
                <div class="crf-forum-room-search">
                    <form method="get" action="index.php" role="search">
                        <label class="visually-hidden" for="forum-room-search">Cari ruang pembahasan</label>
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="forum-room-search"
                            name="q"
                            value="<?= h($search) ?>"
                            placeholder="Cari nomor CRF atau nama..."
                            autocomplete="off"
                        >
                        <?php if ($crfId > 0): ?>
                            <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                        <?php endif; ?>
                        <?php if ($roomStatus !== ''): ?>
                            <input type="hidden" name="status" value="<?= h($roomStatus) ?>">
                        <?php endif; ?>
                        <?php if ($search !== ''): ?>
                            <a class="crf-forum-search-clear" href="<?= h($forumUrl(['q' => null, 'page' => null])) ?>" aria-label="Hapus pencarian">
                                <i class="bi bi-x-circle-fill"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                    <nav class="crf-forum-room-filter" aria-label="Filter status pembahasan">
                        <?php foreach (['' => 'Semua', 'open' => 'Berjalan', 'resolved' => 'Selesai'] as $statusValue => $statusLabel): ?>
                            <a href="<?= h($forumUrl(['status' => $statusValue ?: null, 'page' => null])) ?>"
                               class="<?= $roomStatus === $statusValue ? 'active' : '' ?>"
                               <?= $roomStatus === $statusValue ? 'aria-current="true"' : '' ?>>
                                <?= h($statusLabel) ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                <div class="crf-section-body">
                    <?php if (!$activeCrfs): ?>
                        <p class="text-muted mb-0">
                            <?= ($search !== '' || $roomStatus !== '')
                                ? 'Tidak ada ruang pembahasan yang cocok dengan pencarian atau filter.'
                                : 'Belum ada CRF yang sedang dalam proses.' ?>
                        </p>
                    <?php else: ?>
                        <nav class="crf-forum-room-list" aria-label="Daftar forum CRF">
                            <?php foreach ($activeCrfs as $room): ?>
                                <?php
                                $roomId = (int) $room['id'];
                                $roomUnread = $roomId === $crfId ? 0 : (int) $room['unread_count'];
                                ?>
                                <a class="crf-forum-room <?= $roomId === $crfId ? 'active' : '' ?>"
                                   href="<?= h($forumUrl(['crf_id' => $roomId, 'limit' => null])) ?>">
                                    <span class="crf-forum-room-main">
                                        <strong><?= h($room['request_number'] ?: 'CRF #' . $roomId) ?></strong>
                                        <span><?= h($room['full_name']) ?></span>
                                    </span>
                                    <span class="crf-forum-room-meta">
                                        <small><?= h($room['workflow_stage']) ?></small>
                                        <?php if (!empty($room['forum_resolved_at'])): ?>
                                            <span class="crf-forum-resolved-badge" title="Pembahasan selesai">
                                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Selesai
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($roomUnread > 0): ?>
                                            <span class="crf-forum-unread" aria-label="<?= $roomUnread ?> komentar baru">
                                                <?= $roomUnread > 99 ? '99+' : $roomUnread ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                        <?php if ($totalRoomPages > 1): ?>
                            <nav class="crf-forum-room-pagination" aria-label="Halaman ruang pembahasan">
                                <?php if ($roomPage > 1): ?>
                                    <a href="<?= h($forumUrl(['page' => $roomPage - 1 > 1 ? $roomPage - 1 : null])) ?>" aria-label="Halaman sebelumnya">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="disabled"><i class="bi bi-chevron-left"></i></span>
                                <?php endif; ?>
                                <span>Hal. <?= $roomPage ?> / <?= $totalRoomPages ?> · <?= number_format($totalRooms, 0, ',', '.') ?> ruang</span>
                                <?php if ($roomPage < $totalRoomPages): ?>
                                    <a href="<?= h($forumUrl(['page' => $roomPage + 1])) ?>" aria-label="Halaman berikutnya">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="disabled"><i class="bi bi-chevron-right"></i></span>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="crf-section crf-forum-discussion" id="forum-comments">
                <?php if (!$selectedCrf): ?>
                    <div class="crf-section-body crf-forum-empty">
                        <i class="bi bi-chat-dots" aria-hidden="true"></i>
                        <h2>Pilih ruang CRF</h2>
                        <p>Pilih CRF di daftar untuk melihat riwayat pembahasan atau menambahkan tanggapan.</p>
                    </div>
                <?php else: ?>
                    <?php
                    $isResolved = !empty($selectedCrf['forum_resolved_at']);
                    $slaLocked = forumSlaLocked($selectedCrf);
                    ?>
                    <div class="crf-section-header crf-forum-discussion-header">
                        <div>
                            <span class="crf-section-number"><i class="bi bi-chat-square-text"></i></span>
                            <h2><?= h(forumCrfLabel($selectedCrf)) ?></h2>
                            <p><?= h($selectedCrf['full_name']) ?> · <?= h($selectedCrf['workflow_stage']) ?></p>
                        </div>
                        <?php if ($isResolved): ?>
                            <span class="crf-forum-resolved-badge crf-forum-resolved-badge-lg">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Pembahasan Selesai
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="crf-forum-context">
                        <span><strong>Status:</strong> <?= h($selectedCrf['status']) ?></span>
                        <?php
                        $systemUrgency = crfUrgencyForImpact($selectedCrf['impact_category'] ?? null)
                            ?: ($selectedCrf['level'] ?? null);
                        $finalUrgency = $selectedCrf['final_urgency_level'] ?: $systemUrgency;
                        ?>
                        <?php if ($systemUrgency !== null): ?>
                            <span><strong>Urgensi Sistem:</strong> <?= h($systemUrgency) ?></span>
                        <?php endif; ?>
                        <span><strong>Urgensi Final:</strong> <?= h($finalUrgency ?? 'Belum ditentukan') ?></span>
                        <?php if ($selectedCrf['sla_value'] !== null): ?>
                            <span><strong>SLA Final:</strong> <?= h(slaLabel($selectedCrf['sla_value'], $selectedCrf['sla_unit'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selectedCrf['sla_due_at'])): ?>
                            <span><strong>Tenggat:</strong> <?= h(date('d M Y H:i', strtotime($selectedCrf['sla_due_at']))) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($isResolved || $canResolve): ?>
                        <div class="crf-forum-resolution <?= $isResolved ? 'is-resolved' : '' ?>">
                            <?php if ($isResolved): ?>
                                <p>
                                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                    Pembahasan ditandai selesai oleh <strong><?= h($selectedCrf['forum_resolved_by_name'] ?? '-') ?></strong>
                                    pada <?= h(date('d M Y H:i', strtotime($selectedCrf['forum_resolved_at']))) ?>.
                                    Komentar baru tetap dapat ditambahkan.
                                </p>
                            <?php endif; ?>
                            <?php if ($canResolve): ?>
                                <form method="post" action="../actions/forum_resolve.php" class="crf-forum-resolution-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                                    <input type="hidden" name="operation" value="<?= $isResolved ? 'reopen' : 'resolve' ?>">
                                    <label class="visually-hidden" for="forum-resolution-note">
                                        <?= $isResolved ? 'Alasan membuka kembali' : 'Kesimpulan pembahasan' ?>
                                    </label>
                                    <input
                                        class="form-control form-control-sm"
                                        type="text"
                                        id="forum-resolution-note"
                                        name="note"
                                        maxlength="1000"
                                        placeholder="<?= $isResolved ? 'Alasan membuka kembali (opsional)' : 'Kesimpulan pembahasan (opsional)' ?>"
                                    >
                                    <button type="submit" class="btn btn-sm <?= $isResolved ? 'btn-crf-outline' : 'btn-crf-primary' ?>">
                                        <?php if ($isResolved): ?>
                                            <i class="bi bi-arrow-counterclockwise"></i> Buka Kembali
                                        <?php else: ?>
                                            <i class="bi bi-check2-circle"></i> Tandai Pembahasan Selesai
                                        <?php endif; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canManageFinalSla && $slaLocked): ?>
                        <div class="crf-forum-final-sla crf-forum-final-sla-locked">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            Urgensi dan SLA final terkunci karena CRF telah disetujui Kepala Departemen Operasional dan memasuki tahap eksekusi.
                        </div>
                    <?php elseif ($canManageFinalSla): ?>
                        <form class="crf-forum-final-sla" method="post" action="../actions/forum_update_final_sla.php">
                            <?= csrfField() ?>
                            <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                            <div class="crf-forum-final-sla-heading">
                                <div>
                                    <h3>Penetapan Urgensi &amp; SLA Final</h3>
                                    <p>Admin dan CMO dapat mencatat hasil kesepakatan Forum sebelum CRF disetujui Kepala Departemen Operasional. Perubahan ini tidak mengubah tahap workflow.</p>
                                </div>
                                <?php if (!empty($selectedCrf['sla_started_at'])): ?>
                                    <span class="crf-forum-sla-note">SLA berjalan sejak <?= h(date('d M Y H:i', strtotime($selectedCrf['sla_started_at']))) ?>; tenggat dihitung ulang dari waktu mulai tersebut.</span>
                                <?php else: ?>
                                    <span class="crf-forum-sla-note">Perhitungan waktu SLA dimulai sesuai alur persetujuan yang berlaku.</span>
                                <?php endif; ?>
                            </div>
                            <div class="crf-forum-final-sla-fields">
                                <div>
                                    <label class="form-label" for="final-urgency-level">Level Urgensi Final</label>
                                    <select class="form-select" id="final-urgency-level" name="final_urgency_level" required>
                                        <?php foreach (['Tinggi', 'Normal', 'Rendah'] as $urgencyOption): ?>
                                            <option value="<?= h($urgencyOption) ?>" <?= $finalUrgency === $urgencyOption ? 'selected' : '' ?>>
                                                <?= h($urgencyOption) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="final-sla-value">Nilai SLA</label>
                                    <input
                                        class="form-control"
                                        type="number"
                                        id="final-sla-value"
                                        name="sla_value"
                                        min="0.01"
                                        max="99999999.99"
                                        step="0.01"
                                        value="<?= h($selectedCrf['sla_value'] !== null ? (string) $selectedCrf['sla_value'] : '') ?>"
                                        required
                                    >
                                </div>
                                <div>
                                    <label class="form-label" for="final-sla-unit">Satuan SLA</label>
                                    <select class="form-select" id="final-sla-unit" name="sla_unit" required>
                                        <?php foreach (['Menit', 'Jam', 'Hari'] as $slaUnit): ?>
                                            <option value="<?= h($slaUnit) ?>" <?= ($selectedCrf['sla_unit'] ?? '') === $slaUnit ? 'selected' : '' ?>>
                                                <?= h($slaUnit) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="crf-forum-final-sla-submit">
                                    <button type="submit" class="btn btn-crf-primary">
                                        <i class="bi bi-check2-circle"></i> Simpan Kesepakatan
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div class="crf-forum-message-list">
                        <?php if ($hasOlderComments): ?>
                            <a class="crf-forum-load-older" href="<?= h($forumUrl(['limit' => $commentLimit + FORUM_COMMENTS_PAGE_SIZE], 'forum-comments')) ?>">
                                <i class="bi bi-arrow-up-circle"></i> Tampilkan komentar sebelumnya
                            </a>
                        <?php endif; ?>
                        <?php if (!$comments): ?>
                            <div class="crf-forum-no-messages">
                                <i class="bi bi-chat-left-text"></i>
                                <p>Belum ada pembahasan. Mulai diskusi dengan menambahkan komentar.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($comments as $item): ?>
                                <?php
                                $itemId = (int) $item['id'];
                                $isSystem = (bool) $item['is_system'];
                                $isDeleted = !empty($item['deleted_at']);
                                $isOwn = (int) $item['user_id'] === $userId;
                                $canEdit = $isOwn && !$isSystem && !$isDeleted;
                                $canDelete = ($isOwn || $canModerate) && !$isSystem && !$isDeleted;
                                $isEditing = $canEdit && $editId === $itemId;
                                $messageClass = $isSystem ? 'crf-forum-message-system' : ($isDeleted ? 'crf-forum-message-deleted' : '');
                                ?>
                                <article class="crf-forum-message <?= $messageClass ?>" id="comment-<?= $itemId ?>">
                                    <div class="crf-forum-message-heading">
                                        <div>
                                            <?php if ($isSystem): ?>
                                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                                <strong>Catatan Sistem</strong>
                                                <span class="crf-forum-role"><?= h($item['user_name']) ?></span>
                                            <?php else: ?>
                                                <strong><?= h($item['user_name']) ?></strong>
                                                <span class="crf-forum-role"><?= h(crfRoleLabel($item['user_role'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <time datetime="<?= h(date('c', strtotime($item['created_at']))) ?>">
                                            <?= h(date('d M Y, H:i', strtotime($item['created_at']))) ?>
                                            <?php if (!empty($item['edited_at']) && !$isDeleted): ?>
                                                <span class="crf-forum-edited" title="Diubah <?= h(date('d M Y, H:i', strtotime($item['edited_at']))) ?>">(diubah)</span>
                                            <?php endif; ?>
                                        </time>
                                    </div>

                                    <?php if ($isDeleted): ?>
                                        <div class="crf-forum-message-content">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                            Komentar ini telah dihapus<?= !empty($item['deleted_by_name']) ? ' oleh ' . h($item['deleted_by_name']) : '' ?>.
                                        </div>
                                    <?php else: ?>
                                        <?php if (!empty($item['reply_to_comment_id'])): ?>
                                            <div class="crf-forum-reply-context">
                                                <?php if (!empty($item['reply_deleted_at'])): ?>
                                                    Menanggapi komentar yang telah dihapus.
                                                <?php else: ?>
                                                    Menanggapi <strong><?= h($item['reply_user_name'] ?? 'komentar sebelumnya') ?></strong>:
                                                    <?= h(mb_strimwidth((string) ($item['reply_comment'] ?? ''), 0, 180, '...')) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($isEditing): ?>
                                            <form class="crf-forum-edit-form" method="post" action="../actions/forum_comment_manage.php">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="comment_id" value="<?= $itemId ?>">
                                                <input type="hidden" name="operation" value="edit">
                                                <label class="visually-hidden" for="forum-edit-<?= $itemId ?>">Ubah komentar</label>
                                                <textarea
                                                    class="form-control"
                                                    id="forum-edit-<?= $itemId ?>"
                                                    name="comment"
                                                    rows="4"
                                                    maxlength="<?= FORUM_COMMENT_MAX_LENGTH ?>"
                                                    required
                                                    data-forum-mention
                                                ><?= h($item['comment']) ?></textarea>
                                                <div class="crf-forum-edit-actions">
                                                    <a class="btn btn-sm btn-crf-outline" href="<?= h($forumUrl([], 'comment-' . $itemId)) ?>">Batal</a>
                                                    <button type="submit" class="btn btn-sm btn-crf-primary"><i class="bi bi-check2"></i> Simpan</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="crf-forum-message-content"><?= forumRenderComment((string) $item['comment'], $mentionNamesByComment[$itemId] ?? []) ?></div>
                                        <?php endif; ?>

                                        <?php if (!empty($attachmentsByComment[$itemId])): ?>
                                            <ul class="crf-forum-attachments">
                                                <?php foreach ($attachmentsByComment[$itemId] as $attachment): ?>
                                                    <li>
                                                        <a href="../actions/download_forum_attachment.php?id=<?= (int) $attachment['id'] ?>">
                                                            <i class="bi <?= str_contains((string) $attachment['file_type'], 'pdf') ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' ?>" aria-hidden="true"></i>
                                                            <?= h($attachment['original_name']) ?>
                                                        </a>
                                                        <?php if (forumFileSizeLabel($attachment['file_size']) !== ''): ?>
                                                            <small><?= h(forumFileSizeLabel($attachment['file_size'])) ?></small>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>

                                        <?php if (!$isSystem && !$isEditing): ?>
                                            <div class="crf-forum-message-actions">
                                                <a class="crf-forum-reply-link" href="<?= h($forumUrl(['reply_to' => $itemId], 'forum-form')) ?>">
                                                    <i class="bi bi-reply"></i> Tanggapi
                                                </a>
                                                <?php if ($canEdit): ?>
                                                    <a class="crf-forum-reply-link" href="<?= h($forumUrl(['edit' => $itemId], 'comment-' . $itemId)) ?>">
                                                        <i class="bi bi-pencil"></i> Ubah
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($canDelete): ?>
                                                    <form method="post" action="../actions/forum_comment_manage.php"
                                                          onsubmit="return confirm('<?= $isOwn ? 'Hapus komentar ini?' : 'Hapus komentar ini sebagai moderasi Admin? Tindakan ini tercatat di riwayat CRF.' ?>');">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="comment_id" value="<?= $itemId ?>">
                                                        <input type="hidden" name="operation" value="delete">
                                                        <button type="submit" class="crf-forum-delete-link">
                                                            <i class="bi bi-trash3"></i> Hapus
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form class="crf-forum-form" id="forum-form" method="post" action="../actions/forum_comment.php" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                        <?php if ($replyTo): ?>
                            <input type="hidden" name="reply_to_comment_id" value="<?= (int) $replyTo['id'] ?>">
                            <div class="crf-forum-replying">
                                <span>Menanggapi <strong><?= h($replyTo['user_name']) ?></strong>: <?= h(mb_strimwidth((string) $replyTo['comment'], 0, 140, '...')) ?></span>
                                <a href="<?= h($forumUrl([], 'forum-form')) ?>" aria-label="Batalkan tanggapan"><i class="bi bi-x-lg"></i></a>
                            </div>
                        <?php endif; ?>
                        <label for="forum-comment" class="form-label">Tambahkan komentar atau tanggapan</label>
                        <textarea
                            class="form-control"
                            id="forum-comment"
                            name="comment"
                            rows="4"
                            maxlength="<?= FORUM_COMMENT_MAX_LENGTH ?>"
                            required
                            data-forum-mention
                            placeholder="Tulis pembahasan terkait data CRF, prioritas, urgensi, SLA, persetujuan, atau implementasi... Ketik @ untuk menyebut pengguna."
                        ></textarea>
                        <div class="crf-forum-attachment-input">
                            <label for="forum-attachments" class="form-label">Lampiran (opsional)</label>
                            <input
                                class="form-control form-control-sm"
                                type="file"
                                id="forum-attachments"
                                name="attachments[]"
                                accept=".pdf,.jpg,.jpeg,.png"
                                multiple
                                data-max-files="<?= FORUM_MAX_ATTACHMENTS ?>"
                            >
                            <small>PDF/JPG/PNG, maksimal <?= FORUM_MAX_ATTACHMENTS ?> file, masing-masing 5 MB.</small>
                        </div>
                        <div class="crf-forum-form-footer">
                            <small>Riwayat pembahasan dapat dilihat oleh CMO, Otomasi, Admin, dan Kepala Departemen Operasional. Peserta pembahasan dan pengguna yang disebut akan menerima notifikasi.</small>
                            <button type="submit" class="btn btn-crf-primary"><i class="bi bi-send"></i> Kirim Komentar</button>
                        </div>
                    </form>
                    <script type="application/json" id="forum-mention-candidates"><?= json_encode(
                        array_values(array_map(
                            static fn (int $id, string $name): array => ['id' => $id, 'name' => $name],
                            array_keys($mentionCandidates),
                            $mentionCandidates
                        )),
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
                    ) ?></script>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
