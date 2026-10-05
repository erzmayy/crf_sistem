<?php
require_once __DIR__ . '/../includes/forum.php';
require_once __DIR__ . '/../includes/functions.php';
requireForumAccess();

$pdo = getConnection();
$user = getCurrentUser();
$canManageFinalSla = canManageForumFinalSla();
$userId = (int) $user['id'];
$crfId = filter_input(INPUT_GET, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$search = mb_substr($search, 0, 100);
$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// URL halaman Forum dengan mempertahankan pencarian dan filter yang sedang aktif.
$forumUrl = static function (array $params = []) use ($search, $filter): string {
    $query = array_filter(
        array_merge(['q' => $search, 'filter' => $filter === 'unread' ? 'unread' : null], $params),
        static fn ($value): bool => $value !== null && $value !== '' && $value !== 0
    );

    return 'index.php' . ($query ? '?' . http_build_query($query) : '');
};

$activeCondition = forumActiveCrfCondition('cr');
$crfSql = "SELECT cr.id, cr.request_number, cr.full_name, cr.status, cr.workflow_stage,
            cr.kadep_operasional_approved_at,
            COALESCE(stats.comment_count, 0) AS comment_count,
            last_comment.user_name AS last_user_name,
            last_comment.comment AS last_comment,
            COALESCE(last_comment.created_at, cr.updated_at) AS last_activity_at
     FROM change_requests cr
     LEFT JOIN (
        SELECT change_request_id, COUNT(*) AS comment_count, MAX(id) AS last_comment_id
        FROM forum_comments
        GROUP BY change_request_id
     ) stats ON stats.change_request_id = cr.id
     LEFT JOIN forum_comments last_comment ON last_comment.id = stats.last_comment_id
     WHERE {$activeCondition}";
$searchParams = [];
if ($search !== '') {
    $crfSql .= " AND (cr.request_number LIKE :search_number
                      OR cr.full_name LIKE :search_name
                      OR CAST(cr.id AS CHAR) LIKE :search_id)";
    $searchTerm = '%' . $search . '%';
    $searchParams = [
        'search_number' => $searchTerm,
        'search_name' => $searchTerm,
        'search_id' => $searchTerm,
    ];
}
$crfSql .= ' ORDER BY last_activity_at DESC, cr.id DESC';
$crfStmt = $pdo->prepare($crfSql);
$crfStmt->execute($searchParams);
$activeCrfs = $crfStmt->fetchAll();
$unreadCounts = forumUnreadCounts($pdo, $userId);
$selectedCrf = null;
$comments = [];
$replyTo = null;
$errorMessage = null;
$lastReadCommentId = 0;
$standardSlaMatrix = [];

