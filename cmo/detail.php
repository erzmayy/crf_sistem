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
require_once __DIR__ . '/../includes/forum_discussions.php';

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
          cr.workflow_stage IN ('CMO_FILTER', 'UAT', 'PEMOHON_PIR', 'CMO_FINAL')
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
                <?php require __DIR__ . '/../includes/partials/forum_button.php'; ?>
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

                    <?php
                    $cmoDiscussion = forumOpenDiscussion($pdo, (int) $crf['id']);
                    $cmoSlaMissing = !crfHasValidSla($crf);
                    // Pembahasan dari sistem (SLA kosong) wajib diselesaikan CMO atau Admin, tidak boleh dibatalkan.
                    $cmoCanCancelDiscussion = $cmoDiscussion && !($cmoDiscussion['trigger_source'] === 'sistem' && $cmoSlaMissing);
                    ?>
                    <?php if ($cmoDiscussion): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-flag-fill"></i>
                            <strong>Menunggu Pembahasan Forum.</strong>
                            <?= $cmoDiscussion['trigger_source'] === 'sistem'
                                ? 'SLA default belum tersedia, sehingga CMO atau Admin perlu menetapkannya lewat pembahasan Forum.'
                                : 'Anda mengajukan pembahasan Level Urgensi dan SLA.' ?>
                            Setelah hasil pembahasan dicatat (CMO atau Admin), CRF langsung diteruskan ke Kepala Departemen Operasional
                            <?= !empty($cmoDiscussion['due_at']) ? '(target ' . h(date('d-m-Y H:i', strtotime($cmoDiscussion['due_at']))) . ')' : '' ?>.
                            <a href="../forum/index.php?crf_id=<?= (int) $crf['id'] ?>#forum-pembahasan">Buka pembahasan di Forum</a>
                        </div>
                    <?php elseif ($cmoSlaMissing): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            SLA default belum tersedia karena SLA standar kategori belum diatur. Saat Anda menekan
                            <strong>Teruskan</strong>, pembahasan Forum dibuka otomatis agar CMO atau Admin menetapkan SLA.
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
                                placeholder="Wajib diisi jika dikembalikan untuk perbaikan, dibatalkan, atau diajukan untuk pembahasan Forum (tuliskan alasannya)."
                            ><?= h($crf['tanggapan_tindak_lanjut'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">

                            <button
                                name="action"
                                value="to_approval"
                                class="btn btn-crf-primary"
                                <?= $cmoDiscussion ? 'disabled title="Menunggu hasil pembahasan Forum"' : '' ?>
                            >
                                <i class="bi bi-arrow-right-circle"></i>
                                Teruskan ke Kepala Departemen Operasional
                            </button>

                            <?php if (!$cmoDiscussion): ?>
                                <button
                                    name="action"
                                    value="request_discussion"
                                    class="btn btn-outline-secondary"
                                    title="Bahas di Forum apakah Level Urgensi dan SLA default tetap atau perlu diubah"
                                >
                                    <i class="bi bi-flag"></i>
                                    Ajukan Pembahasan Forum
                                </button>
                            <?php elseif ($cmoCanCancelDiscussion): ?>
                                <button
                                    name="action"
                                    value="cancel_discussion"
                                    class="btn btn-outline-secondary"
                                    formnovalidate
                                >
                                    <i class="bi bi-flag"></i>
                                    Batalkan Pembahasan Forum
                                </button>
                            <?php endif; ?>

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
                        Implementasi dan Post Implementation Review sudah diisi. Tutup CRF setelah memastikan semuanya lengkap.
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

                        </div>

                    </form>

                </div>

            </div>

        <?php elseif ($crf['workflow_stage'] === 'UAT'): ?>

            <?php
            $uatDocCount = count(array_filter($attachments, static fn(array $file): bool => ($file['category'] ?? '') === 'uat'));
            // Catatan serah terima terakhir dari PIC CRF (eksekusi selesai / dikirim ulang).
            $uatHandoffStmt = $pdo->prepare("
                SELECT activity, description, actor, created_at
                FROM crf_activity_logs
                WHERE change_request_id = :id AND activity IN ('Eksekusi Selesai', 'UAT Dikirim Ulang')
                ORDER BY id DESC
                LIMIT 1
            ");
            $uatHandoffStmt->execute(['id' => (int) $crf['id']]);
            $uatHandoff = $uatHandoffStmt->fetch() ?: null;
            $uatRoundStmt = $pdo->prepare("SELECT COUNT(*) FROM crf_activity_logs WHERE change_request_id = :id AND activity = 'UAT Perlu Perbaikan'");
            $uatRoundStmt->execute(['id' => (int) $crf['id']]);
            $uatRound = (int) $uatRoundStmt->fetchColumn() + 1;
            ?>
            <div class="crf-section crf-detail-card mb-4">

                <div class="crf-section-header">
                    <span class="crf-section-number"><i class="bi bi-clipboard2-check"></i></span>
                    <h2>UAT (User Acceptance Test) · Putaran <?= $uatRound ?></h2>
                </div>

                <div class="crf-section-body">

                    <p class="text-muted">
                        Otomasi
                        <?php if (!empty($crf['assigned_handler_name'])): ?>(<strong><?= h($crf['assigned_handler_name']) ?></strong>)<?php endif; ?>
                        sudah menyelesaikan eksekusi<?= !empty($crf['automation_completed_at']) ? ' pada ' . h(date('d-m-Y H:i', strtotime($crf['automation_completed_at']))) : '' ?>
                        dan SLA berhenti. Lakukan pengujian bersama PIC CRF (koordinasi lewat <strong>Diskusi Forum</strong>);
                        waktu UAT tidak dihitung dalam SLA.
                    </p>

                    <?php if ($uatHandoff && trim((string) $uatHandoff['description']) !== ''): ?>
                        <div class="crf-readonly-note mb-3">
                            <i class="bi bi-chat-left-text"></i>
                            <strong><?= h($uatHandoff['activity'] === 'UAT Dikirim Ulang' ? 'Perbaikan dari PIC:' : 'Serah terima dari PIC:') ?></strong>
                            <?= nl2br(h($uatHandoff['description'])) ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <?php $uatUploadBack = ''; require __DIR__ . '/../includes/partials/uat_upload_form.php'; ?>
                        <div class="mt-2 small <?= $uatDocCount > 0 ? 'text-success' : 'text-danger' ?>">
                            <i class="bi <?= $uatDocCount > 0 ? 'bi-check-circle' : 'bi-exclamation-circle' ?>"></i>
                            <?= $uatDocCount > 0
                                ? $uatDocCount . ' dokumen UAT sudah diunggah (lihat di bagian Detail Pengajuan).'
                                : 'Belum ada dokumen UAT. Unggah minimal satu dokumen sebelum menyatakan UAT lulus.' ?>
                        </div>
                    </div>

                    <form action="../actions/cmo_action.php" method="POST">

                        <?= csrfField() ?>

                        <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">

                        <div class="mb-3">
                            <label for="uat_tanggapan" class="form-label fw-semibold">
                                Catatan hasil UAT
                                <span class="text-muted fw-normal">(wajib bila perlu perbaikan)</span>
                            </label>
                            <textarea
                                id="uat_tanggapan"
                                name="tanggapan"
                                class="form-control"
                                rows="3"
                                placeholder="Temuan, error, atau hal yang perlu diperbaiki Otomasi..."
                            ></textarea>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button name="action" value="uat_pass" class="btn btn-success">
                                <i class="bi bi-check-circle"></i>
                                Lulus UAT
                            </button>
                            <button name="action" value="uat_fail" class="btn btn-outline-danger">
                                <i class="bi bi-arrow-counterclockwise"></i>
                                Perlu Perbaikan
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