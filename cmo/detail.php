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
require_once __DIR__ . '/../includes/forum_proposals.php';

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
      AND (
          cr.workflow_stage IN ('CMO_FILTER', 'PEMOHON_PIR', 'CMO_FINAL')
          OR EXISTS (
              SELECT 1
              FROM crf_activity_logs activity_log
              WHERE activity_log.change_request_id = cr.id
                AND (
                    activity_log.activity = 'Lolos Filter CMO'
                    OR (
                        activity_log.activity = 'Perlu Revisi'
                        AND activity_log.description = 'CRF dikembalikan ke Pemohon untuk revisi.'
                    )
                    OR activity_log.activity IN ('Cancel', 'Solve')
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
        old_status,
        new_status,
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

<div class="crf-page crf-detail-page" id="cmo-detail-page">

    <div class="container">


        <!-- =====================================================
             HEADER
             ===================================================== -->
        <div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">

            <div>
                <h1>Verifikasi Permohonan Perubahan</h1>

                <p class="mb-0">
                    Nomor Register:
                    <strong><?= h($crf['request_number'] ?? '-') ?></strong>
                </p>
            </div>

            <div class="d-flex gap-2">
                <a href="../actions/export_crf.php?id=<?= (int) $crf['id'] ?>" class="btn btn-crf-primary" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-pdf"></i> Export PDF
                </a>
                <a href="index.php" class="btn btn-crf-outline">
                    <i class="bi bi-arrow-left"></i>
                    Kembali
                </a>
            </div>

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
        <div class="crf-status-strip mb-4">

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

        <div class="crf-detail-layout">
          <main class="crf-detail-main">

        <!-- 1. INFORMASI PENGAJUAN -->
        <?php
        $showStatusInGrid = true;
        require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
        ?>

        <!-- 2. DETAIL PERMINTAAN -->
        <?php
        require __DIR__ . '/../includes/partials/detail_permintaan.php';
        ?>

        <!-- =====================================================
             3. IMPLEMENTASI & PIR (khusus tampilan CMO, read-only)
             ===================================================== -->
        <div class="crf-section crf-detail-card mb-4">

            <div class="crf-section-header">
                <span class="crf-section-number"><i class="bi bi-clipboard-check"></i></span>
                <h2>Implementasi & Post Implementation Review</h2>
            </div>

            <div class="crf-section-body">

                <div class="crf-info-rows">
                    <div class="crf-info-row">
                        <span class="crf-info-label">Tanggal Implementasi</span>
                        <div class="crf-info-value">
                            <?= !empty($crf['implementation_date'])
                                ? h(date('d-m-Y', strtotime($crf['implementation_date'])))
                                : '<span class="text-muted">Belum diisi oleh Otomasi.</span>' ?>
                        </div>
                    </div>
                    <div class="crf-info-row crf-request-row-long">
                        <span class="crf-info-label">Implementasi / Hasil Perubahan</span>
                        <div class="crf-info-value">
                            <?php if (!empty($crf['implementation'])): ?>
                                <?= nl2br(h($crf['implementation'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Belum diisi oleh Otomasi.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="crf-info-row">
                        <span class="crf-info-label">Tanggal PIR</span>
                        <div class="crf-info-value">
                            <?= !empty($crf['pir_date'])
                                ? h(date('d-m-Y', strtotime($crf['pir_date'])))
                                : '<span class="text-muted">Belum diisi oleh Pemohon.</span>' ?>
                        </div>
                    </div>
                    <div class="crf-info-row crf-request-row-long">
                        <span class="crf-info-label">Post Implementation Review</span>
                        <div class="crf-info-value">
                            <?php if (!empty($crf['post_implementation_review'])): ?>
                                <?= nl2br(h($crf['post_implementation_review'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Belum diisi oleh Pemohon.</span>
                            <?php endif; ?>
                        </div>
                    </div>
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
        require __DIR__ . '/../includes/partials/informasi_sla.php';
        ?>


        <!-- =====================================================
             5. REVIEW CMO / FINALISASI (khusus CMO)
             ===================================================== -->
        <?php if ($crf['workflow_stage'] === 'CMO_FILTER'): ?>

            <div class="crf-section crf-detail-card mb-4">

                <div class="crf-section-header">
                    <span class="crf-section-number"><i class="bi bi-chat-square-text"></i></span>
                    <h2>Verifikasi CMO</h2>
                </div>

                <div class="crf-section-body">

                    <?php $cmoOpenProposal = forumOpenProposal($pdo, (int) $crf['id']); ?>
                    <?php if ($cmoOpenProposal): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-flag-fill"></i>
                            Ada <strong>usulan <?= h(strtolower(forumProposalKindLabel($cmoOpenProposal['kind']))) ?></strong>
                            dari <?= h($cmoOpenProposal['proposed_by_name']) ?> yang menunggu keputusan
                            <?= !empty($cmoOpenProposal['due_at']) ? '(batas ' . h(date('d-m-Y H:i', strtotime($cmoOpenProposal['due_at']))) . ')' : '' ?>.
                            CRF tetap dapat diteruskan; bila belum diputuskan, nilai yang berlaku adalah SLA standar kategori.
                            <a href="../forum/index.php?crf_id=<?= (int) $crf['id'] ?>#forum-proposal">Lihat pembahasan di Forum</a>
                        </div>
                    <?php endif; ?>

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
                                placeholder="Wajib diisi jika dikembalikan untuk perbaikan atau dibatalkan."
                            ><?= h($crf['tanggapan_tindak_lanjut'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">

                            <button
                                name="action"
                                value="to_approval"
                                class="btn btn-crf-primary"
                            >
                                <i class="bi bi-arrow-right-circle"></i>
                                Teruskan ke Kepala Departemen Operasional
                            </button>

                            <a
                                href="../forum/index.php?crf_id=<?= (int) $crf['id'] ?>&amp;propose=1#forum-proposal"
                                class="btn btn-outline-secondary"
                                title="Usulkan perubahan Level Urgensi/SLA untuk dibahas dan diputuskan di Forum"
                            >
                                <i class="bi bi-flag"></i>
                                <?= $cmoOpenProposal ? 'Lihat Pembahasan Urgensi/SLA' : 'Ajukan Pembahasan Urgensi/SLA' ?>
                            </a>

                            <button
                                name="action"
                                value="revision"
                                class="btn btn-warning"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Kembalikan untuk Perbaikan
                            </button>

                            <button
                                name="action"
                                value="cancel"
                                class="btn btn-outline-danger"
                            >
                                <i class="bi bi-x-circle"></i>
                                Batalkan Permohonan
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        <?php elseif ($crf['workflow_stage'] === 'CMO_FINAL'): ?>

            <div class="crf-section crf-detail-card mb-4">

                <div class="crf-section-header">
                    <span class="crf-section-number"><i class="bi bi-check2-circle"></i></span>
                    <h2>Finalisasi</h2>
                </div>

                <div class="crf-section-body">

                    <p class="text-muted">

                        Otomasi sudah menyelesaikan eksekusi dan mengisi
                        Implementasi / Hasil Perubahan, lalu Pemohon sudah mengisi
                        Post Implementation Review.
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

        <?php elseif ($crf['workflow_stage'] === 'PEMOHON_PIR'): ?>

            <?php $pirReminder = crfPirReminderInfo($pdo, $crf); ?>
            <div class="crf-section crf-detail-card mb-4">

                <div class="crf-section-header">
                    <span class="crf-section-number"><i class="bi bi-bell"></i></span>
                    <h2>Menunggu PIR Pemohon</h2>
                </div>

                <div class="crf-section-body">

                    <div class="alert alert-warning">
                        Implementasi selesai
                        <?php if (!empty($crf['automation_completed_at'])): ?>
                            pada <strong><?= h(date('d-m-Y H:i', strtotime($crf['automation_completed_at']))) ?></strong>
                            (<?= $pirReminder['waiting_days'] > 0 ? (int) $pirReminder['waiting_days'] . ' hari kerja lalu' : 'kurang dari 1 hari kerja' ?>),
                        <?php endif; ?>
                        tetapi Pemohon belum mengisi Post Implementation Review.
                        CRF baru bisa difinalisasi setelah PIR diisi.
                    </div>

                    <p class="crf-readonly-note mb-3">
                        <?php if ($pirReminder['count'] > 0): ?>
                            <i class="bi bi-clock-history"></i>
                            Sudah <?= (int) $pirReminder['count'] ?> kali diingatkan, terakhir
                            <?= h(date('d-m-Y H:i', strtotime($pirReminder['last_at']))) ?>.
                        <?php else: ?>
                            <i class="bi bi-info-circle"></i> Belum pernah diingatkan.
                        <?php endif; ?>
                        Pengingat dikirim sebagai notifikasi ke Pemohon, maksimal sekali per 24 jam.
                    </p>

                    <form action="../actions/cmo_action.php" method="POST">

                        <?= csrfField() ?>

                        <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">

                        <div class="mb-3">
                            <label for="pir_reminder_note" class="form-label fw-semibold">Pesan tambahan (opsional)</label>
                            <textarea
                                id="pir_reminder_note"
                                name="tanggapan"
                                class="form-control"
                                rows="2"
                                maxlength="500"
                                placeholder="Contoh: Mohon diisi paling lambat hari Jumat."
                            ></textarea>
                        </div>

                        <button
                            name="action"
                            value="remind_pir"
                            class="btn btn-crf-primary"
                            <?= $pirReminder['can_send'] ? '' : 'disabled' ?>
                        >
                            <i class="bi bi-bell"></i>
                            Kirim Pengingat ke Pemohon
                        </button>

                        <?php if (!$pirReminder['can_send'] && $pirReminder['next_at']): ?>
                            <div class="crf-readonly-note mt-2">
                                Pengingat berikutnya bisa dikirim setelah <?= h(date('d-m-Y H:i', strtotime($pirReminder['next_at']))) ?>.
                            </div>
                        <?php endif; ?>

                    </form>

                </div>

            </div>

        <?php endif; ?>

          </main>
          <aside class="crf-detail-sidebar">
        <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>

          </aside>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>