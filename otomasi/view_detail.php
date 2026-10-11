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

            <a href="index.php" class="crf-back-btn">
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


        <?php
        $dhEyebrow = 'Detail CRF';
        $dhShowExport = false;
        $dhExtraHtml = ($crf['tahap_alur'] === 'OTOMASI' && !empty($crf['tgl_disetujui_kadep']))
            ? '<a href="detail.php?id=' . (int) $crf['id'] . '" class="btn btn-crf-primary"><i class="bi bi-gear" aria-hidden="true"></i> Proses CRF</a>'
            : '';
        require __DIR__ . '/../includes/partials/detail_header.php';
        ?>

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