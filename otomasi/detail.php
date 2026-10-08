<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['otomasi']);

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT cr.*
    FROM change_requests cr
    WHERE cr.id = :id
      AND cr.workflow_stage = 'OTOMASI'
      AND cr.kadep_operasional_approved_at IS NOT NULL
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

$attStmt = $pdo->prepare(
    'SELECT *
     FROM attachments
     WHERE change_request_id = :id
     ORDER BY uploaded_at ASC'
);
$attStmt->execute(['id' => $id]);
$attachments = $attStmt->fetchAll();

$timelineStmt = $pdo->prepare("
    SELECT activity, description, actor, old_status, new_status, created_at
    FROM crf_activity_logs
    WHERE change_request_id = :id
    ORDER BY created_at ASC, id ASC
");
$timelineStmt->execute(['id' => $id]);
$timeline = $timelineStmt->fetchAll();

$isAssignedToOther = !empty($crf['assigned_handler_id']) && !isAssignedCrfHandler($crf);

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
        SELECT description, actor, created_at
        FROM crf_activity_logs
        WHERE change_request_id = :id AND activity = 'UAT Perlu Perbaikan'
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

        <div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">

            <div>

                <h1>Tindak Lanjut Otomasi · Implementasi</h1>

                <p>
                    Nomor Register:
                    <strong><?= h($crf['request_number']) ?></strong>
                    ·
                    Pengaju:
                    <?= h($crf['full_name']) ?>
                </p>

            </div>

            <div class="d-flex gap-2">
                <?php require __DIR__ . '/../includes/partials/forum_button.php'; ?>
                <a
                    href="index.php"
                    class="btn btn-crf-outline"
                >
                    <i class="bi bi-arrow-left"></i>
                    Kembali
                </a>
            </div>

        </div>


        <?php if ($flash): ?>

            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>

        <?php endif; ?>


        <div class="d-flex gap-2 flex-wrap mb-4">
            <?php $resolvedUrgencyLevel = crfEffectiveUrgency($crf); ?>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['workflow_stage']) ?>">
                Tahap:
                <?= h(workflowStageLabel($crf['workflow_stage'])) ?>
            </span>

            <?php if ($resolvedUrgencyLevel !== null): ?>
                <span class="crf-badge <?= levelBadgeClass($resolvedUrgencyLevel) ?>">
                    Level Urgensi: <?= h($resolvedUrgencyLevel) ?>
                </span>
            <?php endif; ?>

            <?php if (!empty($crf['sla_value']) && !empty($crf['sla_unit'])): ?>

                <span class="crf-badge badge-stage-pir">

                    SLA:
                    <?= h(
                        rtrim(
                            rtrim(
                                number_format(
                                    (float) $crf['sla_value'],
                                    2,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        )
                    ) ?>

                    <?= h($crf['sla_unit']) ?>

                </span>

            <?php endif; ?>

        </div>

        <?php require __DIR__ . '/../includes/partials/handler_assignment.php'; ?>

        <div class="crf-detail-layout">
          <main class="crf-detail-main">

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
            CRF ini sedang ditangani oleh <strong><?= h($crf['assigned_handler_name'] ?? '-') ?></strong>.
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

                <div class="crf-section mb-4">

                    <div class="crf-section-header">

                        <span class="crf-section-number">
                            <i class="bi <?= h($otomasiPhaseMeta['icon']) ?>"></i>
                        </span>

                        <h2>
                            <?= h($otomasiPhaseMeta['title']) ?>
                        </h2>

                    </div>


                    <div class="crf-section-body">

                        <?php if ($otomasiPhase === 'eksekusi'): ?>

                            <div class="alert alert-success">
                                CRF sudah disetujui Kepala Departemen Operasional.
                                Otomasi dapat menjalankan eksekusi perubahan.
                            </div>

                            <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>

                            <div class="crf-readonly-note mb-4">
                                <i class="bi bi-info-circle"></i>
                                Setelah eksekusi selesai, tekan <strong>Selesai Eksekusi</strong>. SLA berhenti saat itu
                                dan CRF diteruskan ke CMO untuk <strong>UAT</strong> (pengujian bersama Otomasi; waktu UAT
                                tidak dihitung dalam SLA). Setelah UAT lulus, Otomasi mengisi
                                <strong>Tanggal Implementasi</strong> dan <strong>Implementasi / Hasil Perubahan</strong>,
                                lalu Pemohon mengisi Post Implementation Review.
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
                                        <?= nl2br(h($uatFailNote['description'])) ?>
                                        <small class="d-block text-muted">
                                            <?= h($uatFailNote['actor']) ?> · <?= h(date('d-m-Y H:i', strtotime($uatFailNote['created_at']))) ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>

                            <div class="crf-readonly-note mb-4">
                                <i class="bi bi-info-circle"></i>
                                Perbaiki sesuai catatan CMO (koordinasi lewat tombol <strong>Diskusi Forum</strong>), lalu
                                kirim ulang untuk UAT. SLA sudah berhenti saat eksekusi pertama selesai, jadi perbaikan dan
                                UAT ulang tidak dihitung dalam SLA.
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
                                <strong>UAT lulus</strong><?= !empty($crf['uat_passed_at']) ? ' pada ' . h(date('d-m-Y H:i', strtotime($crf['uat_passed_at']))) : '' ?>.
                                Isi hasil implementasi, lalu CRF diteruskan ke Pemohon untuk Post Implementation Review.
                            </div>

                            <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>

                            <div class="crf-readonly-note mb-4">
                                <i class="bi bi-info-circle"></i>
                                Wajib mengisi <strong>Tanggal Implementasi</strong> dan
                                <strong>Implementasi / Hasil Perubahan</strong>.
                                Setelah itu Pemohon mengisi Post Implementation Review,
                                lalu CRF diteruskan ke CMO untuk penutupan.
                            </div>

                            <div class="mb-4">
                                <label for="implementation_date" class="form-label fw-semibold">
                                    Tanggal Implementasi
                                    <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="date"
                                    id="implementation_date"
                                    name="implementation_date"
                                    class="form-control"
                                    value="<?= h($crf['implementation_date'] ?? '') ?>"
                                    required
                                >
                            </div>

                            <div class="mb-4">
                                <label for="implementation" class="form-label fw-semibold">
                                    Implementasi / Hasil Perubahan
                                    <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    id="implementation"
                                    name="implementation"
                                    class="form-control"
                                    rows="6"
                                    required
                                    placeholder="Tuliskan hasil atau perubahan yang sudah diterapkan..."
                                ><?= h($crf['implementation'] ?? '') ?></textarea>
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

          </main>
          <aside class="crf-detail-sidebar">
            <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>
          </aside>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