if ($crfId > 0) {
    $selectedStmt = $pdo->prepare(
        "SELECT cr.id, cr.request_number, cr.full_name, cr.status, cr.workflow_stage,
                cr.level, cr.impact_category, cr.final_urgency_level,
                cr.sla_value, cr.sla_unit, cr.sla_started_at, cr.sla_due_at,
                cr.kadep_operasional_approved_at, cr.automation_completed_at,
                cr.assigned_handler_name, cr.crf_category_id,
                cr.request_type, cr.from_department, cr.change_description
         FROM change_requests cr
         WHERE cr.id = :id AND {$activeCondition}
         LIMIT 1"
    );
    $selectedStmt->execute(['id' => $crfId]);
    $selectedCrf = $selectedStmt->fetch();

    if (!$selectedCrf) {
        http_response_code(404);
        $crfId = 0;
        $errorMessage = 'Forum hanya tersedia untuk CRF yang masih dalam proses.';
    } else {
        if (isset($_GET['reply_to'])) {
            $replyId = filter_input(INPUT_GET, 'reply_to', FILTER_VALIDATE_INT);
            if ($replyId) {
                $replyStmt = $pdo->prepare(
                    'SELECT id, user_name, comment
                     FROM forum_comments
                     WHERE id = :id AND change_request_id = :crf_id
                     LIMIT 1'
                );
                $replyStmt->execute(['id' => $replyId, 'crf_id' => $crfId]);
                $replyTo = $replyStmt->fetch() ?: null;
            }
        }

        $readStmt = $pdo->prepare(
            'SELECT last_read_comment_id
             FROM forum_read_states
             WHERE user_id = :user_id AND change_request_id = :crf_id'
        );
        $readStmt->execute(['user_id' => $userId, 'crf_id' => $crfId]);
        $lastReadCommentId = (int) $readStmt->fetchColumn();

        $commentStmt = $pdo->prepare(
            'SELECT comments.id, comments.user_id, comments.user_name,
                    comments.user_role, comments.comment,
                    comments.reply_to_comment_id, comments.created_at,
                    parent.user_name AS reply_user_name,
                    parent.comment AS reply_comment
             FROM forum_comments comments
             LEFT JOIN forum_comments parent
                ON parent.id = comments.reply_to_comment_id
             WHERE comments.change_request_id = :crf_id
             ORDER BY comments.created_at ASC, comments.id ASC'
        );
        $commentStmt->execute(['crf_id' => $crfId]);
        $comments = $commentStmt->fetchAll();
        $commentIds = array_map(
            static fn (array $comment): int => (int) $comment['id'],
            $comments
        );
        markForumRead(
            $pdo,
            $crfId,
            $userId,
            $commentIds ? max($commentIds) : 0
        );
        $unreadCounts[$crfId] = 0;

        if ($canManageFinalSla && !empty($selectedCrf['crf_category_id'])) {
            $category = findCrfCategory($pdo, (int) $selectedCrf['crf_category_id']);
            $standardSlaMatrix = $category ? crfCategorySlaMatrix($category) : [];
        }
    }
}

