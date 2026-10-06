<?php
/**
 * forum/detail.php
 * Detail CRF read-only untuk peran Forum (tanpa form aksi).
 *   ?crf_id=ID&partial=1 : potongan HTML untuk panel samping di halaman Forum.
 *   ?crf_id=ID           : halaman penuh (fallback tanpa JavaScript).
 */
require_once __DIR__ . '/../includes/forum.php';
require_once __DIR__ . '/../includes/functions.php';
requireForumAccess();

$pdo = getConnection();
$crfId = filter_input(INPUT_GET, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$isPartial = ($_GET['partial'] ?? '') === '1';

if (!canViewForumCrf($pdo, $crfId)) {
    http_response_code(404);
    if ($isPartial) {
        echo '<div class="alert alert-warning m-3">Detail hanya tersedia untuk CRF yang masih dibahas di Forum.</div>';
        exit;
    }
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Detail hanya tersedia untuk CRF yang masih dibahas di Forum.'];
    header('Location: index.php');
    exit;
}

$crfStmt = $pdo->prepare('SELECT * FROM change_requests WHERE id = :id LIMIT 1');
$crfStmt->execute(['id' => $crfId]);
$crf = $crfStmt->fetch();

$attStmt = $pdo->prepare('SELECT * FROM attachments WHERE change_request_id = :id ORDER BY uploaded_at ASC');
$attStmt->execute(['id' => $crfId]);
$attachments = $attStmt->fetchAll();

$timelineStmt = $pdo->prepare('
    SELECT activity, description, actor, old_status, new_status, created_at
    FROM crf_activity_logs
    WHERE change_request_id = :id
    ORDER BY created_at ASC, id ASC
');
$timelineStmt->execute(['id' => $crfId]);
$timeline = $timelineStmt->fetchAll();

$displayStatus = crfDisplayStatus($crf);
$processLink = forumProcessLink($pdo, $crf);
$crfTitle = $crf['request_number'] ?: 'CRF #' . $crfId;

if (!$isPartial) {
    $pageTitle = 'Detail CRF ' . $crfTitle;
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="crf-page crf-detail-page"><div class="container">';
    echo '<div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">'
        . '<div><h1>Detail CRF ' . h($crfTitle) . '</h1><p>Tampilan baca saja untuk diskusi Forum.</p></div>'
        . '<a href="index.php?crf_id=' . $crfId . '" class="btn btn-crf-outline"><i class="bi bi-arrow-left"></i> Kembali ke Forum</a>'
        . '</div>';
}
?>
<div class="crf-forum-detail" data-crf-id="<?= $crfId ?>">
    <div class="crf-forum-detail-head">
        <div>
            <strong><?= h($crfTitle) ?></strong>
            <span><?= h($crf['full_name']) ?> · <?= h(workflowStageLabel((string) $crf['workflow_stage'])) ?></span>
        </div>
        <span class="crf-badge <?= h($displayStatus['class']) ?>"><?= h($displayStatus['label']) ?></span>
    </div>

    <ul class="nav nav-tabs crf-forum-detail-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#forum-detail-summary" type="button" role="tab" aria-controls="forum-detail-summary" aria-selected="true">Ringkasan</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#forum-detail-request" type="button" role="tab" aria-controls="forum-detail-request" aria-selected="false">
                Permintaan<?php if ($attachments): ?> <span class="crf-forum-detail-count" title="Jumlah lampiran"><i class="bi bi-paperclip"></i><?= count($attachments) ?></span><?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#forum-detail-sla" type="button" role="tab" aria-controls="forum-detail-sla" aria-selected="false">SLA</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#forum-detail-timeline" type="button" role="tab" aria-controls="forum-detail-timeline" aria-selected="false">Riwayat</button>
        </li>
    </ul>

    <div class="tab-content crf-forum-detail-body">
        <div class="tab-pane fade show active" id="forum-detail-summary" role="tabpanel">
            <?php require __DIR__ . '/../includes/partials/handler_assignment.php'; ?>
            <?php
            $showStatusInGrid = true;
            require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
            ?>
        </div>
        <div class="tab-pane fade" id="forum-detail-request" role="tabpanel">
            <?php require __DIR__ . '/../includes/partials/detail_permintaan.php'; ?>
        </div>
        <div class="tab-pane fade" id="forum-detail-sla" role="tabpanel">
            <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>
        </div>
        <div class="tab-pane fade" id="forum-detail-timeline" role="tabpanel">
            <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>
        </div>
    </div>

    <?php if ($processLink): ?>
        <div class="crf-forum-detail-footer">
            <span>Anda memiliki tindakan pada tahap ini.</span>
            <a href="<?= h($processLink['url']) ?>" class="btn btn-crf-primary btn-sm">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> <?= h($processLink['label']) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
<?php
if (!$isPartial) {
    echo '</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
}
