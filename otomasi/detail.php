<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['otomasi']);

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT cr.*
    FROM change_requests cr
    WHERE cr.id = :id
      AND cr.workflow_stage = 'OTOMASI'
      AND cr.kadep_operasional_approved_at IS NOT NULL
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

// Handler hanya boleh memproses CRF pada kategori yang ditanganinya.
if ($crf && !canHandleCrf($pdo, $crf)) {
    $crf = false;
}

if (!$crf) {
    http_response_code(404);

    $pageTitle = 'CRF Tidak Ditemukan';

    require_once __DIR__ . '/../includes/header.php';

    echo '
        <div class="crf-page">
            <div class="container">

                <div class="alert alert-danger">
                    CRF tidak ditemukan pada antrean Otomasi.
                </div>

                <a href="index.php" class="btn btn-crf-outline">
                    <i class="bi bi-arrow-left"></i>
                    Kembali
                </a>

            </div>
        </div>
    ';

    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$attStmt = $pdo->prepare(
    'SELECT *
     FROM attachments
     WHERE change_request_id = :id
     ORDER BY uploaded_at ASC'
);
$attStmt->execute(['id' => $id]);
$attachments = $attStmt->fetchAll();

$timelineStmt = $pdo->prepare("
    SELECT activity, description, actor, old_status, new_status, created_at
    FROM crf_activity_logs
    WHERE change_request_id = :id
    ORDER BY created_at ASC, id ASC
");
$timelineStmt->execute(['id' => $id]);
$timeline = $timelineStmt->fetchAll();

// Disetujui tetapi belum "Mulai Kerjakan": CRF masih di antrean, SLA belum berjalan.
$isQueued = crfIsQueued($crf);
$isAssignedToOther = !empty($crf['assigned_handler_id']) && !isAssignedCrfHandler($crf);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = $isQueued
    ? 'Otomasi - Antrean CRF'
    : 'Otomasi - Eksekusi CRF';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-detail-page">

    <div class="container">

        <div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">

            <div>

                <h1>
                    <?= $isQueued
                        ? 'Tindak Lanjut Otomasi · Antrean'
                        : 'Tindak Lanjut Otomasi · Implementasi'
                    ?>
                </h1>

                <p>
                    Nomor Register:
                    <strong><?= h($crf['request_number']) ?></strong>
                    ·
                    Pengaju:
                    <?= h($crf['full_name']) ?>
                </p>

            </div>

            <a
                href="index.php"
                class="btn btn-crf-outline"
            >
                <i class="bi bi-arrow-left"></i>
                Kembali
            </a>

        </div>


        <?php if ($flash): ?>

            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>

        <?php endif; ?>


        <div class="d-flex gap-2 flex-wrap mb-4">
            <?php $resolvedUrgencyLevel = crfEffectiveUrgency($crf); ?>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['workflow_stage']) ?>">
                Tahap:
                <?= h(workflowStageLabel($crf['workflow_stage'])) ?>
            </span>

            <?php if ($resolvedUrgencyLevel !== null): ?>
                <span class="crf-badge <?= levelBadgeClass($resolvedUrgencyLevel) ?>">
                    Level Urgensi: <?= h($resolvedUrgencyLevel) ?>
                </span>
            <?php endif; ?>

            <?php if (!empty($crf['sla_value']) && !empty($crf['sla_unit'])): ?>

                <span class="crf-badge badge-stage-pir">

                    SLA:
                    <?= h(
                        rtrim(
                            rtrim(
                                number_format(
                                    (float) $crf['sla_value'],
                                    2,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        )
                    ) ?>

                    <?= h($crf['sla_unit']) ?>

                </span>

            <?php endif; ?>

        </div>

        <?php require __DIR__ . '/../includes/partials/handler_assignment.php'; ?>

        <div class="crf-detail-layout">
          <main class="crf-detail-main">

        <!-- INFORMASI PENGAJUAN -->
        <?php
        $showStatusInGrid = true;
        require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
        ?>

        <!-- =====================================================
             2. DETAIL PENGAJUAN
             ===================================================== -->

        <?php
        require __DIR__ . '/../includes/partials/detail_permintaan.php';
        ?>


        <!-- =====================================================
             FORM OTOMASI
             ===================================================== -->

        <?php if ($isAssignedToOther): ?>
        <div class="alert alert-warning crf-alert">
            <i class="bi bi-person-lock"></i>
            CRF ini sedang ditangani oleh <strong><?= h($crf['assigned_handler_name'] ?? '-') ?></strong>.
            Hanya PIC CRF tersebut yang dapat memprosesnya.
        </div>
        <?php else: ?>
        <form
            action="../actions/automation_action.php"
            method="POST"
            <?php if ($isQueued): ?>
                data-confirm="Mulai kerjakan CRF <?= h($crf['request_number']) ?>? SLA akan mulai dihitung sejak sekarang."
            <?php else: ?>
                data-loading-form
            <?php endif; ?>
        >

            <?= csrfField() ?>

            <input
                type="hidden"
                name="id"
                value="<?= (int) $crf['id'] ?>"
            >


            <?php if ($isQueued): ?>

                <!-- =================================================
                     2. ANTREAN · MULAI KERJAKAN
                     ================================================= -->

                <?php
                $queueWaitSeconds = slaWorkingSecondsBetween(
                    new DateTimeImmutable($crf['kadep_operasional_approved_at']),
                    new DateTimeImmutable()
                );
                $hasValidSla = slaDueAt(date('Y-m-d H:i:s'), $crf['sla_value'], $crf['sla_unit']) !== null;
                ?>

                <div class="crf-section mb-4">

                    <div class="crf-section-header">

                        <span class="crf-section-number">
                            <i class="bi bi-inboxes"></i>
                        </span>

                        <h2>
                            Antrean · Mulai Kerjakan
                        </h2>

                    </div>


                    <div class="crf-section-body">

                        <div class="alert alert-info">
                            CRF sudah disetujui Kepala Departemen Operasional pada
                            <strong><?= h(date('d-m-Y H:i', strtotime($crf['kadep_operasional_approved_at']))) ?></strong>
                            dan berada di antrean
                            selama <?= h(formatSlaDuration($queueWaitSeconds)) ?> (dihitung pada hari kerja).
                            SLA <strong>belum berjalan</strong> dan baru dihitung saat Anda menekan
                            <strong>Mulai Kerjakan</strong>.
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="crf-detail-label">LEVEL URGENSI</div>
                                <div class="crf-detail-value">
                                    <?php if ($resolvedUrgencyLevel !== null): ?>
                                        <span class="crf-badge <?= h(levelBadgeClass($resolvedUrgencyLevel)) ?>"><?= h($resolvedUrgencyLevel) ?></span>
                                    <?php else: ?>
                                        <span class="crf-sla-empty">Belum ditentukan</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="crf-detail-label">SLA</div>
                                <div class="crf-detail-value">
                                    <?= h(slaLabel($crf['sla_value'], $crf['sla_unit'])) ?>
                                    <small class="text-muted">(hari kerja)</small>
                                </div>
                            </div>
                        </div>

                        <div class="crf-readonly-note mt-3">
                            <i class="bi bi-info-circle"></i>
                            Level Urgensi dan SLA ditetapkan sistem dan hanya dapat diubah Admin melalui Forum.
                            Jika ada kendala, sampaikan di <a href="../forum/index.php?crf_id=<?= (int) $crf['id'] ?>">Forum CRF</a>.
                        </div>

                        <?php if (!$hasValidSla): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                SLA CRF ini belum valid sehingga belum dapat dikerjakan. Hubungi Admin untuk menetapkan SLA di Forum.
                            </div>
                        <?php endif; ?>

                    </div>

                </div>


                <input type="hidden" name="action" value="start">

                <div class="d-flex justify-content-end">

                    <button
                        type="submit"
                        class="btn btn-crf-primary"
                        <?= $hasValidSla ? '' : 'disabled' ?>
                    >
                        <i class="bi bi-play-circle"></i>
                        Mulai Kerjakan
                    </button>

                </div>


            <?php else: ?>


                <!-- =================================================
                     2. EKSEKUSI PERUBAHAN
                     ================================================= -->

                <div class="crf-section mb-4">

                    <div class="crf-section-header">

                        <span class="crf-section-number">
                            <i class="bi bi-play-circle"></i>
                        </span>

                        <h2>
                            Eksekusi / Tangani Permintaan
                        </h2>

                    </div>


                    <div class="crf-section-body">

                        <div class="alert alert-success">

                            CRF sudah disetujui Kepala Departemen Operasional.
                            Otomasi dapat menjalankan eksekusi perubahan.

                        </div>


                        <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>


                        <div class="crf-readonly-note mb-4">

                            <i class="bi bi-info-circle"></i>

                            Sebelum menyelesaikan eksekusi, Otomasi wajib mengisi
                            <strong>Tanggal Implementasi</strong> dan
                            <strong>Implementasi / Hasil Perubahan</strong>.
                            Setelah itu Pemohon mengisi Post Implementation Review,
                            lalu CRF diteruskan ke CMO untuk penutupan.

                        </div>

                        <div class="mb-4">

                            <label
                                for="implementation_date"
                                class="form-label fw-semibold"
                            >
                                Tanggal Implementasi
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="date"
                                id="implementation_date"
                                name="implementation_date"
                                class="form-control"
                                value="<?= h($crf['implementation_date'] ?? '') ?>"
                                required
                            >

                        </div>

                        <div class="mb-4">

                            <label
                                for="implementation"
                                class="form-label fw-semibold"
                            >
                                Implementasi / Hasil Perubahan
                                <span class="text-danger">*</span>
                            </label>

                            <textarea
                                id="implementation"
                                name="implementation"
                                class="form-control"
                                rows="6"
                                required
                                placeholder="Tuliskan hasil atau perubahan yang sudah diterapkan..."
                            ><?= h($crf['implementation'] ?? '') ?></textarea>

                        </div>

                    </div>

                </div>


                <div class="d-flex justify-content-end">

                    <button
                        type="submit"
                        name="action"
                        value="complete"
                        class="btn btn-crf-primary"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Selesaikan
                    </button>

                </div>

            <?php endif; ?>

        </form>
        <?php endif; ?>

          </main>
          <aside class="crf-detail-sidebar">
            <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>
          </aside>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
