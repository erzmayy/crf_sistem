<?php
/**
 * user/detail.php
 * ---------------------------------------------------------------
 * Menampilkan detail CRF milik user yang sedang login.
 *
 * Implementasi diisi oleh Otomasi (read-only bagi user). Setelah itu
 * CRF masuk tahap PEMOHON_PIR dan pemohon mengisi Post Implementation
 * Review dari halaman ini.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$pdo = getConnection();

$currentUser = getCurrentUser();

$id = (int) ($_GET['id'] ?? 0);

/*
 * Ambil CRF berdasarkan:
 * 1. ID CRF
 * 2. pengguna_id user yang sedang login
 *
 * Jadi user tidak bisa membuka detail CRF milik user lain hanya
 * dengan mengganti ?id= di URL.
 */
$stmt = $pdo->prepare(
    'SELECT cr.*
     FROM crf_pengajuan cr
     WHERE cr.id = :id
       AND cr.pengguna_id = :pengguna_id
     LIMIT 1'
);

$stmt->execute([
    'id' => $id,
    'pengguna_id' => $currentUser['id']
]);

$crf = $stmt->fetch();

if (!$crf) {
    http_response_code(404);
    $pageTitle = 'CRF Tidak Ditemukan';

    require_once __DIR__ . '/../includes/header.php';

    echo '<div class="crf-page"><div class="container">';
    echo '<div class="alert alert-danger">';
    echo 'Pengajuan CRF tidak ditemukan atau bukan milik Anda.';
    echo '</div>';

    echo '<a href="pengajuan_saya.php" class="btn btn-crf-outline">';
    echo '<i class="bi bi-arrow-left"></i> Kembali ke Pengajuan Saya';
    echo '</a>';

    echo '</div></div>';

    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/*
 * Ambil lampiran CRF
 */
$attStmt = $pdo->prepare(
    'SELECT *
     FROM crf_lampiran
     WHERE pengajuan_id = :id
     ORDER BY tgl_unggah ASC'
);

$attStmt->execute([
    'id' => $id
]);

$attachments = $attStmt->fetchAll();

/*
 * Timeline proses pengajuan
 */
$timelineStmt = $pdo->prepare("
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

$timelineStmt->execute([
    'id' => $id
]);

$timeline = $timelineStmt->fetchAll();

/*
 * Flash message
 */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$requestNumberDisplay = !empty($crf['nomor_register'])
    ? $crf['nomor_register']
    : 'Belum ada (draft)';

$pageTitle = 'Detail CRF - ' . $requestNumberDisplay;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-detail-page">
    <div class="container">

        <!-- HEADER -->
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 crf-page-header">

            <div>
                <h1>Detail CRF</h1>

                <p>
                    Nomor Register:
                    <strong><?= h($requestNumberDisplay) ?></strong>
                </p>
            </div>

            <div class="d-flex gap-2">

                <a
                    href="../actions/export_crf.php?id=<?= (int) $crf['id'] ?>"
                    class="btn btn-crf-primary"
                >
                    <i class="bi bi-file-earmark-pdf"></i>
                    Export PDF
                </a>

                <a
                    href="pengajuan_saya.php"
                    class="btn btn-crf-outline"
                >
                    <i class="bi bi-arrow-left"></i>
                    Pengajuan Saya
                </a>

                <?php if (($crf['status'] ?? '') === 'Perlu Revisi'): ?>
                    <a
                        href="form_crf.php?id=<?= (int) $crf['id'] ?>"
                        class="btn btn-warning"
                    >
                        <i class="bi bi-pencil-square"></i>
                        Perbaiki Pengajuan
                    </a>
                <?php endif; ?>

            </div>

        </div>

        <!-- FLASH MESSAGE -->
        <?php if ($flash): ?>

            <div
                class="alert alert-<?= h($flash['type']) ?> crf-alert"
                role="alert"
            >
                <?= h($flash['message']) ?>
            </div>

        <?php endif; ?>


        <!-- STATUS -->
        <div class="crf-status-strip mb-4">

            <span class="crf-badge <?= levelBadgeClass($crf['level']) ?>"
                  title="Berdasarkan dampak yang Anda pilih; dapat disesuaikan oleh CMO">
                Level Urgensi:
                <?= h($crf['level'] ?? 'Belum ditentukan') ?>
            </span>

            <span class="crf-badge <?= statusBadgeClass($crf['status']) ?>">
                Status: <?= h(statusLabel($crf['status'])) ?>
            </span>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['tahap_alur'] ?? 'PEMOHON') ?>">
                Tahap: <?= h(workflowStageLabel($crf['tahap_alur'] ?? 'PEMOHON')) ?>
            </span>

        </div>

        <!-- INFO SOLVE -->
        <?php if ($crf['status'] === 'Solve' && $crf['tgl_selesai']): ?>

            <div class="crf-readonly-note mb-3">

                <i class="bi bi-check-circle"></i>

                Diselesaikan pada:
                <?= h(date('d-m-Y H:i', strtotime($crf['tgl_selesai']))) ?>

            </div>

        <?php endif; ?>


        <!-- INFO CANCEL -->
        <?php if ($crf['status'] === 'Cancel' && $crf['tgl_dibatalkan']): ?>

            <div class="crf-readonly-note mb-3">

                <i class="bi bi-x-circle"></i>

                Dibatalkan pada:
                <?= h(date('d-m-Y H:i', strtotime($crf['tgl_dibatalkan']))) ?>

            </div>

        <?php endif; ?>

        <div class="crf-detail-layout">
          <div class="crf-detail-main">

        <?php
        // Tanggapan tampil paling atas hanya bila pemohon perlu menindaklanjuti
        // (Perlu Revisi / Dibatalkan); selain itu ditaruh setelah data pengajuan.
        $tanggapanDulu = in_array((string) ($crf['status'] ?? ''), ['Perlu Revisi', 'Cancel'], true);
        ob_start();
        ?>
        <!-- TANGGAPAN / TINDAK LANJUT -->
        <div class="crf-section crf-response-card mb-4">

            <div class="crf-section-header">

                <span class="crf-section-number">
                    <i class="bi bi-chat-left-text"></i>
                </span>

                <h2>Tanggapan / Tindak Lanjut</h2>

            </div>

            <div class="crf-section-body">
                <div class="crf-detail-value mb-0"><?= !empty($crf['tanggapan_tindak_lanjut'])
                    ? nl2br(h($crf['tanggapan_tindak_lanjut']))
                    : '<span class="text-muted">Belum ada tanggapan atau tindak lanjut.</span>' ?></div>
            </div>

        </div>
        <?php $tanggapanCard = ob_get_clean(); ?>
        <?= $tanggapanDulu ? $tanggapanCard : '' ?>

        <!-- INFORMASI PENGAJUAN -->
        <?php
        require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
        ?>


        <!-- DETAIL PENGAJUAN -->
        <?php
        require __DIR__ . '/../includes/partials/detail_permintaan.php';
        ?>

        <?= $tanggapanDulu ? '' : $tanggapanCard ?>

        <?php /* Pemohon hanya melihat Level Urgensi; panel Informasi SLA khusus peran internal. */ ?>


        <!-- IMPLEMENTASI & POST IMPLEMENTATION REVIEW -->
        <div id="implementation-review" class="crf-section crf-detail-card mb-4">

            <div class="crf-section-header">
                <span class="crf-section-number">
                    <i class="bi bi-clipboard-check"></i>
                </span>

                <h2>Implementasi & Post Implementation Review</h2>
            </div>

            <div class="crf-section-body">

                <?php $isPirStage = ($crf['tahap_alur'] ?? '') === 'PEMOHON_PIR'; ?>
                <?php if ($isPirStage): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-pencil-square"></i>
                        Otomasi sudah mencatat hasil implementasi. Silakan periksa hasilnya lalu isi
                        <strong>Tanggal PIR</strong> dan <strong>Post Implementation Review</strong> di bawah.
                    </div>
                <?php elseif (empty($crf['implementasi'])): ?>
                    <div class="alert alert-info">
                        Implementasi akan dicatat oleh Otomasi. Setelah itu Anda diminta mengisi Post Implementation Review.
                    </div>
                <?php endif; ?>

                <div class="crf-info-rows">
                    <div class="crf-info-row">
                        <span class="crf-info-label">Tanggal Implementasi</span>
                        <div class="crf-info-value">
                            <?= !empty($crf['tgl_implementasi'])
                                ? h(date('d-m-Y', strtotime($crf['tgl_implementasi'])))
                                : '<span class="text-muted">Belum diisi.</span>' ?>
                        </div>
                    </div>
                    <div class="crf-info-row crf-request-row-long">
                        <span class="crf-info-label">Implementasi / Hasil Perubahan</span>
                        <div class="crf-info-value">
                            <?php if (!empty($crf['implementasi'])): ?>
                                <?= nl2br(h($crf['implementasi'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Belum diisi.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$isPirStage): ?>
                    <div class="crf-info-row">
                        <span class="crf-info-label">Tanggal PIR</span>
                        <div class="crf-info-value">
                            <?= !empty($crf['tgl_pir'])
                                ? h(date('d-m-Y', strtotime($crf['tgl_pir'])))
                                : '<span class="text-muted">Belum diisi.</span>' ?>
                        </div>
                    </div>
                    <div class="crf-info-row crf-request-row-long">
                        <span class="crf-info-label">Post Implementation Review</span>
                        <div class="crf-info-value">
                            <?php if (!empty($crf['tinjauan_pasca_implementasi'])): ?>
                                <?= nl2br(h($crf['tinjauan_pasca_implementasi'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Belum diisi.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($isPirStage): ?>
                    <form action="../actions/submit_pir.php" method="POST" class="mt-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">

                        <div class="mb-4">
                            <label for="tgl_pir" class="form-label fw-semibold">
                                Tanggal PIR <span class="text-danger">*</span>
                            </label>
                            <input
                                type="date"
                                id="tgl_pir"
                                name="tgl_pir"
                                class="form-control"
                                value="<?= h($crf['tgl_pir'] ?? date('Y-m-d')) ?>"
                                <?= !empty($crf['tgl_implementasi']) ? 'min="' . h($crf['tgl_implementasi']) . '"' : '' ?>
                                required
                            >
                        </div>

                        <div class="mb-4">
                            <label for="tinjauan_pasca_implementasi" class="form-label fw-semibold">
                                Post Implementation Review <span class="text-danger">*</span>
                            </label>
                            <textarea
                                id="tinjauan_pasca_implementasi"
                                name="tinjauan_pasca_implementasi"
                                class="form-control"
                                rows="6"
                                required
                                placeholder="Apakah perubahan sudah sesuai kebutuhan? Tuliskan hasil evaluasi Anda setelah perubahan diterapkan..."
                            ><?= h($crf['tinjauan_pasca_implementasi'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-crf-primary">
                                <i class="bi bi-send-check"></i> Kirim PIR
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

            </div>

        </div>

        <!-- TIMELINE PROSES PENGAJUAN -->
          </div><!-- /.crf-detail-main -->
          <aside class="crf-detail-sidebar">
        <?php $timelineHideSla = true; require __DIR__ . '/../includes/partials/timeline.php'; ?>

          </aside>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>