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

$isExecutionStage = !empty($crf['kadep_operasional_approved_at']);
$isAssignedToOther = !empty($crf['assigned_handler_id']) && !isAssignedCrfHandler($crf);
$categoryHandlers = !empty($crf['crf_category_id']) && isAdmin()
    ? (categoryMembers($pdo, 'crf_category_handlers', 'crf_category_id')[(int) $crf['crf_category_id']] ?? [])
    : [];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = $isExecutionStage
    ? 'Otomasi - Eksekusi CRF'
    : 'Otomasi - Tentukan SLA';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-detail-page">

    <div class="container">

        <div class="crf-page-header d-flex justify-content-between align-items-start gap-2 flex-wrap">

            <div>

                <h1>
                    <?= $isExecutionStage
                        ? 'Eksekusi Otomasi'
                        : 'Proses Otomasi'
                    ?>
                </h1>

                <p>
                    Nomor Register:
                    <strong><?= h($crf['request_number']) ?></strong>
                    ·
                    Pengaju:
                    <?= h($crf['full_name']) ?>
                </p>

            </div>

            <a
                href="index.php"
                class="btn btn-crf-outline"
            >
                <i class="bi bi-arrow-left"></i>
                Kembali
            </a>

        </div>


        <?php if ($flash): ?>

            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>

        <?php endif; ?>


        <div class="d-flex gap-2 flex-wrap mb-4">
            <?php $resolvedUrgencyLevel = crfUrgencyForImpact($crf['impact_category'] ?? null) ?: ($crf['level'] ?? null); ?>

            <span class="crf-badge <?= workflowStageBadgeClass($crf['workflow_stage']) ?>">
                Tahap:
                <?= h(workflowStageLabel($crf['workflow_stage'])) ?>
            </span>

            <?php if ($resolvedUrgencyLevel !== null): ?>
                <span class="crf-badge <?= levelBadgeClass($resolvedUrgencyLevel) ?>">
                    Level Urgensi Sistem: <?= h($resolvedUrgencyLevel) ?>
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
            Hanya handler tersebut atau Admin yang dapat memprosesnya.
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


            <?php if (!$isExecutionStage): ?>

                <!-- =================================================
                     2. MENENTUKAN LEVEL & SLA
                     ================================================= -->

                <div class="crf-section mb-4">

                    <div class="crf-section-header">

                        <span class="crf-section-number">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <h2>
                            Menentukan Level Urgensi & SLA
                        </h2>

                    </div>


                    <div class="crf-section-body">

                        <div class="alert alert-info">
                            Periksa Level Urgensi otomatis dan tentukan SLA sebelum CRF
                            diteruskan ke Kepala Departemen Operasional
                            untuk approval.
                        </div>


                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">
                                    Level Urgensi Sistem
                                    <span class="text-danger">*</span>
                                </label>

                                <?php if (
                                    !empty($crf['level'])
                                    || crfUrgencyForImpact($crf['impact_category'] ?? null) !== null
                                ): ?>
                                    <div class="otomasi-urgency-field-value">
                                        <span class="crf-badge otomasi-urgency-badge <?= h(levelBadgeClass($resolvedUrgencyLevel)) ?>">
                                            <?= h($resolvedUrgencyLevel) ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <select name="level" class="form-select" required>
                                        <option value="">Pilih untuk CRF lama</option>
                                        <?php foreach (['Tinggi', 'Normal', 'Rendah'] as $legacyLevel): ?>
                                            <option
                                                value="<?= h($legacyLevel) ?>"
                                                <?= ($crf['level'] ?? '') === $legacyLevel ? 'selected' : '' ?>
                                            ><?= h($legacyLevel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="crf-readonly-note mt-2">
                                        Level belum tersedia untuk CRF lama ini.
                                    </div>
                                <?php endif; ?>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">
                                    Nilai SLA
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    name="sla_value"
                                    class="form-control"
                                    <?= !empty($crf['final_urgency_level']) ? 'readonly' : '' ?>
                                    value="<?= h(
                                        $crf['sla_value'] ?? ''
                                    ) ?>"
                                    required
                                >
                                <?php if (!empty($crf['final_urgency_level'])): ?>
                                    <div class="crf-readonly-note mt-2">
                                        SLA final sudah disepakati di Forum dan hanya dapat diperbarui oleh Admin atau CMO.
                                    </div>
                                <?php endif; ?>

                            </div>


                            <div class="col-md-4">

                                <label class="form-label fw-semibold">
                                    Satuan SLA
                                    <span class="text-danger">*</span>
                                </label>

                                <select
                                    name="sla_unit"
                                    class="form-select"
                                    <?= !empty($crf['final_urgency_level']) ? 'disabled' : '' ?>
                                    required
                                >

                                    <?php foreach (
                                        ['Menit', 'Jam', 'Hari']
                                        as $unit
                                    ): ?>

                                        <option
                                            value="<?= h($unit) ?>"
                                            <?= $crf['sla_unit'] === $unit
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= h($unit) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                        </div>

                    </div>

                </div>


                <div class="d-flex justify-content-end gap-2">

                    <button
                        type="submit"
                        name="action"
                        value="save"
                        class="btn btn-crf-outline"
                    >
                        <i class="bi bi-save"></i>
                        Simpan
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="complete"
                        class="btn btn-crf-primary"
                    >
                        <i class="bi bi-send"></i>
                        Submit
                    </button>

                </div>


            <?php else: ?>


                <!-- =================================================
                     2. EKSEKUSI PERUBAHAN
                     ================================================= -->

                <div class="crf-section mb-4">

                    <div class="crf-section-header">

                        <span class="crf-section-number">
                            <i class="bi bi-play-circle"></i>
                        </span>

                        <h2>
                            Eksekusi / Tangani Permintaan
                        </h2>

                    </div>


                    <div class="crf-section-body">

                        <div class="alert alert-success">

                            CRF sudah disetujui Kepala Departemen Operasional.
                            Otomasi dapat menjalankan eksekusi perubahan.

                        </div>


                        <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>


                        <div class="crf-readonly-note mb-4">

                            <i class="bi bi-info-circle"></i>

                            Sebelum menyelesaikan eksekusi, Otomasi wajib mengisi
                            <strong>Tanggal Implementasi</strong>,
                            <strong>Implementasi / Hasil Perubahan</strong>, dan
                            <strong>Tanggal PIR</strong> serta
                            <strong>Post Implementation Review</strong>.
                            Setelah itu CRF diteruskan ke CMO untuk penutupan.

                        </div>

                        <div class="mb-4">

                            <label
                                for="implementation_date"
                                class="form-label fw-semibold"
                            >
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

                            <label
                                for="implementation"
                                class="form-label fw-semibold"
                            >
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

                        <div class="mb-4">

                            <label
                                for="pir_date"
                                class="form-label fw-semibold"
                            >
                                Tanggal PIR
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="date"
                                id="pir_date"
                                name="pir_date"
                                class="form-control"
                                value="<?= h($crf['pir_date'] ?? '') ?>"
                                required
                            >

                        </div>

                        <div class="mb-4">

                            <label
                                for="post_implementation_review"
                                class="form-label fw-semibold"
                            >
                                Post Implementation Review
                                <span class="text-danger">*</span>
                            </label>

                            <textarea
                                id="post_implementation_review"
                                name="post_implementation_review"
                                class="form-control"
                                rows="6"
                                required
                                placeholder="Tuliskan hasil evaluasi setelah perubahan diterapkan..."
                            ><?= h($crf['post_implementation_review'] ?? '') ?></textarea>

                        </div>

                    </div>

                </div>


                <div class="d-flex justify-content-end">

                    <button
                        type="submit"
                        name="action"
                        value="complete"
                        class="btn btn-crf-primary"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Selesaikan
                    </button>

                </div>

            <?php endif; ?>

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
