<?php
/**
 * user/detail.php
 * ---------------------------------------------------------------
 * Menampilkan detail CRF milik user yang sedang login.
 *
 * Implementasi / Hasil Perubahan dan Post Implementation Review
 * diisi oleh Otomasi sebelum eksekusi diselesaikan. User hanya melihat hasilnya.
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
 * 2. user_id user yang sedang login
 *
 * Jadi user tidak bisa membuka detail CRF milik user lain hanya
 * dengan mengganti ?id= di URL.
 */
$stmt = $pdo->prepare(
    'SELECT cr.*
     FROM change_requests cr
     WHERE cr.id = :id
       AND cr.user_id = :user_id
     LIMIT 1'
);

$stmt->execute([
    'id' => $id,
    'user_id' => $currentUser['id']
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
     FROM attachments
     WHERE change_request_id = :id
     ORDER BY uploaded_at ASC'
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
        activity,
        description,
        actor,
        created_at
    FROM crf_activity_logs
    WHERE change_request_id = :id
    ORDER BY created_at ASC, id ASC
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

$requestNumberDisplay = !empty($crf['request_number'])
    ? $crf['request_number']
    : 'Belum ada (draft)';

$pageTitle = 'Detail CRF - ' . $requestNumberDisplay;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page">
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
        <div class="d-flex gap-2 mb-4">

            <span class="crf-badge <?= levelBadgeClass($crf['level']) ?>">
                Level Urgensi:
                <?= h($crf['level'] ?? 'Belum ditentukan') ?>
            </span>

            <span class="crf-badge <?= statusBadgeClass($crf['status']) ?>">
                Status: <?= h(statusLabel($crf['status'])) ?>
            </span>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['workflow_stage'] ?? 'PEMOHON') ?>">
                Tahap: <?= h(workflowStageLabel($crf['workflow_stage'] ?? 'PEMOHON')) ?>
            </span>

        </div>

        <?php if (!empty($crf['sla_value']) && !empty($crf['sla_unit'])): ?>
            <div class="crf-readonly-note mb-3">
                <i class="bi bi-hourglass-split"></i>
                SLA: <?= h(rtrim(rtrim(number_format((float) $crf['sla_value'], 2, '.', ''), '0'), '.')) ?> <?= h($crf['sla_unit']) ?>
                <?php if (!empty($crf['sla_due_at'])): ?>
                    · Batas waktu: <?= h(date('d-m-Y H:i', strtotime($crf['sla_due_at']))) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <!-- INFO SOLVE -->
        <?php if ($crf['status'] === 'Solve' && $crf['solved_at']): ?>

            <div class="crf-readonly-note mb-3">

                <i class="bi bi-check-circle"></i>

                Diselesaikan pada:
                <?= h(date('d-m-Y H:i', strtotime($crf['solved_at']))) ?>

            </div>

        <?php endif; ?>


        <!-- INFO CANCEL -->
        <?php if ($crf['status'] === 'Cancel' && $crf['cancelled_at']): ?>

            <div class="crf-readonly-note mb-3">

                <i class="bi bi-x-circle"></i>

                Dibatalkan pada:
                <?= h(date('d-m-Y H:i', strtotime($crf['cancelled_at']))) ?>

            </div>

        <?php endif; ?>


        <!-- TANGGAPAN / TINDAK LANJUT -->
        <div class="crf-section mb-4">

            <div class="crf-section-header">

                <span class="crf-section-number">
                    <i class="bi bi-chat-left-text"></i>
                </span>

                <h2>Tanggapan / Tindak Lanjut</h2>

            </div>

            <div class="crf-section-body">

                <div class="crf-detail-value mb-0">

                    <?php if (!empty($crf['tanggapan_tindak_lanjut'])): ?>

                        <?= nl2br(h($crf['tanggapan_tindak_lanjut'])) ?>

                    <?php else: ?>

                        <span class="text-muted">
                            Belum ada tanggapan atau tindak lanjut.
                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- INFORMASI PENGAJUAN -->
        <div class="crf-detail-section">

            <div class="crf-section-header">

                <span class="crf-section-number">
                    1
                </span>

                <h2>Informasi Pengajuan</h2>

            </div>

            <div class="crf-section-body">

                <!-- DATA PENGAJU -->
                <div class="row g-4 mb-4">

                    <div class="col-md-3">

                        <div class="crf-detail-label">
                            PENGAJU
                        </div>

                        <div class="crf-detail-value">
                            <?= h($crf['full_name']) ?>
                        </div>

                    </div>


                    <div class="col-md-3">

                        <div class="crf-detail-label">
                            EMAIL
                        </div>

                        <div class="crf-detail-value">
                            <?= h($crf['email'] ?? '-') ?>
                        </div>

                    </div>


                    <div class="col-md-3">

                        <div class="crf-detail-label">
                            NO. HP/WA
                        </div>

                        <div class="crf-detail-value">
                            <?= h($crf['phone'] ?? '-') ?>
                        </div>

                    </div>


                    <div class="col-md-3">
                        <!-- kosong -->
                    </div>

                </div>


                <hr>


                <!-- INFORMASI CRF -->
                <div class="row g-4 mt-1">

                    <div class="col-md-3">

                        <div class="crf-detail-label">
                            HARI/TANGGAL
                        </div>

                        <div class="crf-detail-value">

                            <?php if (!empty($crf['submission_date'])): ?>

                                <?= h(
                                    formatTanggalIndonesia(
                                        new DateTime($crf['submission_date'])
                                    )
                                ) ?>

                            <?php else: ?>

                                <span class="text-muted">Belum diajukan</span>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="col-md-3">

                                                <div class="crf-detail-label">
                            NOMOR REGISTER
                        </div>

                        <div class="crf-detail-value">
                            <?= h($requestNumberDisplay) ?>
                        </div>

                    </div>


                    <div class="col-md-3">

                        <div class="crf-detail-label">
                            KEPADA
                        </div>

                        <div class="crf-detail-value">

                            <?= h($crf['to_department']) ?>

                            <?php if (!empty($crf['to_division'])): ?>

                                (<?= h($crf['to_division']) ?>)

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="col-md-3">

                        <div class="crf-detail-label">
                            DARI
                        </div>

                        <div class="crf-detail-value">

                            <?= h($crf['from_department']) ?>

                            <?php if (!empty($crf['from_division'])): ?>

                                (<?= h($crf['from_division']) ?>)

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- DETAIL PENGAJUAN -->
        <div class="crf-section">

            <div class="crf-section-header">

                <span class="crf-section-number">
                    <i class="bi bi-card-checklist"></i>
                </span>

                <h2>Detail Pengajuan</h2>

            </div>


            <div class="crf-section-body">

                <!-- RINCIAN PERUBAHAN -->
                <div class="crf-detail-label">
                    Rincian Permohonan Perubahan
                </div>

                <div class="crf-detail-value">
                    <?= nl2br(h($crf['change_description'] ?? '-')) ?>
                </div>


                <!-- BENEFIT -->
                <div class="crf-detail-label">
                    Benefit dari Perubahan yang Diharapkan
                </div>

                <div class="crf-detail-value">
                    <?= nl2br(h($crf['benefit'] ?? '-')) ?>
                </div>


                <!-- IMPACT -->
                <div class="crf-detail-label">
                    Dampak Jika Tidak Dilakukan Perubahan
                </div>

                <div class="crf-detail-value">
                    <?= nl2br(h($crf['impact'] ?? '-')) ?>
                </div>


                <!-- ALASAN -->
                <div class="crf-detail-label">
                    Alasan Permohonan Perubahan
                </div>

                <div class="crf-detail-value">
                    <?= nl2br(h($crf['reason'] ?? '-')) ?>
                </div>


                <!-- LAMPIRAN -->
                <div class="crf-detail-label">
                    Bukti dan Informasi Pendukung
                </div>

                <div class="crf-detail-value">

                    <?php if (!$attachments): ?>

                        <span class="text-muted">
                            Tidak ada file yang diupload.
                        </span>

                    <?php else: ?>

                        <div class="list-group">

                            <?php foreach ($attachments as $file): ?>

                                <div class="list-group-item d-flex justify-content-between align-items-center gap-3 py-2 px-3">

                                    <div class="d-flex align-items-center gap-2 flex-grow-1 min-width-0">

                                        <i class="bi bi-paperclip text-primary"></i>

                                        <div class="text-truncate">

                                            <div class="fw-medium text-truncate">

                                                <?= h($file['original_name']) ?>

                                            </div>

                                            <small class="text-muted">

                                                <?= round($file['file_size'] / 1024) ?>
                                                KB

                                            </small>

                                        </div>

                                    </div>


                                    <div class="d-flex gap-1 flex-shrink-0">

                                        <a
                                            href="<?= h($file['file_path']) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="btn btn-sm btn-crf-outline py-1 px-2"
                                        >
                                            <i class="bi bi-eye"></i>
                                            Lihat
                                        </a>


                                        <a
                                            href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>"
                                            class="btn btn-sm btn-crf-primary py-1 px-2"
                                        >
                                            <i class="bi bi-download"></i>
                                            Download
                                        </a>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>


                <!-- ANGGARAN -->
                <div class="crf-detail-label">
                    Biaya / Anggaran
                </div>

                <div class="crf-detail-value">

                    <?= h(budgetTypeLabel($crf['budget_type'])) ?>

                    <?php if ($crf['budget_amount'] !== null): ?>

                        &mdash;
                        <?= h(formatRupiah($crf['budget_amount'])) ?>

                    <?php endif; ?>

                </div>


                <!-- KATEGORI -->
                <div class="crf-detail-label">
                    Kategori Perubahan
                </div>

                <div class="crf-detail-value">

                    <?= h($crf['change_category'] ?? '-') ?>

                    <?php if (!empty($crf['change_category_detail'])): ?>

                        &mdash;
                        <?= h($crf['change_category_detail']) ?>

                    <?php endif; ?>

                </div>


                <!-- SARAN ALTERNATIF -->
                <div class="crf-detail-label">
                    Saran Alternatif
                </div>

                <div class="crf-detail-value">

                    <?= nl2br(h($crf['alternative_suggestion'] ?? '-')) ?>

                </div>
              
                <!-- IMPLEMENTASI & POST IMPLEMENTATION REVIEW -->
                <div id="implementation-review" class="mt-4 pt-3 border-top">

                    <div class="crf-section-header mb-3">
                        <span class="crf-section-number">
                            <i class="bi bi-clipboard-check"></i>
                        </span>

                        <h2>Implementasi & Post Implementation Review</h2>
                    </div>

                    <div class="alert alert-info mb-4">
                        Implementasi / Hasil Perubahan dan Post Implementation Review diisi oleh Otomasi setelah eksekusi perubahan selesai.
                    </div>

                    <!-- IMPLEMENTASI -->
                    <div class="mb-4">

                        <div class="crf-detail-label">
                            Implementasi / Hasil Perubahan
                        </div>

                        <div class="crf-detail-value">

                            <?php if (!empty($crf['implementation'])): ?>

                                <?= nl2br(h($crf['implementation'])) ?>

                            <?php else: ?>

                                <span class="text-muted">
                                    Belum diisi.
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                    <!-- POST IMPLEMENTATION REVIEW -->
                    <div class="mb-0">

                        <div class="crf-detail-label">
                            Post Implementation Review
                        </div>

                        <div class="crf-detail-value">

                            <?php if (!empty($crf['post_implementation_review'])): ?>

                                <?= nl2br(h($crf['post_implementation_review'])) ?>

                            <?php else: ?>

                                <span class="text-muted">
                                    Belum diisi.
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

        <!-- TIMELINE PROSES PENGAJUAN -->
        <div class="crf-section mt-4">

            <div class="crf-section-header">

                <span class="crf-section-number">
                    <i class="bi bi-clock-history"></i>
                </span>

                <h2>Timeline Proses Pengajuan</h2>

            </div>

            <div class="crf-section-body">

                <?php if (!$timeline): ?>

                    <div class="text-muted">
                        Belum ada riwayat proses pengajuan.
                    </div>

                <?php else: ?>

                    <div class="crf-timeline">

                        <?php foreach ($timeline as $item): ?>

                            <div class="crf-timeline-item">

                                <div class="crf-timeline-dot"></div>

                                <div class="crf-timeline-content">

                                    <div class="crf-timeline-top">

                                        <strong>
                                            <?= h(
                                                $item['activity'] === 'Solve'
                                                    ? 'Selesai'
                                                    : (
                                                        $item['activity'] === 'Cancel'
                                                            ? 'Dibatalkan'
                                                            : $item['activity']
                                                    )
                                            ) ?>
                                        </strong>

                                        <span class="crf-timeline-date">
                                            <?= h(
                                                date(
                                                    'd-m-Y H:i',
                                                    strtotime($item['created_at'])
                                                )
                                            ) ?>
                                        </span>

                                    </div>

                                    <?php if (!empty($item['description'])): ?>

                                        <div class="crf-timeline-description">
                                            <?= nl2br(h($item['description'])) ?>
                                        </div>

                                    <?php endif; ?>

                                    <?php if (!empty($item['actor'])): ?>

                                        <div class="crf-timeline-actor">
                                            Oleh: <?= h($item['actor']) ?>
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>