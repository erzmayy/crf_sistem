<?php
/**
 * pak_joko/detail.php
 * ---------------------------------------------------------------
 * Halaman review & approval CRF oleh Kepala Departemen Operasional.
 *
 * Bagian yang sama dengan halaman role lain diambil dari
 * includes/partials/*. Bagian yang khusus (form approval) tetap
 * ditulis di file ini.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['kadep_operasional']);

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);


/* =========================================================
 * AMBIL DATA CRF
 * ========================================================= */
$stmt = $pdo->prepare("
    SELECT cr.*
    FROM change_requests cr
    WHERE cr.id = :id
      AND cr.status <> 'Draft'
      AND (
          cr.workflow_stage = 'kadep_operasional'
          OR EXISTS (
              SELECT 1
              FROM crf_activity_logs activity_log
              WHERE activity_log.change_request_id = cr.id
                AND (
                    activity_log.activity = 'Approval Kepala Departemen Operasional'
                    OR (
                        activity_log.activity = 'Perlu Revisi'
                        AND activity_log.description LIKE 'Kepala Departemen Operasional mengembalikan CRF ke Otomasi%'
                    )
                )
          )
      )
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();


/* =========================================================
 * JIKA CRF TIDAK DITEMUKAN
 * ========================================================= */
if (!$crf) {

    http_response_code(404);

    $pageTitle = 'CRF Tidak Ditemukan';

    require_once __DIR__ . '/../includes/header.php';
    ?>

    <div class="crf-page">
        <div class="container">

            <div class="alert alert-danger">
                CRF tidak ditemukan pada antrean approval Kepala Departemen Operasional.
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


/* =========================================================
 * FLASH
 * ========================================================= */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


$pageTitle = 'Kepala Departemen Operasional - Review CRF';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page">

    <div class="container">


        <!-- =====================================================
             HEADER
             ===================================================== -->
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 crf-page-header">

            <div>
                <h1>Review CRF</h1>

                <p>
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
             FLASH MESSAGE
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
                Status: <?= h(statusLabel($crf['status'] ?? '')) ?>
            </span>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['workflow_stage'] ?? 'kadep_operasional') ?>">
                Tahap: <?= h(workflowStageLabel($crf['workflow_stage'] ?? 'kadep_operasional')) ?>
            </span>

            <span class="crf-badge <?= levelBadgeClass($crf['level']) ?>">
                Level Urgensi: <?= h($crf['level'] ?? 'Belum ditentukan') ?>
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
        $sectionNumber = 1;
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


        <!-- =====================================================
             4. APPROVAL KEPALA DEPARTEMEN OPERASIONAL (khusus)
             ===================================================== -->
        <?php if ($crf['workflow_stage'] === 'kadep_operasional'): ?>
        <div class="crf-section mb-4">

            <div class="crf-section-header">
                <span class="crf-section-number">4</span>
                <h2>Approval Kepala Departemen Operasional</h2>
            </div>

            <div class="crf-section-body">

                <p class="text-muted">
                    Periksa detail CRF serta Level Urgensi dan SLA sebelum
                    menyetujui pelaksanaan oleh Otomasi.
                </p>

                <form action="../actions/pak_joko_approve.php" method="POST">

                    <?= csrfField() ?>

                    <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">

                    <div class="mb-3">

                        <label for="approval_note" class="form-label fw-semibold">
                            Catatan Approval
                            <span class="text-muted">(opsional)</span>
                        </label>

                        <textarea
                            name="approval_note"
                            id="approval_note"
                            class="form-control"
                            rows="4"
                            placeholder="Tuliskan catatan approval bila diperlukan..."
                        ><?= h($crf['kadep_operasional_approval_note'] ?? '') ?></textarea>

                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i>
                        Approve CRF
                    </button>

                </form>

            </div>

        </div>
        <?php endif; ?>


        <!-- 5. TIMELINE (tanpa nomor -> ikon jam, seperti versi lama) -->
        <?php
        $sectionTitle = 'Timeline Proses';
        require __DIR__ . '/../includes/partials/timeline.php';
        ?>


    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>