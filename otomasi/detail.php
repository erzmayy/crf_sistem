<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['otomasi']);

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT cr.*
    FROM crf_pengajuan cr
    WHERE cr.id = :id
      AND cr.tahap_alur = 'OTOMASI'
      AND cr.tgl_disetujui_kadep IS NOT NULL
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

                <a href="index.php" class="crf-back-btn">
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
     FROM crf_lampiran
     WHERE pengajuan_id = :id
     ORDER BY tgl_unggah ASC'
);
$attStmt->execute(['id' => $id]);
$attachments = $attStmt->fetchAll();

$timelineStmt = $pdo->prepare("
    SELECT aktivitas, deskripsi, pelaksana, status_lama, status_baru, tgl_dibuat
    FROM crf_log_aktivitas
    WHERE pengajuan_id = :id
    ORDER BY tgl_dibuat ASC, id ASC
");
$timelineStmt->execute(['id' => $id]);
$timeline = $timelineStmt->fetchAll();

$isAssignedToOther = !empty($crf['pic_id']) && !isAssignedCrfHandler($crf);

// Tahap OTOMASI punya tiga fase (eksekusi, perbaikan hasil UAT, isi implementasi).
$otomasiPhase = crfOtomasiPhase($crf);
$otomasiPhaseMeta = [
    'eksekusi' => ['title' => 'Eksekusi / Tangani Permintaan', 'action' => 'execute', 'button' => 'Selesai Eksekusi · Kirim ke UAT', 'icon' => 'bi-play-circle'],
    'perbaikan' => ['title' => 'Perbaikan Hasil UAT', 'action' => 'resubmit_uat', 'button' => 'Kirim Ulang ke UAT', 'icon' => 'bi-arrow-repeat'],
    'implementasi' => ['title' => 'Implementasi', 'action' => 'complete', 'button' => 'Selesaikan', 'icon' => 'bi-journal-check'],
][$otomasiPhase] ?? ['title' => 'Eksekusi / Tangani Permintaan', 'action' => 'execute', 'button' => 'Selesai Eksekusi · Kirim ke UAT', 'icon' => 'bi-play-circle'];

// Catatan CMO pada UAT terakhir yang meminta perbaikan.
$uatFailNote = null;
if ($otomasiPhase === 'perbaikan') {
    $uatFailStmt = $pdo->prepare("
        SELECT deskripsi, pelaksana, tgl_dibuat
        FROM crf_log_aktivitas
        WHERE pengajuan_id = :id AND aktivitas = 'UAT Perlu Perbaikan'
        ORDER BY id DESC
        LIMIT 1
    ");
    $uatFailStmt->execute(['id' => $id]);
    $uatFailNote = $uatFailStmt->fetch() ?: null;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Otomasi - Eksekusi CRF';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-detail-page">

    <div class="container">

        <?php
        $dhEyebrow = 'Eksekusi PIC CRF';
        $dhShowExport = false;
        require __DIR__ . '/../includes/partials/detail_header.php';
        ?>

        <?php require __DIR__ . '/../includes/partials/handler_assignment.php'; ?>

        <div class="crf-detail-layout">
          <div class="crf-detail-main">

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
            CRF ini sedang ditangani oleh <strong><?= h($crf['pic_nama'] ?? '-') ?></strong>.
            Hanya PIC CRF tersebut yang dapat memprosesnya.
        </div>
        <?php else: ?>
        <form
            action="../actions/automation_action.php"
            method="POST"
            data-loading-form
        >

            <?= csrfField() ?>

            <input
                type="hidden"
                name="id"
                value="<?= (int) $crf['id'] ?>"
            >




                <!-- =================================================
                     2. EKSEKUSI PERUBAHAN
                     ================================================= -->

                <div class="crf-section crf-detail-card crf-action-card mb-4">

                    <div class="crf-section-header">

                        <span class="crf-section-number">
                            <i class="bi <?= h($otomasiPhaseMeta['icon']) ?>"></i>
                        </span>

                        <h2>
                            Tindakan Anda: <?= h($otomasiPhaseMeta['title']) ?>
                        </h2>

                    </div>


                    <div class="crf-section-body">

                        <?php if ($otomasiPhase === 'eksekusi'): ?>

                            <div class="alert alert-success">
                                CRF disetujui Kepala Departemen. Silakan jalankan eksekusi perubahan.
                            </div>

                            <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>

                            <div class="crf-readonly-note mb-4">
                                <i class="bi bi-info-circle"></i>
                                Setelah eksekusi selesai, tekan <strong>Selesai Eksekusi</strong>. SLA berhenti dan
                                CRF dikirim ke CMO untuk <strong>UAT</strong> (tidak dihitung dalam SLA).
                            </div>

                            <div class="mb-4">
                                <label for="note" class="form-label fw-semibold">
                                    Catatan untuk UAT <span class="text-muted fw-normal">(opsional)</span>
                                </label>
                                <textarea
                                    id="note"
                                    name="note"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Ringkasan perubahan yang perlu diuji, lingkungan, atau langkah pengujian..."
                                ></textarea>
                            </div>

                        <?php elseif ($otomasiPhase === 'perbaikan'): ?>

                            <div class="alert alert-warning">
                                <strong>Hasil UAT: perlu perbaikan.</strong>
                                <?php if ($uatFailNote): ?>
                                    <div class="mt-1">
                                        <?= nl2br(h($uatFailNote['deskripsi'])) ?>
                                        <small class="d-block text-muted">
                                            <?= h($uatFailNote['pelaksana']) ?> · <?= h(date('d-m-Y H:i', strtotime($uatFailNote['tgl_dibuat']))) ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>

                            <div class="crf-readonly-note mb-4">
                                <i class="bi bi-info-circle"></i>
                                Perbaiki sesuai catatan CMO (koordinasi lewat <strong>Diskusi Forum</strong>), lalu
                                kirim ulang untuk UAT. Waktu perbaikan tidak dihitung dalam SLA.
                            </div>

                            <div class="mb-4">
                                <label for="note" class="form-label fw-semibold">
                                    Catatan perbaikan <span class="text-muted fw-normal">(opsional)</span>
                                </label>
                                <textarea
                                    id="note"
                                    name="note"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Apa yang diperbaiki dan apa yang perlu diuji ulang..."
                                ></textarea>
                            </div>

                        <?php else: ?>

                            <div class="alert alert-success">
                                <strong>UAT lulus</strong><?= !empty($crf['tgl_uat_lulus']) ? ' pada ' . h(date('d-m-Y H:i', strtotime($crf['tgl_uat_lulus']))) : '' ?>.
                            </div>

                            <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>

                            <div class="mb-4">
                                <label for="tgl_implementasi" class="form-label fw-semibold">
                                    Tanggal Implementasi
                                    <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="date"
                                    id="tgl_implementasi"
                                    name="tgl_implementasi"
                                    class="form-control"
                                    value="<?= h($crf['tgl_implementasi'] ?? '') ?>"
                                    required
                                >
                            </div>

                            <div class="mb-4">
                                <label for="implementasi" class="form-label fw-semibold">
                                    Implementasi / Hasil Perubahan
                                    <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    id="implementasi"
                                    name="implementasi"
                                    class="form-control"
                                    rows="6"
                                    required
                                    placeholder="Tuliskan hasil atau perubahan yang sudah diterapkan..."
                                ><?= h($crf['implementasi'] ?? '') ?></textarea>
                            </div>

                        <?php endif; ?>

                    </div>

                </div>


                <div class="d-flex justify-content-end">

                    <button
                        type="submit"
                        name="action"
                        value="<?= h($otomasiPhaseMeta['action']) ?>"
                        class="btn btn-crf-primary"
                    >
                        <i class="bi bi-check2-circle"></i>
                        <?= h($otomasiPhaseMeta['button']) ?>
                    </button>

                </div>

        </form>
        <?php endif; ?>

          </div><!-- /.crf-detail-main -->
          <aside class="crf-detail-sidebar">
            <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>
          </aside>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
