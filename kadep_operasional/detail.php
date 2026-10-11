<?php
/**
 * kadep_operasional/detail.php
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
    FROM crf_pengajuan cr
    WHERE cr.id = :id
      AND cr.status <> 'Draft'
      AND (
          cr.tahap_alur = 'kadep_operasional'
          OR EXISTS (
              SELECT 1
              FROM crf_log_aktivitas activity_log
              WHERE activity_log.pengajuan_id = cr.id
                AND (
                    activity_log.aktivitas = 'Approval Kepala Departemen Operasional'
                    OR (
                        activity_log.aktivitas = 'Perlu Revisi'
                        AND activity_log.deskripsi LIKE 'Kepala Departemen Operasional mengembalikan CRF ke Otomasi%'
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
                CRF tidak ditemukan pada antrean persetujuan Kepala Departemen Operasional.
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


/* =========================================================
 * FLASH
 * ========================================================= */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


$pageTitle = 'Persetujuan Kepala Departemen Operasional';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-detail-page">

    <div class="container">


        <?php
        $dhEyebrow = 'Persetujuan Kepala Departemen Operasional';
        require __DIR__ . '/../includes/partials/detail_header.php';
        ?>

        <div class="crf-detail-layout">
          <div class="crf-detail-main">

        <!-- 1. INFORMASI PENGAJUAN -->
        <?php
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


        <!-- =====================================================
             4. APPROVAL KEPALA DEPARTEMEN OPERASIONAL (khusus)
             ===================================================== -->
        <?php if ($crf['tahap_alur'] === 'kadep_operasional'): ?>
        <div class="crf-section crf-detail-card crf-action-card mb-4">

            <div class="crf-section-header">
                <span class="crf-section-number"><i class="bi bi-person-check"></i></span>
                <h2>Tindakan Anda: setujui CRF ini</h2>
            </div>

            <div class="crf-section-body">

                <p class="text-muted">
                    Periksa detail CRF serta Level Urgensi dan SLA sebelum menyetujui.
                    SLA mulai dihitung sejak Anda menyetujui, lalu CRF diserahkan ke PIC CRF untuk dieksekusi.
                </p>

                <?php $approvalHasSla = slaDueAt(date('Y-m-d H:i:s'), $crf['sla_nilai'], $crf['sla_satuan']) !== null; ?>
                <?php if (!$approvalHasSla): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        SLA CRF ini belum tersedia sehingga belum dapat disetujui. Hubungi Admin.
                    </div>
                <?php endif; ?>

                <form action="../actions/kadep_operasional_approve.php" method="POST">

                    <?= csrfField() ?>

                    <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">

                    <div class="mb-3">

                        <label for="approval_note" class="form-label fw-semibold">
                            Catatan Persetujuan
                            <span class="text-danger">*</span>
                        </label>

                        <textarea
                            name="approval_note"
                            id="approval_note"
                            class="form-control"
                            rows="4"
                            required
                            placeholder="Tuliskan catatan persetujuan..."
                        ><?= h($crf['catatan_persetujuan_kadep'] ?? '') ?></textarea>

                    </div>

                    <button type="submit" class="btn btn-crf-primary" <?= $approvalHasSla ? '' : 'disabled' ?>>
                        <i class="bi bi-check-circle"></i>
                        Setujui
                    </button>

                </form>

            </div>

        </div>
        <?php endif; ?>

          </div><!-- /.crf-detail-main -->
          <aside class="crf-detail-sidebar">
        <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>

          </aside>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>