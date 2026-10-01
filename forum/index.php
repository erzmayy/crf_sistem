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
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$activeCondition = forumActiveCrfCondition('cr');
$crfSql = "SELECT cr.id, cr.request_number, cr.full_name, cr.status, cr.workflow_stage
     FROM change_requests cr
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
$crfSql .= ' ORDER BY cr.updated_at DESC, cr.id DESC';
$crfStmt = $pdo->prepare($crfSql);
$crfStmt->execute($searchParams);
$activeCrfs = $crfStmt->fetchAll();
$unreadCounts = forumUnreadCounts($pdo, $userId);
$selectedCrf = null;
$comments = [];
$replyTo = null;
$errorMessage = null;

if ($crfId > 0) {
    $selectedStmt = $pdo->prepare(
        "SELECT cr.id, cr.request_number, cr.full_name, cr.status, cr.workflow_stage,
                cr.level, cr.impact_category, cr.final_urgency_level,
                cr.sla_value, cr.sla_unit, cr.sla_started_at, cr.sla_due_at
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
    }
}

$pageTitle = 'Forum CRF';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page crf-forum-page">
    <div class="container">
        <div class="crf-page-header">
            <div>
                <h1>Forum CRF</h1>
                <p>Ruang diskusi lintas fungsi untuk cross-check data, prioritas, urgensi, SLA, approval, dan implementasi.</p>
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
                        <?php if ($search !== ''): ?>
                            <a class="crf-forum-search-clear" href="index.php<?= $crfId > 0 ? '?crf_id=' . $crfId : '' ?>" aria-label="Hapus pencarian">
                                <i class="bi bi-x-circle-fill"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="crf-section-body">
                    <?php if (!$activeCrfs): ?>
                        <p class="text-muted mb-0">
                            <?= $search !== ''
                                ? 'Tidak ada ruang pembahasan yang cocok dengan pencarian.'
                                : 'Belum ada CRF yang sedang dalam proses.' ?>
                        </p>
                    <?php else: ?>
                        <nav class="crf-forum-room-list" aria-label="Daftar forum CRF">
                            <?php foreach ($activeCrfs as $room): ?>
                                <?php $roomId = (int) $room['id']; ?>
                                <a class="crf-forum-room <?= $roomId === $crfId ? 'active' : '' ?>"
                                   href="index.php?crf_id=<?= $roomId ?>">
                                    <span class="crf-forum-room-main">
                                        <strong><?= h($room['request_number'] ?: 'CRF #' . $roomId) ?></strong>
                                        <span><?= h($room['full_name']) ?></span>
                                    </span>
                                    <span class="crf-forum-room-meta">
                                        <small><?= h($room['workflow_stage']) ?></small>
                                        <?php if (!empty($unreadCounts[$roomId])): ?>
                                            <span class="crf-forum-unread" aria-label="<?= (int) $unreadCounts[$roomId] ?> komentar baru">
                                                <?= (int) $unreadCounts[$roomId] > 99 ? '99+' : (int) $unreadCounts[$roomId] ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </nav>
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
                    <div class="crf-section-header crf-forum-discussion-header">
                        <div>
                            <span class="crf-section-number"><i class="bi bi-chat-square-text"></i></span>
                            <h2><?= h($selectedCrf['request_number'] ?: 'CRF #' . $crfId) ?></h2>
                            <p><?= h($selectedCrf['full_name']) ?> · <?= h($selectedCrf['workflow_stage']) ?></p>
                        </div>
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
                            <span><strong>SLA Final:</strong> <?= h((string) $selectedCrf['sla_value']) ?> <?= h($selectedCrf['sla_unit'] ?? '') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selectedCrf['sla_due_at'])): ?>
                            <span><strong>Tenggat:</strong> <?= h(date('d M Y H:i', strtotime($selectedCrf['sla_due_at']))) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($canManageFinalSla): ?>
                        <form class="crf-forum-final-sla" method="post" action="../actions/forum_update_final_sla.php">
                            <?= csrfField() ?>
                            <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                            <div class="crf-forum-final-sla-heading">
                                <div>
                                    <h3>Penetapan Urgensi &amp; SLA Final</h3>
                                    <p>Admin dan CMO dapat mencatat hasil kesepakatan Forum. Perubahan ini tidak mengubah tahap workflow.</p>
                                </div>
                                <?php if (!empty($selectedCrf['sla_started_at'])): ?>
                                    <span class="crf-forum-sla-note">SLA berjalan sejak <?= h(date('d M Y H:i', strtotime($selectedCrf['sla_started_at']))) ?>; tenggat dihitung ulang dari waktu mulai tersebut.</span>
                                <?php else: ?>
                                    <span class="crf-forum-sla-note">Perhitungan waktu SLA dimulai sesuai alur approval yang berlaku.</span>
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
                        <?php if (!$comments): ?>
                            <div class="crf-forum-no-messages">
                                <i class="bi bi-chat-left-text"></i>
                                <p>Belum ada pembahasan. Mulai diskusi dengan menambahkan komentar.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($comments as $item): ?>
                                <article class="crf-forum-message" id="comment-<?= (int) $item['id'] ?>">
                                    <div class="crf-forum-message-heading">
                                        <div>
                                            <strong><?= h($item['user_name']) ?></strong>
                                            <span class="crf-forum-role"><?= h(crfRoleLabel($item['user_role'])) ?></span>
                                        </div>
                                        <time datetime="<?= h(date('c', strtotime($item['created_at']))) ?>">
                                            <?= h(date('d M Y, H:i', strtotime($item['created_at']))) ?>
                                        </time>
                                    </div>
                                    <?php if (!empty($item['reply_to_comment_id'])): ?>
                                        <div class="crf-forum-reply-context">
                                            Menanggapi <strong><?= h($item['reply_user_name'] ?? 'komentar sebelumnya') ?></strong>:
                                            <?= h(mb_strimwidth((string) ($item['reply_comment'] ?? ''), 0, 180, '...')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="crf-forum-message-content"><?= nl2br(h($item['comment'])) ?></div>
                                    <a class="crf-forum-reply-link" href="index.php?crf_id=<?= $crfId ?>&amp;reply_to=<?= (int) $item['id'] ?>#forum-form">
                                        <i class="bi bi-reply"></i> Tanggapi
                                    </a>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form class="crf-forum-form" id="forum-form" method="post" action="../actions/forum_comment.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="crf_id" value="<?= $crfId ?>">
                        <?php if ($replyTo): ?>
                            <input type="hidden" name="reply_to_comment_id" value="<?= (int) $replyTo['id'] ?>">
                            <div class="crf-forum-replying">
                                <span>Menanggapi <strong><?= h($replyTo['user_name']) ?></strong>: <?= h(mb_strimwidth((string) $replyTo['comment'], 0, 140, '...')) ?></span>
                                <a href="index.php?crf_id=<?= $crfId ?>#forum-form" aria-label="Batalkan tanggapan"><i class="bi bi-x-lg"></i></a>
                            </div>
                        <?php endif; ?>
                        <label for="forum-comment" class="form-label">Tambahkan komentar atau tanggapan</label>
                        <textarea class="form-control" id="forum-comment" name="comment" rows="4" maxlength="5000" required placeholder="Tulis pembahasan terkait data CRF, prioritas, urgensi, SLA, approval, atau implementasi..."></textarea>
                        <div class="crf-forum-form-footer">
                            <small>Riwayat pembahasan dapat dilihat oleh CMO, Otomasi, Admin, dan Kepala Departemen Operasional.</small>
                            <button type="submit" class="btn btn-crf-primary"><i class="bi bi-send"></i> Kirim Komentar</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
