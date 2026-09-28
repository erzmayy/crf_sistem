<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

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


$isExecutionStage = !empty($crf['pak_joko_approved_at']);


$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = $isExecutionStage
    ? 'Otomasi - Eksekusi CRF'
    : 'Otomasi - Tentukan SLA';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page">

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

            <span class="crf-badge <?= workflowStageBadgeClass($crf['workflow_stage']) ?>">
                Tahap:
                <?= h(workflowStageLabel($crf['workflow_stage'])) ?>
            </span>

            <?php if (!empty($crf['level'])): ?>

                <span class="crf-badge <?= levelBadgeClass($crf['level']) ?>">
                    Level:
                    <?= h($crf['level']) ?>
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


        <!-- =====================================================
             1. PERMINTAAN
             ===================================================== -->

        <div class="crf-section mb-4">

            <div class="crf-section-header">

                <span class="crf-section-number">
                    1
                </span>

                <h2>
                    Permintaan
                </h2>

            </div>

            <div class="crf-section-body">

                <div class="crf-detail-label">
                    Rincian Permohonan Perubahan
                </div>

                <div class="crf-detail-value mb-3">
                    <?= nl2br(
                        h(
                            $crf['change_description']
                            ?? '-'
                        )
                    ) ?>
                </div>


                <div class="crf-detail-label">
                    Alasan / Tujuan
                </div>

                <div class="crf-detail-value">
                    <?= nl2br(
                        h(
                            $crf['reason']
                            ?? '-'
                        )
                    ) ?>
                </div>

            </div>

        </div>


        <!-- =====================================================
             FORM OTOMASI
             ===================================================== -->

        <form
            action="../actions/automation_action.php"
            method="POST"
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
                            2
                        </span>

                        <h2>
                            Menentukan Level Urgensi & SLA
                        </h2>

                    </div>


                    <div class="crf-section-body">

                        <div class="alert alert-info">
                            Tentukan Level Urgensi dan SLA sebelum CRF
                            diteruskan ke Kepala Departemen Operasional
                            untuk approval.
                        </div>


                        <div class="row g-3">

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">
                                    Level Urgensi
                                    <span class="text-danger">*</span>
                                </label>

                                <select
                                    name="level"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Belum ditentukan
                                    </option>

                                    <?php foreach (
                                        ['Tinggi', 'Normal', 'Rendah']
                                        as $level
                                    ): ?>

                                        <option
                                            value="<?= h($level) ?>"
                                            <?= $crf['level'] === $level
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= h($level) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

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
                                    value="<?= h(
                                        $crf['sla_value'] ?? ''
                                    ) ?>"
                                    required
                                >

                            </div>


                            <div class="col-md-4">

                                <label class="form-label fw-semibold">
                                    Satuan SLA
                                    <span class="text-danger">*</span>
                                </label>

                                <select
                                    name="sla_unit"
                                    class="form-select"
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
                            2
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


                        <div class="row g-3 mb-4">

                            <div class="col-md-4">

                                <div class="crf-detail-label">
                                    LEVEL URGENSI
                                </div>

                                <div class="crf-detail-value">
                                    <?= h(
                                        $crf['level']
                                        ?? 'Belum ditentukan'
                                    ) ?>
                                </div>

                            </div>


                            <div class="col-md-4">

                                <div class="crf-detail-label">
                                    SLA
                                </div>

                                <div class="crf-detail-value">
                                    <?php if (
                                        !empty($crf['sla_value'])
                                        && !empty($crf['sla_unit'])
                                    ): ?>

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

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>
                                </div>

                            </div>


                            <div class="col-md-4">

                                <div class="crf-detail-label">
                                    APPROVAL
                                </div>

                                <div class="crf-detail-value">

                                    <?= !empty(
                                        $crf['pak_joko_approved_at']
                                    )
                                        ? h(
                                            date(
                                                'd-m-Y H:i',
                                                strtotime(
                                                    $crf['pak_joko_approved_at']
                                                )
                                            )
                                        )
                                        : '-' ?>

                                </div>

                            </div>

                        </div>


                        <div class="crf-readonly-note mb-4">

                            <i class="bi bi-info-circle"></i>

                            Sebelum menyelesaikan eksekusi, Otomasi wajib mengisi
                            <strong>Implementasi / Hasil Perubahan</strong> dan
                            <strong>Post Implementation Review</strong>.
                            Setelah itu CRF diteruskan ke CMO untuk penutupan.

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
                        Selesai Eksekusi 
                    </button>

                </div>

            <?php endif; ?>

        </form>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
