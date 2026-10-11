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
    FROM crf_pengajuan cr
    WHERE cr.id = :id
      AND (
          cr.tahap_alur = 'OTOMASI'
          OR EXISTS (
              SELECT 1
              FROM crf_log_aktivitas activity_log
              WHERE activity_log.pengajuan_id = cr.id
                AND activity_log.aktivitas IN (
                    'Proses Otomasi Diperbarui',
                    'Otomasi - SLA Ditentukan',
                    'Eksekusi Selesai',
                    'Otomasi Selesai'
                )
          )
      )
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

// Handler hanya boleh melihat CRF pada kategori yang ditanganinya.
if ($crf && !canHandleCrf($pdo, $crf)) {
    $crf = false;
}

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
    FROM crf_lampiran
    WHERE pengajuan_id = :id
    ORDER BY tgl_unggah ASC
");

$attStmt->execute(['id' => $id]);

$attachments = $attStmt->fetchAll();


/* =========================================================
 * TIMELINE
 * ========================================================= */
$logStmt = $pdo->prepare("
    SELECT
        aktivitas,
        deskripsi,
        pelaksana,
        status_lama,
        status_baru,
        tgl_dibuat
    FROM crf_log_aktivitas
    WHERE pengajuan_id = :id
    ORDER BY tgl_dibuat ASC, id ASC
");

$logStmt->execute(['id' => $id]);

$timeline = $logStmt->fetchAll();


$pageTitle = 'Otomasi - Detail CRF';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-detail-page">

    <div class="container">


        <!-- =====================================================
             HEADER
             ===================================================== -->
        <div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">

            <div>
                <h1>Detail CRF</h1>

                <p class="mb-0">
                    Nomor Register:
                    <strong><?= h($crf['nomor_register'] ?? '-') ?></strong>
                    &middot;
                    Pengaju:
                    <?= h($crf['nama_pemohon'] ?? '-') ?>
                </p>
            </div>

            <div class="d-flex gap-2">
                <?php require __DIR__ . '/../includes/partials/forum_button.php'; ?>

                <a href="index.php" class="btn btn-crf-outline">
                    <i class="bi bi-arrow-left"></i>
                    Kembali
                </a>

                <?php if ($crf['tahap_alur'] === 'OTOMASI' && !empty($crf['tgl_disetujui_kadep'])): ?>
                    <a
                        href="detail.php?id=<?= (int) $crf['id'] ?>"
                        class="btn btn-crf-primary"
                    >
                        <i class="bi bi-gear"></i>
                        Proses CRF
                    </a>
                <?php endif; ?>

            </div>

        </div>


        <!-- =====================================================
             STATUS
             ===================================================== -->
        <div class="crf-status-strip mb-4">

            <span class="crf-badge <?= statusBadgeClass($crf['status']) ?>">
                Status: <?= h(statusLabel($crf['status'] ?? '')) ?>
            </span>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['tahap_alur']) ?>">
                Tahap: <?= h(workflowStageLabel($crf['tahap_alur'])) ?>
            </span>

            <span class="crf-badge <?= levelBadgeClass($crf['level']) ?>">
                Level: <?= h($crf['level'] ?? 'Belum ditentukan') ?>
            </span>

            <?php if (!empty($crf['sla_nilai']) && !empty($crf['sla_satuan'])): ?>
                <span class="crf-badge badge-stage-pir">
                    SLA:
                    <?= h(rtrim(rtrim(number_format((float) $crf['sla_nilai'], 2, '.', ''), '0'), '.')) ?>
                    <?= h($crf['sla_satuan']) ?>
                </span>
            <?php endif; ?>

        </div>

        <div class="crf-detail-layout">
          <div class="crf-detail-main">

        <!-- 1. INFORMASI PENGAJUAN -->
        <?php
        $showStatusInGrid = true;
        require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
        ?>

        <!-- 2. DETAIL PERMINTAAN -->
        <?php
        require __DIR__ . '/../includes/partials/detail_permintaan.php';
        ?>

        <!-- 3. INFORMASI SLA -->
        <?php
        require __DIR__ . '/../includes/partials/informasi_sla.php';
        ?>

          </div><!-- /.crf-detail-main -->
          <aside class="crf-detail-sidebar">
        <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>

          </aside>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>