// Ruang dengan komentar belum dibaca tampil paling atas, lalu aktivitas terakhir.
usort($activeCrfs, static function (array $a, array $b) use ($unreadCounts): int {
    return (int) !empty($unreadCounts[(int) $b['id']]) <=> (int) !empty($unreadCounts[(int) $a['id']]);
});
$unreadRoomCount = count(array_filter(
    $activeCrfs,
    static fn (array $room): bool => !empty($unreadCounts[(int) $room['id']])
));
if ($filter === 'unread') {
    $activeCrfs = array_values(array_filter(
        $activeCrfs,
        static fn (array $room): bool => !empty($unreadCounts[(int) $room['id']]) || (int) $room['id'] === $crfId
    ));
}

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

        <div class="crf-forum-layout <?= $selectedCrf ? 'has-selection' : '' ?>">
            <aside class="crf-forum-rooms" aria-label="Ruang pembahasan">
                <div class="crf-forum-rooms-header">
                    <h2><i class="bi bi-chat-square-text" aria-hidden="true"></i> Ruang Pembahasan</h2>
                    <span class="crf-forum-count"><?= count($activeCrfs) ?></span>
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
                        <?php if ($filter === 'unread'): ?>
                            <input type="hidden" name="filter" value="unread">
                        <?php endif; ?>
                        <?php if ($search !== ''): ?>
                            <a class="crf-forum-search-clear" href="<?= h($forumUrl(['q' => null, 'crf_id' => $crfId])) ?>" aria-label="Hapus pencarian">
                                <i class="bi bi-x-circle-fill"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                <nav class="crf-forum-filter" aria-label="Filter ruang">
                    <a class="<?= $filter === 'all' ? 'active' : '' ?>" href="<?= h($forumUrl(['filter' => null, 'crf_id' => $crfId])) ?>">Semua</a>
                    <a class="<?= $filter === 'unread' ? 'active' : '' ?>" href="<?= h($forumUrl(['filter' => 'unread', 'crf_id' => $crfId])) ?>">
                        Belum dibaca
                        <?php if ($unreadRoomCount > 0): ?>
                            <span class="crf-forum-filter-badge"><?= $unreadRoomCount ?></span>
                        <?php endif; ?>
                    </a>
                </nav>

                <?php if (!$activeCrfs): ?>
                    <p class="crf-forum-rooms-empty">
                        <?php if ($search !== ''): ?>
                            Tidak ada ruang pembahasan yang cocok dengan pencarian.
                        <?php elseif ($filter === 'unread'): ?>
                            Semua komentar sudah dibaca.
                        <?php else: ?>
                            Belum ada CRF yang sedang dalam proses.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <nav class="crf-forum-room-list" aria-label="Daftar forum CRF">
                        <?php foreach ($activeCrfs as $room): ?>
                            <?php
                            $roomId = (int) $room['id'];
                            $roomUnread = (int) ($unreadCounts[$roomId] ?? 0);
                            $roomStatus = crfDisplayStatus($room);
                            ?>
                            <a class="crf-forum-room <?= $roomId === $crfId ? 'active' : '' ?> <?= $roomUnread > 0 ? 'is-unread' : '' ?>"
                               href="<?= h($forumUrl(['crf_id' => $roomId])) ?>"
                               <?= $roomId === $crfId ? 'aria-current="page"' : '' ?>>
                                <span class="crf-forum-room-top">
                                    <strong><?= h($room['request_number'] ?: 'CRF #' . $roomId) ?></strong>
                                    <time datetime="<?= h(date('c', strtotime($room['last_activity_at']))) ?>">
                                        <?= h(forumRelativeTime($room['last_activity_at'])) ?>
                                    </time>
                                </span>
                                <span class="crf-forum-room-name"><?= h($room['full_name']) ?></span>
                                <span class="crf-forum-room-preview">
                                    <?php if ($room['last_comment'] !== null): ?>
                                        <span class="crf-forum-room-author"><?= h($room['last_user_name']) ?>:</span>
                                        <?= h(mb_strimwidth((string) $room['last_comment'], 0, 70, '...')) ?>
                                    <?php else: ?>
                                        <em>Belum ada pembahasan</em>
                                    <?php endif; ?>
                                </span>
                                <span class="crf-forum-room-bottom">
                                    <span class="crf-badge <?= h($roomStatus['class']) ?>"><?= h($roomStatus['label']) ?></span>
                                    <span class="crf-forum-room-stats">
                                        <?php if ((int) $room['comment_count'] > 0): ?>
                                            <span title="Jumlah komentar"><i class="bi bi-chat" aria-hidden="true"></i> <?= (int) $room['comment_count'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($roomUnread > 0): ?>
                                            <span class="crf-forum-unread" aria-label="<?= $roomUnread ?> komentar baru">
                                                <?= $roomUnread > 99 ? '99+' : $roomUnread ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </aside>

            <section class="crf-forum-discussion" id="forum-comments">
                <?php if (!$selectedCrf): ?>
                    <div class="crf-forum-empty">
                        <i class="bi bi-chat-dots" aria-hidden="true"></i>
                        <h2>Pilih ruang CRF</h2>
                        <p>Pilih CRF di daftar untuk melihat riwayat pembahasan atau menambahkan tanggapan.</p>
                        <?php if ($unreadRoomCount > 0): ?>
                            <p class="crf-forum-empty-hint"><strong><?= $unreadRoomCount ?></strong> ruang memiliki komentar yang belum Anda baca.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php
                    $selectedStatus = crfDisplayStatus($selectedCrf);
                    $systemUrgency = crfUrgencyForImpact($selectedCrf['impact_category'] ?? null)
                        ?: ($selectedCrf['level'] ?? null);
                    $finalUrgency = $selectedCrf['final_urgency_level'] ?: $systemUrgency;
                    $slaStatus = getCrfSlaStatus($selectedCrf);
                    $isSlaLocked = isForumFinalSlaLocked($selectedCrf);
                    ?>
                    <header class="crf-forum-discussion-header">
                        <a class="crf-forum-back" href="<?= h($forumUrl()) ?>">
                            <i class="bi bi-arrow-left" aria-hidden="true"></i> Daftar ruang
                        </a>
                        <div class="crf-forum-title">
                            <div>
                                <h2><?= h($selectedCrf['request_number'] ?: 'CRF #' . $crfId) ?></h2>
                                <p>
                                    <?= h($selectedCrf['full_name']) ?>
                                    <?php if (!empty($selectedCrf['from_department'])): ?>
                                        · <?= h($selectedCrf['from_department']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($selectedCrf['request_type'])): ?>
                                        · <?= h($selectedCrf['request_type']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary" href="../crf/open.php?id=<?= $crfId ?>">
                                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Detail CRF
                            </a>
                        </div>
                    </header>

                    <dl class="crf-forum-info">
                        <div>
                            <dt>Status</dt>
                            <dd><span class="crf-badge <?= h($selectedStatus['class']) ?>"><?= h($selectedStatus['label']) ?></span></dd>
                        </div>
                        <?php $stageLabel = workflowStageLabel((string) $selectedCrf['workflow_stage']); ?>
                        <?php if ($stageLabel !== $selectedStatus['label']): ?>
                            <div>
                                <dt>Tahap</dt>
                                <dd><?= h($stageLabel) ?></dd>
                            </div>
                        <?php endif; ?>
                        <div>
                            <dt>Urgensi</dt>
                            <dd>
                                <span class="crf-badge <?= h(levelBadgeClass($finalUrgency)) ?>"><?= h($finalUrgency ?? 'Belum ditentukan') ?></span>
                                <small><?= $selectedCrf['final_urgency_level'] ? 'Final Forum' : 'Sistem' ?><?php if ($selectedCrf['final_urgency_level'] && $systemUrgency && $systemUrgency !== $finalUrgency): ?> · sistem: <?= h($systemUrgency) ?><?php endif; ?></small>
                            </dd>
                        </div>
                        <div>
                            <dt>SLA</dt>
                            <dd title="Dihitung pada hari kerja">
                                <?= h(slaLabel($selectedCrf['sla_value'], $selectedCrf['sla_unit'])) ?>
                                <?php if ($isSlaLocked): ?>
                                    <i class="bi bi-lock-fill crf-forum-lock" title="SLA terkunci pada tahap eksekusi" aria-label="Terkunci"></i>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Tenggat</dt>
                            <dd>
                                <?= !empty($selectedCrf['sla_due_at']) ? h(date('d M Y H:i', strtotime($selectedCrf['sla_due_at']))) : '-' ?>
                                <span class="crf-forum-tone crf-forum-tone-<?= h($slaStatus['class']) ?>"><?= h($slaStatus['label']) ?></span>
                            </dd>
                        </div>
                        <?php if (!empty($selectedCrf['assigned_handler_name'])): ?>
                            <div>
                                <dt>Handler</dt>
                                <dd><?= h($selectedCrf['assigned_handler_name']) ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>

                    <?php if (!empty($selectedCrf['change_description'])): ?>
                        <details class="crf-forum-summary">
                            <summary>Ringkasan permintaan</summary>
                            <p><?= nl2br(h($selectedCrf['change_description'])) ?></p>
                        </details>
                    <?php endif; ?>

                    <?php if ($canManageFinalSla): ?>
                        <?php if ($isSlaLocked): ?>
                            <div class="crf-forum-locked">
                                <i class="bi bi-lock" aria-hidden="true"></i>
                                <span>Urgensi dan SLA final terkunci karena CRF sudah disetujui Kepala Departemen Operasional
                                    pada <?= h(date('d M Y H:i', strtotime($selectedCrf['kadep_operasional_approved_at']))) ?> dan masuk tahap eksekusi.</span>
                            </div>
                        <?php else: ?>
                            <details class="crf-forum-final-sla">
                                <summary>
                                    <span>
                                        <strong>Penetapan Urgensi &amp; SLA Final</strong>
                                        <small>
                                            <?= empty($selectedCrf['final_urgency_level'])
                                                ? 'Belum ada kesepakatan — catat hasil diskusi di sini.'
                                                : 'Sudah disepakati: ' . h($finalUrgency) . ' · ' . h(slaLabel($selectedCrf['sla_value'], $selectedCrf['sla_unit'])) . '. Klik untuk mengubah.' ?>
                                        </small>
                                    </span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </summary>
                                <form method="post" action="../actions/forum_update_final_sla.php" id="forum-final-sla-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                                    <p class="crf-forum-final-sla-note">
                                        Hanya Admin yang dapat mencatat hasil kesepakatan Forum. Perubahan tidak mengubah tahap workflow
                                        dan akan terkunci setelah persetujuan Kepala Departemen Operasional.
                                    </p>
                                    <div class="crf-forum-final-sla-fields">
                                        <div>
                                            <label class="form-label" for="final-urgency-level">Level Urgensi Final</label>
                                            <select class="form-select" id="final-urgency-level" name="final_urgency_level" required>
                                                <?php foreach (['Tinggi', 'Normal', 'Rendah'] as $urgencyOption): ?>
                                                    <?php $standard = $standardSlaMatrix[$urgencyOption] ?? null; ?>
                                                    <option value="<?= h($urgencyOption) ?>"
                                                        <?= $finalUrgency === $urgencyOption ? 'selected' : '' ?>
                                                        <?php if ($standard): ?>
                                                            data-sla-value="<?= h(rtrim(rtrim(number_format($standard['value'], 2, '.', ''), '0'), '.')) ?>"
                                                            data-sla-unit="<?= h($standard['unit']) ?>"
                                                        <?php endif; ?>>
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
                                                value="<?= h($selectedCrf['sla_value'] !== null ? rtrim(rtrim((string) $selectedCrf['sla_value'], '0'), '.') : '') ?>"
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
                                    <p class="crf-forum-standard-sla" id="forum-standard-sla" hidden>
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                        <span></span>
                                        <button type="button" class="btn btn-link btn-sm p-0" id="forum-use-standard-sla">Pakai SLA standar</button>
                                    </p>
                                </form>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="crf-forum-message-list" id="forum-message-list" aria-live="polite">
                        <?php if (!$comments): ?>
                            <div class="crf-forum-no-messages">
                                <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                <p>Belum ada pembahasan. Mulai diskusi dengan menambahkan komentar di bawah.</p>
                            </div>
                        <?php else: ?>
                            <?php
                            $previousDate = null;
                            $unreadMarkerShown = false;
                            ?>
                            <?php foreach ($comments as $item): ?>
                                <?php
                                $itemId = (int) $item['id'];
                                $itemDate = date('Y-m-d', strtotime($item['created_at']));
                                $isOwn = (int) $item['user_id'] === $userId;
                                $isSystem = isForumSystemComment((string) $item['comment']);
                                $isNew = !$isOwn && $itemId > $lastReadCommentId;
                                ?>
                                <?php if ($itemDate !== $previousDate): ?>
                                    <div class="crf-forum-date"><span><?= h(forumDateLabel($item['created_at'])) ?></span></div>
                                    <?php $previousDate = $itemDate; ?>
                                <?php endif; ?>
                                <?php if ($isNew && !$unreadMarkerShown): ?>
                                    <div class="crf-forum-new-marker" id="forum-first-unread"><span>Komentar baru</span></div>
                                    <?php $unreadMarkerShown = true; ?>
                                <?php endif; ?>

                                <?php if ($isSystem): ?>
                                    <article class="crf-forum-system" id="comment-<?= $itemId ?>">
                                        <i class="bi bi-flag-fill" aria-hidden="true"></i>
                                        <div>
                                            <p><?= h($item['comment']) ?></p>
                                            <small><?= h($item['user_name']) ?> · <?= h(date('H:i', strtotime($item['created_at']))) ?></small>
                                        </div>
                                    </article>
                                <?php else: ?>
                                    <article class="crf-forum-message <?= $isOwn ? 'is-own' : '' ?> <?= $isNew ? 'is-new' : '' ?>" id="comment-<?= $itemId ?>">
                                        <span class="crf-forum-avatar crf-forum-role-<?= h($item['user_role']) ?>" aria-hidden="true"><?= h(forumInitials((string) $item['user_name'])) ?></span>
                                        <div class="crf-forum-bubble">
                                            <div class="crf-forum-message-heading">
                                                <strong><?= $isOwn ? 'Anda' : h($item['user_name']) ?></strong>
                                                <span class="crf-forum-role crf-forum-role-<?= h($item['user_role']) ?>"><?= h(crfRoleLabel($item['user_role'])) ?></span>
                                                <time datetime="<?= h(date('c', strtotime($item['created_at']))) ?>" title="<?= h(date('d M Y H:i', strtotime($item['created_at']))) ?>">
                                                    <?= h(date('H:i', strtotime($item['created_at']))) ?>
                                                </time>
                                            </div>
                                            <?php if (!empty($item['reply_to_comment_id'])): ?>
                                                <a class="crf-forum-reply-context" href="#comment-<?= (int) $item['reply_to_comment_id'] ?>">
                                                    <i class="bi bi-reply" aria-hidden="true"></i>
                                                    <strong><?= h($item['reply_user_name'] ?? 'komentar sebelumnya') ?></strong>
                                                    <span><?= h(mb_strimwidth((string) ($item['reply_comment'] ?? ''), 0, 140, '...')) ?></span>
                                                </a>
                                            <?php endif; ?>
                                            <div class="crf-forum-message-content"><?= nl2br(h($item['comment'])) ?></div>
                                            <a class="crf-forum-reply-link"
                                               href="<?= h($forumUrl(['crf_id' => $crfId, 'reply_to' => $itemId])) ?>#forum-form"
                                               data-reply-id="<?= $itemId ?>"
                                               data-reply-name="<?= h($item['user_name']) ?>"
                                               data-reply-text="<?= h(mb_strimwidth((string) $item['comment'], 0, 140, '...')) ?>">
                                                <i class="bi bi-reply" aria-hidden="true"></i> Tanggapi
                                            </a>
                                        </div>
                                    </article>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form class="crf-forum-form" id="forum-form" method="post" action="../actions/forum_comment.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                        <input type="hidden" name="reply_to_comment_id" id="forum-reply-id" value="<?= $replyTo ? (int) $replyTo['id'] : '' ?>">
                        <div class="crf-forum-replying" id="forum-replying" <?= $replyTo ? '' : 'hidden' ?>>
                            <span>
                                <i class="bi bi-reply" aria-hidden="true"></i>
                                Menanggapi <strong id="forum-replying-name"><?= $replyTo ? h($replyTo['user_name']) : '' ?></strong>:
                                <span id="forum-replying-text"><?= $replyTo ? h(mb_strimwidth((string) $replyTo['comment'], 0, 140, '...')) : '' ?></span>
                            </span>
                            <a href="<?= h($forumUrl(['crf_id' => $crfId])) ?>#forum-form" id="forum-reply-cancel" aria-label="Batalkan tanggapan"><i class="bi bi-x-lg"></i></a>
                        </div>
                        <label for="forum-comment" class="visually-hidden">Tambahkan komentar atau tanggapan</label>
                        <textarea class="form-control" id="forum-comment" name="comment" rows="3" maxlength="5000" required placeholder="Tulis pembahasan terkait data CRF, prioritas, urgensi, SLA, persetujuan, atau implementasi..."></textarea>
                        <div class="crf-forum-form-footer">
                            <small>
                                <span id="forum-comment-count">0</span>/5.000 · Ctrl+Enter untuk kirim ·
                                Peserta diskusi dan Handler CRF akan mendapat notifikasi.
                            </small>
                            <button type="submit" class="btn btn-crf-primary"><i class="bi bi-send"></i> Kirim</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
<script src="<?= h($appBasePath) ?>/assets/js/forum.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/forum.js') ?>" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
