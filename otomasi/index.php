<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pdo = getConnection();

$stmt = $pdo->query("
    SELECT cr.*
    FROM change_requests cr
    WHERE cr.workflow_stage = 'OTOMASI'
      AND cr.status <> 'Draft'
    ORDER BY
        CASE
            WHEN cr.pak_joko_approved_at IS NOT NULL THEN 0
            ELSE 1
        END,
        cr.automation_started_at ASC,
        cr.created_at ASC
");

$requests = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Otomasi';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page">

    <div class="container">

        <div class="crf-page-header">

            <h1>
                Otomasi
            </h1>

            <p>
                Menentukan Level Urgensi dan SLA sebelum approval,
                lalu melakukan eksekusi setelah disetujui Kepala
                Departemen Operasional.
            </p>

        </div>


        <?php if ($flash): ?>

            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>

        <?php endif; ?>


        <div class="crf-table-card">

            <div class="crf-table-heading">
                <h2>Antrean Otomasi</h2>
            </div>


            <div class="table-responsive crf-table-responsive-cards">

                <table class="table crf-table align-middle">

                    <thead>

                        <tr>
                            <th>No</th>
                            <th>Nomor Register</th>
                            <th>Pengaju</th>
                            <th>Level</th>
                            <th>SLA</th>
                            <th>Mulai</th>
                            <th>Mode</th>
                            <th>Aksi</th>
                        </tr>

                    </thead>


                    <tbody>

                    <?php if (!$requests): ?>

                        <tr>

                            <td
                                colspan="8"
                                class="text-center text-muted py-4"
                            >
                                Tidak ada CRF yang sedang menunggu Otomasi.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($requests as $i => $row): ?>

                            <?php
                            $executionMode = !empty(
                                $row['pak_joko_approved_at']
                            );
                            ?>

                            <tr>

                                <td data-label="No">
                                    <?= $i + 1 ?>
                                </td>

                                <td data-label="Nomor Register">
                                    <strong>
                                        <?= h($row['request_number']) ?>
                                    </strong>
                                </td>

                                <td data-label="Pengaju">
                                    <?= h($row['full_name']) ?>
                                </td>

                                <td data-label="Level">

                                    <span
                                        class="crf-badge <?= levelBadgeClass($row['level']) ?>"
                                    >
                                        <?= h(
                                            $row['level']
                                            ?? 'Belum ditentukan'
                                        ) ?>
                                    </span>

                                </td>

                                <td data-label="SLA">

                                    <?= $row['sla_value'] !== null
                                        && $row['sla_unit']
                                        ? h(
                                            rtrim(
                                                rtrim(
                                                    number_format(
                                                        (float) $row['sla_value'],
                                                        2,
                                                        '.',
                                                        ''
                                                    ),
                                                    '0'
                                                ),
                                                '.'
                                            )
                                        )
                                        . ' '
                                        . h($row['sla_unit'])
                                        : '-' ?>

                                </td>

                                <td data-label="Mulai">

                                    <?= !empty(
                                        $row['automation_started_at']
                                    )
                                        ? h(
                                            date(
                                                'd-m-Y H:i',
                                                strtotime(
                                                    $row[
                                                        'automation_started_at'
                                                    ]
                                                )
                                            )
                                        )
                                        : '-' ?>

                                </td>

                                <td data-label="Mode">

                                    <?php if ($executionMode): ?>

                                        <span class="crf-badge badge-stage-cmo-final">
                                            Eksekusi
                                        </span>

                                    <?php else: ?>

                                        <span class="crf-badge badge-stage-otomasi">
                                            Tentukan SLA
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td data-label="Aksi">

                                    <div class="d-flex gap-2">

                                        <a
                                            href="view_detail.php?id=<?= (int) $row['id'] ?>"
                                            class="btn btn-sm btn-crf-outline"
                                        >
                                            <i class="bi bi-eye"></i>
                                            Detail
                                        </a>

                                        <a
                                            href="detail.php?id=<?= (int) $row['id'] ?>"
                                            class="btn btn-sm btn-crf-primary"
                                        >
                                            <i class="bi bi-gear"></i>

                                            <?= $executionMode
                                                ? 'Eksekusi'
                                                : 'Tentukan SLA' ?>

                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
