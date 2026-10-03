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
        old_status,
        new_status,
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

        <div class="crf-detail-layout">
          <main class="crf-detail-main">

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
        <?php
        require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
        ?>


        <!-- DETAIL PENGAJUAN -->
        <?php
        require __DIR__ . '/../includes/partials/detail_permintaan.php';
        ?>

        <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>


        <!-- IMPLEMENTASI & POST IMPLEMENTATION REVIEW -->
        <div id="implementation-review" class="crf-section crf-detail-card mb-4">

            <div class="crf-section-header">
                <span class="crf-section-number">
                    <i class="bi bi-clipboard-check"></i>
                </span>

                <h2>Implementasi & Post Implementation Review</h2>
            </div>

            <div class="crf-section-body">

                <?php $isPirStage = ($crf['workflow_stage'] ?? '') === 'PEMOHON_PIR'; ?>
                <?php if ($isPirStage): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-pencil-square"></i>
                        Otomasi sudah mencatat hasil implementasi. Silakan periksa hasilnya lalu isi
                        <strong>Tanggal PIR</strong> dan <strong>Post Implementation Review</strong> di bawah.
                    </div>
                <?php elseif (empty($crf['implementation'])): ?>
                    <div class="alert alert-info">
                        Implementasi akan dicatat oleh Otomasi. Setelah itu Anda diminta mengisi Post Implementation Review.
                    </div>
                <?php endif; ?>

                <div class="crf-info-rows">
                    <div class="crf-info-row">
                        <span class="crf-info-label">Tanggal Implementasi</span>
                        <div class="crf-info-value">
                            <?= !empty($crf['implementation_date'])
                                ? h(date('d-m-Y', strtotime($crf['implementation_date'])))
                                : '<span class="text-muted">Belum diisi.</span>' ?>
                        </div>
                    </div>
                    <?php if (!$isPirStage): ?>
                    <div class="crf-info-row">
                        <span class="crf-info-label">Tanggal PIR</span>
                        <div class="crf-info-value">
                            <?= !empty($crf['pir_date'])
                                ? h(date('d-m-Y', strtotime($crf['pir_date'])))
                                : '<span class="text-muted">Belum diisi.</span>' ?>
                        </div>
                    </div>
                    <div class="crf-info-row crf-request-row-long">
                        <span class="crf-info-label">Implementasi / Hasil Perubahan</span>
                        <div class="crf-info-value">
                            <?php if (!empty($crf['implementation'])): ?>
                                <?= nl2br(h($crf['implementation'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Belum diisi.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="crf-info-row crf-request-row-long">
                        <span class="crf-info-label">Post Implementation Review</span>
                        <div class="crf-info-value">
                            <?php if (!empty($crf['post_implementation_review'])): ?>
                                <?= nl2br(h($crf['post_implementation_review'])) ?>
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
                            <label for="pir_date" class="form-label fw-semibold">
                                Tanggal PIR <span class="text-danger">*</span>
                            </label>
                            <input
                                type="date"
                                id="pir_date"
                                name="pir_date"
                                class="form-control"
                                value="<?= h($crf['pir_date'] ?? date('Y-m-d')) ?>"
                                <?= !empty($crf['implementation_date']) ? 'min="' . h($crf['implementation_date']) . '"' : '' ?>
                                required
                            >
                        </div>

                        <div class="mb-4">
                            <label for="post_implementation_review" class="form-label fw-semibold">
                                Post Implementation Review <span class="text-danger">*</span>
                            </label>
                            <textarea
                                id="post_implementation_review"
                                name="post_implementation_review"
                                class="form-control"
                                rows="6"
                                required
                                placeholder="Apakah perubahan sudah sesuai kebutuhan? Tuliskan hasil evaluasi Anda setelah perubahan diterapkan..."
                            ><?= h($crf['post_implementation_review'] ?? '') ?></textarea>
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
          </main>
          <aside class="crf-detail-sidebar">
        <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>

          </aside>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>