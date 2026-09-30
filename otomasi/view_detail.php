<?php
/**
 * otomasi/view_detail.php
 * ---------------------------------------------------------------
 * Tampilan read-only detail CRF untuk Otomasi.
 * Tombol "Proses CRF" mengarah ke otomasi/detail.php (form SLA /
 * eksekusi).
 *
 * Bagian yang sama dengan halaman role lain diambil dari
 * includes/partials/*.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['otomasi']);

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);


/* =========================================================
 * AMBIL CRF
 * ========================================================= */
$stmt = $pdo->prepare("
    SELECT cr.*
    FROM change_requests cr
    WHERE cr.id = :id
      AND (
          cr.workflow_stage = 'OTOMASI'
          OR EXISTS (
              SELECT 1
              FROM crf_activity_logs activity_log
              WHERE activity_log.change_request_id = cr.id
                AND activity_log.activity IN (
                    'Proses Otomasi Diperbarui',
                    'Otomasi - SLA Ditentukan',
                    'Otomasi Selesai'
                )
          )
      )
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

if (!$crf) {
    http_response_code(404);

    $pageTitle = 'CRF Tidak Ditemukan';

    require_once __DIR__ . '/../includes/header.php';
    ?>

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

    <?php
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


$pageTitle = 'Otomasi - Detail CRF';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page">

    <div class="container">


        <!-- =====================================================
             HEADER
             ===================================================== -->
        <div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">

            <div>
                <h1>Detail CRF</h1>

                <p class="mb-0">
                    Nomor Register:
                    <strong><?= h($crf['request_number'] ?? '-') ?></strong>
                    &middot;
                    Pengaju:
                    <?= h($crf['full_name'] ?? '-') ?>
                </p>
            </div>

            <div class="d-flex gap-2">

                <a href="index.php" class="btn btn-crf-outline">
                    <i class="bi bi-arrow-left"></i>
                    Kembali
                </a>

                <a
                    href="detail.php?id=<?= (int) $crf['id'] ?>"
                    class="btn btn-crf-primary"
                >
                    <i class="bi bi-gear"></i>
                    Proses CRF
                </a>

            </div>

        </div>


        <!-- =====================================================
             STATUS
             ===================================================== -->
        <div class="d-flex gap-2 mb-4 flex-wrap">

            <span class="crf-badge <?= statusBadgeClass($crf['status']) ?>">
                Status: <?= h(statusLabel($crf['status'] ?? '')) ?>
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

        <!-- 3. INFORMASI SLA -->
        <?php
        $sectionNumber = 3;
        require __DIR__ . '/../includes/partials/informasi_sla.php';
        ?>

        <!-- 4. TIMELINE -->
        <?php
        $sectionNumber = 4;
        $sectionTitle  = 'Timeline Proses';
        require __DIR__ . '/../includes/partials/timeline.php';
        ?>


    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>