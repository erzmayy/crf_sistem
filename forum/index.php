<?php
require_once __DIR__ . '/../includes/forum_proposals.php';
requireForumAccess();

$pdo = getConnection();
$user = getCurrentUser();
$canDecide = canDecideForumProposal();
$userId = (int) $user['id'];
$crfId = filter_input(INPUT_GET, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$search = mb_substr($search, 0, 100);
$filterParam = in_array($_GET['filter'] ?? '', ['all', 'unread', 'discuss'], true) ? $_GET['filter'] : null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Pengingat usulan yang melewati batas keputusan (dijalankan saat Forum dibuka).
forumSendDueReminders($pdo);

$activeCondition = forumActiveCrfCondition('cr');
$crfSql = "SELECT cr.id, cr.request_number, cr.full_name, cr.status, cr.workflow_stage,
            cr.kadep_operasional_approved_at, cr.automation_started_at,
            COALESCE(stats.comment_count, 0) AS comment_count,
            last_comment.user_name AS last_user_name,
            last_comment.comment AS last_comment,
            COALESCE(last_comment.created_at, cr.updated_at) AS last_activity_at,
            (open_proposal.change_request_id IS NOT NULL) AS has_open_proposal,
            open_proposal.due_at AS open_proposal_due_at,
            EXISTS (
                SELECT 1 FROM forum_proposals agreed
                WHERE agreed.change_request_id = cr.id AND agreed.status = 'disetujui'
            ) AS has_agreement
     FROM change_requests cr
     LEFT JOIN (
        SELECT change_request_id, COUNT(*) AS comment_count, MAX(id) AS last_comment_id
        FROM forum_comments
        GROUP BY change_request_id
     ) stats ON stats.change_request_id = cr.id
     LEFT JOIN forum_comments last_comment ON last_comment.id = stats.last_comment_id
     LEFT JOIN (
        SELECT change_request_id, MIN(due_at) AS due_at
        FROM forum_proposals
        WHERE status = 'menunggu'
        GROUP BY change_request_id
     ) open_proposal ON open_proposal.change_request_id = cr.id
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
$openProposal = null;
$proposalHistory = [];
$proposalKind = null;

if ($crfId > 0) {
    $selectedStmt = $pdo->prepare(
        "SELECT cr.id, cr.request_number, cr.full_name, cr.status, cr.workflow_stage,
                cr.level, cr.impact_category, cr.final_urgency_level,
                cr.sla_value, cr.sla_unit, cr.sla_started_at, cr.sla_due_at,
                cr.kadep_operasional_approved_at, cr.automation_started_at, cr.automation_completed_at,
                cr.assigned_handler_name, cr.assigned_handler_id, cr.crf_category_id,
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
                    comments.user_role, comments.comment, comments.is_system,
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

        if (!empty($selectedCrf['crf_category_id'])) {
            $category = findCrfCategory($pdo, (int) $selectedCrf['crf_category_id']);
            $standardSlaMatrix = $category ? crfCategorySlaMatrix($category) : [];
        }

        $openProposal = forumOpenProposal($pdo, $crfId);
        $proposalHistory = forumProposalHistory($pdo, $crfId);
        $proposalKind = forumProposalKindFor($pdo, $selectedCrf);
    }
}

// Ruang "Perlu dibahas" (ada usulan terbuka) dan yang punya komentar belum dibaca tampil paling atas.
usort($activeCrfs, static function (array $a, array $b) use ($unreadCounts): int {
    return [(int) $b['has_open_proposal'], (int) !empty($unreadCounts[(int) $b['id']])]
        <=> [(int) $a['has_open_proposal'], (int) !empty($unreadCounts[(int) $a['id']])];
});
$unreadRoomCount = count(array_filter(
    $activeCrfs,
    static fn (array $room): bool => !empty($unreadCounts[(int) $room['id']])
));
$discussRoomCount = count(array_filter(
    $activeCrfs,
    static fn (array $room): bool => (int) $room['has_open_proposal'] === 1
));

// Bawaan: "Perlu dibahas" bila ada, selain itu "Semua".
$filter = $filterParam ?? ($discussRoomCount > 0 ? 'discuss' : 'all');
if ($filter === 'unread') {
    $activeCrfs = array_values(array_filter(
        $activeCrfs,
        static fn (array $room): bool => !empty($unreadCounts[(int) $room['id']]) || (int) $room['id'] === $crfId
    ));
} elseif ($filter === 'discuss') {
    $activeCrfs = array_values(array_filter(
        $activeCrfs,
        static fn (array $room): bool => (int) $room['has_open_proposal'] === 1 || (int) $room['id'] === $crfId
    ));
}

// URL halaman Forum dengan mempertahankan pencarian dan filter yang dipilih.
$forumUrl = static function (array $params = []) use ($search, $filterParam): string {
    $query = array_filter(
        array_merge(['q' => $search, 'filter' => $filterParam], $params),
        static fn ($value): bool => $value !== null && $value !== '' && $value !== 0
    );

    return 'index.php' . ($query ? '?' . http_build_query($query) : '');
};

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
                        <?php if ($filterParam !== null): ?>
                            <input type="hidden" name="filter" value="<?= h($filterParam) ?>">
                        <?php endif; ?>
                        <?php if ($search !== ''): ?>
                            <a class="crf-forum-search-clear" href="<?= h($forumUrl(['q' => null, 'crf_id' => $crfId])) ?>" aria-label="Hapus pencarian">
                                <i class="bi bi-x-circle-fill"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                <nav class="crf-forum-filter" aria-label="Filter ruang">
                    <a class="<?= $filter === 'discuss' ? 'active' : '' ?>" href="<?= h($forumUrl(['filter' => 'discuss', 'crf_id' => $crfId])) ?>" title="Ruang dengan usulan urgensi/SLA yang menunggu keputusan">
                        Perlu dibahas
                        <?php if ($discussRoomCount > 0): ?>
                            <span class="crf-forum-filter-badge is-amber"><?= $discussRoomCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="<?= $filter === 'unread' ? 'active' : '' ?>" href="<?= h($forumUrl(['filter' => 'unread', 'crf_id' => $crfId])) ?>">
                        Belum dibaca
                        <?php if ($unreadRoomCount > 0): ?>
                            <span class="crf-forum-filter-badge"><?= $unreadRoomCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="<?= $filter === 'all' ? 'active' : '' ?>" href="<?= h($forumUrl(['filter' => 'all', 'crf_id' => $crfId])) ?>">Semua</a>
                </nav>

                <?php if (!$activeCrfs): ?>
                    <p class="crf-forum-rooms-empty">
                        <?php if ($search !== ''): ?>
                            Tidak ada ruang pembahasan yang cocok dengan pencarian.
                        <?php elseif ($filter === 'unread'): ?>
                            Semua komentar sudah dibaca.
                        <?php elseif ($filter === 'discuss'): ?>
                            Tidak ada usulan urgensi/SLA yang menunggu keputusan.
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
                            $roomHasOpen = (int) $room['has_open_proposal'] === 1;
                            $roomOverdue = $roomHasOpen && !empty($room['open_proposal_due_at']) && strtotime($room['open_proposal_due_at']) < time();
                            ?>
                            <a class="crf-forum-room <?= $roomId === $crfId ? 'active' : '' ?> <?= $roomUnread > 0 ? 'is-unread' : '' ?> <?= $roomHasOpen ? 'has-proposal' : '' ?>"
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
                                        <?php if ($roomHasOpen): ?>
                                            <span class="crf-forum-room-flag <?= $roomOverdue ? 'is-overdue' : '' ?>" title="<?= $roomOverdue ? 'Usulan melewati batas keputusan' : 'Ada usulan urgensi/SLA yang menunggu keputusan' ?>"><i class="bi bi-flag-fill" aria-hidden="true"></i> Perlu dibahas</span>
                                        <?php elseif (!empty($room['has_agreement'])): ?>
                                            <span class="crf-forum-room-agreed" title="Urgensi/SLA sudah disepakati di Forum"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                                        <?php endif; ?>
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
                        <?php if ($discussRoomCount > 0): ?>
                            <p class="crf-forum-empty-hint is-amber"><strong><?= $discussRoomCount ?></strong> ruang memiliki usulan urgensi/SLA yang menunggu keputusan.</p>
                        <?php endif; ?>
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
                            <a class="btn btn-sm btn-outline-secondary"
                               href="detail.php?crf_id=<?= $crfId ?>"
                               data-forum-detail="detail.php?crf_id=<?= $crfId ?>&amp;partial=1"
                               aria-controls="forum-detail-panel">
                                <i class="bi bi-layout-sidebar-reverse" aria-hidden="true"></i> Detail CRF
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
                                    <i class="bi bi-lock-fill crf-forum-lock" title="Terkunci sejak disetujui; perpanjangan lewat usulan" aria-label="Terkunci"></i>
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

                    <?php
                    $fmtNum = static fn ($v): string => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
                    $matrix = $standardSlaMatrix;
                    $stdFor = static fn (?string $u): ?array => $u !== null ? ($matrix[$u] ?? null) : null;
                    $valuesText = static fn (?string $u, $v, ?string $unit, bool $withU = true): string => forumValuesText($u, $v, $unit, $withU);
                    $isApproved = !empty($selectedCrf['kadep_operasional_approved_at']);
                    $lastAgreed = null;
                    foreach ($proposalHistory as $historyItem) {
                        if ($historyItem['status'] === 'disetujui') {
                            $lastAgreed = $historyItem;
                            break;
                        }
                    }
                    $proposeOpen = ($_GET['propose'] ?? '') === '1';
                    $deciderLabel = implode(' / ', array_map('crfRoleLabel', forumDeciderRoles()));

                    // Kolom nilai (urgensi, nilai SLA, satuan). Opsi urgensi membawa SLA standar untuk petunjuk.
                    $slaFields = static function (bool $withUrgency, ?string $urgency, $value, ?string $unit) use ($matrix, $fmtNum): void {
                        ?>
                        <div class="crf-forum-final-sla-fields <?= $withUrgency ? '' : 'no-urgency' ?>">
                            <?php if ($withUrgency): ?>
                                <div>
                                    <label class="form-label">Level Urgensi</label>
                                    <select class="form-select" name="urgency" required>
                                        <?php foreach (FORUM_URGENCIES as $option): ?>
                                            <?php $standard = $matrix[$option] ?? null; ?>
                                            <option value="<?= h($option) ?>" <?= $urgency === $option ? 'selected' : '' ?>
                                                <?php if ($standard): ?>
                                                    data-sla-value="<?= h($fmtNum($standard['value'])) ?>"
                                                    data-sla-unit="<?= h($standard['unit']) ?>"
                                                <?php endif; ?>><?= h($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <div>
                                <label class="form-label"><?= $withUrgency ? 'Nilai SLA' : 'SLA baru (total)' ?></label>
                                <input class="form-control" type="number" name="sla_value" min="0.01" max="99999999.99" step="0.01" value="<?= h($fmtNum($value)) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">Satuan SLA</label>
                                <select class="form-select" name="sla_unit" required>
                                    <?php foreach (FORUM_SLA_UNITS as $option): ?>
                                        <option value="<?= h($option) ?>" <?= $unit === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <p class="crf-forum-standard-sla" data-standard-hint hidden>
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span></span>
                            <button type="button" class="btn btn-link btn-sm p-0" data-use-standard>Pakai SLA standar</button>
                        </p>
                        <?php
                    };
                    ?>

                    <section class="crf-forum-proposal" id="forum-proposal" aria-label="Usulan urgensi dan SLA">
                        <?php if ($openProposal): ?>
                            <?php
                            $pKind = $openProposal['kind'];
                            $withUrg = $pKind === 'urgensi_sla';
                            $overdue = forumProposalIsOverdue($openProposal);
                            $isAutoProposal = $openProposal['trigger_source'] === 'sistem';
                            $isProposer = !empty($openProposal['proposed_by']) && (int) $openProposal['proposed_by'] === $userId;
                            $sysSla = $stdFor($systemUrgency);
                            $curSla = $selectedCrf['sla_value'] !== null ? ['value' => $selectedCrf['sla_value'], 'unit' => $selectedCrf['sla_unit']] : null;
                            $defaultUrgency = $openProposal['proposed_urgency'] ?: $finalUrgency;
                            $defaultValue = $openProposal['proposed_sla_value'] ?? $selectedCrf['sla_value'];
                            $defaultUnit = $openProposal['proposed_sla_unit'] ?? ($selectedCrf['sla_unit'] ?: 'Hari');
                            if ($isAutoProposal && $selectedCrf['sla_value'] === null && $defaultUrgency && $stdFor($defaultUrgency)) {
                                $defaultValue = $stdFor($defaultUrgency)['value'];
                                $defaultUnit = $stdFor($defaultUrgency)['unit'];
                            }
                            ?>
                            <article class="crf-forum-proposal-card is-open <?= $overdue ? 'is-overdue' : '' ?>">
                                <header class="crf-forum-proposal-head">
                                    <div class="crf-forum-proposal-title">
                                        <span class="crf-forum-proposal-icon" aria-hidden="true"><i class="bi bi-flag-fill"></i></span>
                                        <div>
                                            <strong><?= $isAutoProposal ? 'SLA perlu ditetapkan' : 'Usulan ' . h(forumProposalKindLabel($pKind)) ?></strong>
                                            <small>
                                                <?= $isAutoProposal ? 'Dibuka otomatis oleh sistem' : 'Diajukan oleh ' . h($openProposal['proposed_by_name']) . ' (' . h(forumRoleName($openProposal['proposed_by_role'])) . ')' ?>
                                                · <?= h(date('d M Y H:i', strtotime($openProposal['created_at']))) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="crf-forum-proposal-meta">
                                        <span class="crf-forum-tone crf-forum-tone-<?= $overdue ? 'danger' : 'warning' ?>">Menunggu keputusan</span>
                                        <?php if (!empty($openProposal['due_at'])): ?>
                                            <small class="crf-forum-due <?= $overdue ? 'is-overdue' : '' ?>">Batas keputusan <?= h(date('d M Y H:i', strtotime($openProposal['due_at']))) ?><?= $overdue ? ' · terlewat' : '' ?></small>
                                        <?php endif; ?>
                                    </div>
                                </header>

                                <div class="crf-forum-compare-wrap">
                                    <table class="crf-forum-compare">
                                        <thead>
                                            <tr><th></th><th>Standar sistem</th><th>Berlaku saat ini</th><th>Usulan</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($withUrg): ?>
                                                <tr>
                                                    <th>Urgensi</th>
                                                    <td><?= h($systemUrgency ?? '-') ?></td>
                                                    <td><?= h($finalUrgency ?? '-') ?></td>
                                                    <td class="is-proposed"><?= h($openProposal['proposed_urgency'] ?? '-') ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <th>SLA</th>
                                                <td><?= h($sysSla ? slaLabel($sysSla['value'], $sysSla['unit']) : '-') ?></td>
                                                <td>
                                                    <?php if ($curSla): ?>
                                                        <?= h(slaLabel($curSla['value'], $curSla['unit'])) ?>
                                                    <?php elseif ($stdFor($finalUrgency)): ?>
                                                        <span class="text-muted">Belum diisi · standar <?= h(slaLabel($stdFor($finalUrgency)['value'], $stdFor($finalUrgency)['unit'])) ?></span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="is-proposed"><?= h($openProposal['proposed_sla_value'] !== null ? slaLabel($openProposal['proposed_sla_value'], $openProposal['proposed_sla_unit']) : '-') ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <p class="crf-forum-proposal-reason"><strong>Alasan:</strong> <?= nl2br(h($openProposal['reason'])) ?></p>
                                <p class="crf-forum-proposal-hint">
                                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    Usulan tidak menahan proses CRF. Selama belum diputuskan, nilai yang berlaku tidak berubah.
                                    <?php if ($selectedCrf['workflow_stage'] === 'kadep_operasional'): ?>
                                        Usulan ditutup otomatis saat Kepala Departemen Operasional menyetujui.
                                    <?php endif; ?>
                                </p>

                                <?php if ($canDecide): ?>
                                    <form method="post" action="../actions/forum_proposal.php" class="crf-forum-proposal-decide" data-sla-form data-decide-form>
                                        <?= csrfField() ?>
                                        <input type="hidden" name="op" value="decide">
                                        <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                                        <input type="hidden" name="proposal_id" value="<?= (int) $openProposal['id'] ?>">
                                        <h3>Keputusan <?= h($deciderLabel) ?></h3>
                                        <p class="crf-forum-final-sla-note">Nilai di bawah terisi dari usulan dan boleh disesuaikan sebelum disetujui.</p>
                                        <?php $slaFields($withUrg, $defaultUrgency, $defaultValue, $defaultUnit); ?>
                                        <label class="form-label mt-2" for="forum-decision-note">Catatan keputusan <small class="text-muted">(wajib bila menolak)</small></label>
                                        <textarea class="form-control" id="forum-decision-note" name="note" rows="2" maxlength="1000" placeholder="Alasan persetujuan atau penolakan..."></textarea>
                                        <div class="crf-forum-proposal-actions">
                                            <button type="submit" name="decision" value="approve" class="btn btn-crf-primary"><i class="bi bi-check2-circle"></i> Setujui</button>
                                            <button type="submit" name="decision" value="reject" class="btn btn-outline-danger" formnovalidate data-reject><i class="bi bi-x-circle"></i> Tolak</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <p class="crf-forum-proposal-wait"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Menunggu keputusan <?= h($deciderLabel) ?>. Anda dapat menambahkan pertimbangan pada kolom komentar di bawah.</p>
                                <?php endif; ?>

                                <?php if ($isProposer || $canDecide): ?>
                                    <form method="post" action="../actions/forum_proposal.php" class="crf-forum-proposal-cancel" data-confirm="Batalkan usulan ini?">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="op" value="cancel">
                                        <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                                        <input type="hidden" name="proposal_id" value="<?= (int) $openProposal['id'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-x-lg"></i> Batalkan usulan</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php else: ?>
                            <div class="crf-forum-proposal-bar">
                                <?php if ($lastAgreed): ?>
                                    <i class="bi bi-check2-circle text-success" aria-hidden="true"></i>
                                    <span>
                                        <strong>Kesepakatan terakhir:</strong>
                                        <?= h($valuesText($lastAgreed['final_urgency'], $lastAgreed['final_sla_value'], $lastAgreed['final_sla_unit'], $lastAgreed['kind'] === 'urgensi_sla')) ?>
                                        · <?= h($lastAgreed['decided_by_name'] ?? '-') ?>
                                        · <?= h(date('d M Y H:i', strtotime($lastAgreed['decided_at'] ?? $lastAgreed['created_at']))) ?>
                                    </span>
                                <?php elseif (!empty($selectedCrf['final_urgency_level'])): ?>
                                    <i class="bi bi-check2-circle text-success" aria-hidden="true"></i>
                                    <span><strong>Urgensi final:</strong> <?= h($selectedCrf['final_urgency_level']) ?> · SLA <?= h(slaLabel($selectedCrf['sla_value'], $selectedCrf['sla_unit'])) ?></span>
                                <?php else: ?>
                                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    <span>Belum ada pembahasan urgensi/SLA. Nilai yang berlaku mengikuti standar sistem.</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($proposalKind !== null && $canDecide): ?>
                                <details class="crf-forum-propose" <?= $proposeOpen ? 'open' : '' ?>>
                                    <summary><i class="bi bi-sliders" aria-hidden="true"></i> <?= $proposalKind === 'urgensi_sla' ? 'Tetapkan Urgensi &amp; SLA' : 'Perpanjang SLA' ?></summary>
                                    <form method="post" action="../actions/forum_proposal.php" data-sla-form>
                                        <?= csrfField() ?>
                                        <input type="hidden" name="op" value="direct">
                                        <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                                        <p class="crf-forum-final-sla-note">
                                            Anda dapat menetapkan nilai langsung. Tetap tercatat di riwayat usulan dan peserta diskusi diberi tahu.
                                            <?= $proposalKind === 'urgensi_sla'
                                                ? 'Nilai terkunci setelah Kepala Departemen Operasional menyetujui.'
                                                : 'SLA baru adalah total sejak SLA dimulai dan harus lebih lama dari SLA saat ini; batas SLA dihitung ulang.' ?>
                                        </p>
                                        <?php $slaFields($proposalKind === 'urgensi_sla', $finalUrgency, $selectedCrf['sla_value'], $selectedCrf['sla_unit'] ?: 'Hari'); ?>
                                        <label class="form-label mt-2">Alasan <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="reason" rows="2" maxlength="1000" required placeholder="Dasar penetapan nilai..."></textarea>
                                        <div class="crf-forum-proposal-actions">
                                            <button type="submit" class="btn btn-crf-primary"><i class="bi bi-check2-circle"></i> Simpan</button>
                                        </div>
                                    </form>
                                </details>
                            <?php elseif ($proposalKind !== null): ?>
                                <details class="crf-forum-propose" <?= $proposeOpen ? 'open' : '' ?>>
                                    <summary><i class="bi bi-flag" aria-hidden="true"></i> <?= $proposalKind === 'urgensi_sla' ? 'Ajukan Pembahasan Urgensi &amp; SLA' : 'Ajukan Perpanjangan SLA' ?></summary>
                                    <form method="post" action="../actions/forum_proposal.php" data-sla-form>
                                        <?= csrfField() ?>
                                        <input type="hidden" name="op" value="create">
                                        <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                                        <p class="crf-forum-final-sla-note">
                                            Usulan dikirim ke <?= h($deciderLabel) ?> untuk diputuskan paling lambat
                                            <?= (int) FORUM_PROPOSAL_DECISION_DAYS ?> hari kerja. Usulan tidak menahan proses CRF.
                                            <?= $proposalKind === 'perpanjangan_sla' ? 'SLA baru adalah total sejak SLA dimulai dan harus lebih lama dari SLA saat ini.' : '' ?>
                                        </p>
                                        <?php $slaFields($proposalKind === 'urgensi_sla', $finalUrgency, $selectedCrf['sla_value'], $selectedCrf['sla_unit'] ?: 'Hari'); ?>
                                        <label class="form-label mt-2">Alasan <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="reason" rows="3" maxlength="1000" required placeholder="Jelaskan mengapa nilai perlu diubah..."></textarea>
                                        <div class="crf-forum-proposal-actions">
                                            <button type="submit" class="btn btn-crf-primary"><i class="bi bi-send"></i> Kirim Usulan</button>
                                        </div>
                                    </form>
                                </details>
                            <?php else: ?>
                                <p class="crf-forum-locked">
                                    <i class="bi bi-lock" aria-hidden="true"></i>
                                    <span>
                                        <?php if ($isApproved): ?>
                                            Urgensi dan SLA terkunci sejak disetujui Kepala Departemen Operasional
                                            (<?= h(date('d M Y H:i', strtotime($selectedCrf['kadep_operasional_approved_at']))) ?>).
                                            Perpanjangan SLA hanya dapat diajukan Petugas Otomasi pemegang CRF atau Admin selama CRF dikerjakan.
                                        <?php else: ?>
                                            Usulan urgensi/SLA dapat diajukan oleh CMO, Admin, atau Petugas Otomasi kategori ini sebelum CRF disetujui.
                                            Anda tetap dapat berdiskusi pada kolom komentar.
                                        <?php endif; ?>
                                    </span>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($proposalHistory): ?>
                            <details class="crf-forum-proposal-history">
                                <summary>Riwayat usulan <span class="crf-forum-detail-count"><?= count($proposalHistory) ?></span></summary>
                                <ul>
                                    <?php foreach ($proposalHistory as $item): ?>
                                        <?php
                                        $hMeta = forumProposalStatusMeta($item['status']);
                                        $hWithUrg = $item['kind'] === 'urgensi_sla';
                                        ?>
                                        <li>
                                            <div class="crf-forum-history-top">
                                                <span class="crf-forum-tone crf-forum-tone-<?= h($hMeta['class']) ?>"><?= h($hMeta['label']) ?></span>
                                                <strong><?= h(forumProposalKindLabel($item['kind'])) ?></strong>
                                                <?php if ($item['trigger_source'] === 'langsung'): ?><small>(ditetapkan langsung)</small><?php endif; ?>
                                                <time><?= h(date('d M Y H:i', strtotime($item['decided_at'] ?? $item['created_at']))) ?></time>
                                            </div>
                                            <div class="crf-forum-history-values">
                                                <?php if ($item['proposed_sla_value'] !== null): ?>
                                                    Usulan: <?= h($valuesText($item['proposed_urgency'], $item['proposed_sla_value'], $item['proposed_sla_unit'], $hWithUrg)) ?>
                                                <?php endif; ?>
                                                <?php if ($item['status'] === 'disetujui' && $item['final_sla_value'] !== null): ?>
                                                    · <strong>Ditetapkan: <?= h($valuesText($item['final_urgency'], $item['final_sla_value'], $item['final_sla_unit'], $hWithUrg)) ?></strong>
                                                <?php endif; ?>
                                            </div>
                                            <small>
                                                <?= h($item['proposed_by_name']) ?>
                                                <?php if (!empty($item['decided_by_name'])): ?> → <?= h($item['decided_by_name']) ?><?php endif; ?>
                                                <?php if (!empty($item['decision_note'])): ?> · <?= h($item['decision_note']) ?><?php endif; ?>
                                            </small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </section>

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
                                $isSystem = !empty($item['is_system']) || isForumSystemComment((string) $item['comment']);
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

<div class="offcanvas offcanvas-end crf-forum-detail-panel" tabindex="-1" id="forum-detail-panel" aria-labelledby="forum-detail-panel-title">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="forum-detail-panel-title"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Detail CRF</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
    </div>
    <div class="offcanvas-body" id="forum-detail-panel-body"></div>
</div>
<script src="<?= h($appBasePath) ?>/assets/js/forum.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/forum.js') ?>" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
