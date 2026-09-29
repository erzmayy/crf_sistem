<?php
/**
 * cmo/detail.php
 * ---------------------------------------------------------------
 * Detail CRF untuk CMO (tahap filter dan finalisasi).
 *
 * Bagian yang sama dengan halaman role lain (informasi pengajuan,
 * detail permintaan, lampiran, biaya & kategori, saran alternatif,
 * SLA, timeline) diambil dari includes/partials/*.
 * Bagian yang khusus CMO (Implementasi & PIR read-only, form
 * review/finalisasi) tetap ditulis di file ini.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['cmo']);

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);


/* =========================================================
 * AMBIL DATA CRF
 * ========================================================= */
$stmt = $pdo->prepare("
    SELECT cr.*
    FROM change_requests cr
    WHERE cr.id = :id
      AND cr.workflow_stage IN ('CMO_FILTER', 'CMO_FINAL')
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

if (!$crf) {

    http_response_code(404);

    $pageTitle = 'CRF Tidak Ditemukan';

    require_once __DIR__ . '/../includes/header.php';

    echo '
        <div class="crf-page">
            <div class="container">
                <div class="alert alert-danger">
                    CRF tidak ditemukan pada antrean CMO.
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


/* =========================================================
 * ATTACHMENTS
 * ========================================================= */
$attStmt = $pdo->prepare("
    SELECT *
    FROM attachments
    WHERE change_request_id = :id
    ORDER BY uploaded_at ASC
");

$attStmt->execute(['id' => $id]);

$attachments = $attStmt->fetchAll();


/* =========================================================
 * TIMELINE
 * ========================================================= */
$logStmt = $pdo->prepare("
    SELECT
        activity,
        description,
        actor,
        created_at
    FROM crf_activity_logs
    WHERE change_request_id = :id
    ORDER BY created_at ASC, id ASC
");

$logStmt->execute(['id' => $id]);

$timeline = $logStmt->fetchAll();


/* =========================================================
 * FLASH MESSAGE
 * ========================================================= */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


$pageTitle = 'CMO - Detail CRF';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page" id="cmo-detail-page">

    <div class="container">


        <!-- =====================================================
             HEADER
             ===================================================== -->
        <div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">

            <div>
                <h1>Detail CRF - CMO</h1>

                <p class="mb-0">
                    Nomor Register:
                    <strong><?= h($crf['request_number'] ?? '-') ?></strong>
                </p>
            </div>

            <a href="index.php" class="btn btn-crf-outline">
                <i class="bi bi-arrow-left"></i>
                Kembali
            </a>

        </div>


        <!-- =====================================================
             FLASH
             ===================================================== -->
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert" role="alert">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>


        <!-- =====================================================
             STATUS
             ===================================================== -->
        <div class="d-flex gap-2 mb-4 flex-wrap">

            <span class="crf-badge <?= statusBadgeClass($crf['status']) ?>">
                Status: <?= h(statusLabel($crf['status'])) ?>
            </span>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['workflow_stage']) ?>">
                Tahap: <?= h(workflowStageLabel($crf['workflow_stage'])) ?>
            </span>

            <span class="crf-badge <?= levelBadgeClass($crf['level']) ?>">
                Level: <?= h($crf['level'] ?? 'Belum ditentukan') ?>
            </span>

            <?php if (!empty($crf['sla_value']) && !empty($crf['sla_unit'])): ?>
                <span class="crf-badge badge-stage-pir">
                    SLA:
                    <?= h(rtrim(rtrim(number_format((float) $crf['sla_value'], 2, '.', ''), '0'), '.')) ?>
                    <?= h($crf['sla_unit']) ?>
                </span>
            <?php endif; ?>

        </div>


        <!-- 1. INFORMASI PENGAJUAN -->
        <?php
        $sectionNumber    = 1;
        $showStatusInGrid = true;
        require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
        ?>

        <!-- 2. DETAIL PERMINTAAN -->
        <?php
        $sectionNumber = 2;
        require __DIR__ . '/../includes/partials/detail_permintaan.php';
        ?>

        <!-- =====================================================
             3. IMPLEMENTASI & PIR (khusus tampilan CMO, read-only)
             ===================================================== -->
        <div class="crf-section mb-4">

            <div class="crf-section-header">
                <span class="crf-section-number">3</span>
                <h2>Implementasi & Post Implementation Review</h2>
            </div>

            <div class="crf-section-body">

                <div class="crf-detail-label">IMPLEMENTASI / HASIL PERUBAHAN</div>

                <div class="crf-detail-value mb-4">
                    <?php if (!empty($crf['implementation'])): ?>
                        <?= nl2br(h($crf['implementation'])) ?>
                    <?php else: ?>

                        <span class="text-muted">
                            Belum diisi oleh Otomasi.
                        </span>

                    <?php endif; ?>
                </div>

                <div class="crf-detail-label">POST IMPLEMENTATION REVIEW</div>

                <div class="crf-detail-value mb-4">
                    <?php if (!empty($crf['post_implementation_review'])): ?>
                        <?= nl2br(h($crf['post_implementation_review'])) ?>
                    <?php else: ?>

                        <span class="text-muted">
                            Belum diisi oleh Otomasi.
                        </span>

                    <?php endif; ?>
                </div>

                <?php if (!empty($crf['kadep_operasional_approved_at'])): ?>
                    <div class="crf-readonly-note">
                        <i class="bi bi-check2-circle"></i>
                        Disetujui Kepala Departemen Operasional pada
                        <?= h(date('d-m-Y H:i', strtotime($crf['kadep_operasional_approved_at']))) ?>
                    </div>
                <?php endif; ?>

            </div>

        </div>


        <!-- 4. INFORMASI SLA -->
        <?php
        $sectionNumber = 4;
        require __DIR__ . '/../includes/partials/informasi_sla.php';
        ?>


        <!-- =====================================================
             5. REVIEW CMO / FINALISASI (khusus CMO)
             ===================================================== -->
        <?php if ($crf['workflow_stage'] === 'CMO_FILTER'): ?>

            <div class="crf-section mb-4">

                <div class="crf-section-header">
                    <span class="crf-section-number">5</span>
                    <h2>Review CMO</h2>
                </div>

                <div class="crf-section-body">

                    <form action="../actions/cmo_action.php" method="POST">

                        <?= csrfField() ?>

                        <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Tanggapan / Tindak Lanjut
                            </label>

                            <textarea
                                name="tanggapan"
                                class="form-control"
                                rows="4"
                                placeholder="Wajib diisi jika Perlu Revisi atau Dibatalkan."
                            ><?= h($crf['tanggapan_tindak_lanjut'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">

                            <button
                                name="action"
                                value="to_automation"
                                class="btn btn-crf-primary"
                            >
                                <i class="bi bi-arrow-right-circle"></i>
                                Lanjut ke Otomasi
                            </button>

                            <button
                                name="action"
                                value="revision"
                                class="btn btn-warning"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Perlu Revisi
                            </button>

                            <button
                                name="action"
                                value="cancel"
                                class="btn btn-outline-danger"
                            >
                                <i class="bi bi-x-circle"></i>
                                Batalkan
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        <?php elseif ($crf['workflow_stage'] === 'CMO_FINAL'): ?>

            <div class="crf-section mb-4">

                <div class="crf-section-header">
                    <span class="crf-section-number">5</span>
                    <h2>Finalisasi</h2>
                </div>

                <div class="crf-section-body">

                    <p class="text-muted">

                        Otomasi sudah menyelesaikan eksekusi serta mengisi
                        Implementasi / Hasil Perubahan dan Post Implementation Review.
                        CMO dapat menutup CRF setelah memastikan
                        seluruh proses sudah lengkap.

                    </p>

                    <form action="../actions/cmo_action.php" method="POST">

                        <?= csrfField() ?>

                        <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">

                        <div class="d-flex gap-2 flex-wrap">

                            <button
                                name="action"
                                value="complete"
                                class="btn btn-success"
                            >
                                <i class="bi bi-check-circle"></i>
                                Tandai Selesai
                            </button>

                            <button
                                name="action"
                                value="cancel"
                                class="btn btn-outline-danger"
                            >
                                <i class="bi bi-x-circle"></i>
                                Batalkan
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        <?php endif; ?>


        <!-- 6. TIMELINE -->
        <?php
        $sectionNumber = 6;
        $sectionTitle  = 'Timeline';
        require __DIR__ . '/../includes/partials/timeline.php';
        ?>


    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>