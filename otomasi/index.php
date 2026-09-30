<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireCrfRole(['otomasi']);

$pdo = getConnection();

$queue = $_GET['queue'] ?? 'all';
$search = trim($_GET['q'] ?? '');

if (!in_array($queue, ['all', 'sla', 'execution', 'history'], true)) {
    $queue = 'all';
}

$where = ["cr.status <> 'Draft'"];
$params = [];

/* =========================================================
 * FILTER ANTREAN
 * ========================================================= */

if ($queue === 'history') {
    $where[] = "EXISTS (
        SELECT 1
        FROM crf_activity_logs activity_log
        WHERE activity_log.change_request_id = cr.id
          AND activity_log.activity IN (
              'Proses Otomasi Diperbarui',
              'Otomasi - SLA Ditentukan',
              'Otomasi Selesai'
          )
    )";
} else {
    $where[] = "cr.workflow_stage = 'OTOMASI'";
}

if ($queue === 'sla') {
    $where[] = 'cr.kadep_operasional_approved_at IS NULL';
} elseif ($queue === 'execution') {
    $where[] = 'cr.kadep_operasional_approved_at IS NOT NULL';
}

/* =========================================================
 * PENCARIAN
 * ========================================================= */

if ($search !== '') {
    $where[] = '(
        cr.request_number LIKE :search_request
        OR cr.full_name LIKE :search_name
    )';

    $searchValue = '%' . $search . '%';
    $params['search_request'] = $searchValue;
    $params['search_name'] = $searchValue;
}

/* =========================================================
 * PAGINATION
 * ========================================================= */

$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

$countSql = "
    SELECT COUNT(*)
    FROM change_requests cr
    WHERE " . implode(' AND ', $where);

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/* =========================================================
 * AMBIL DATA
 * ========================================================= */

$sql = "
    SELECT cr.*
    FROM change_requests cr
    WHERE " . implode(' AND ', $where) . "
    ORDER BY " . ($queue === 'history'
        ? 'cr.updated_at DESC'
        : "CASE
            WHEN cr.kadep_operasional_approved_at IS NOT NULL THEN 0
            ELSE 1
        END,
        cr.automation_started_at ASC,
        cr.created_at ASC") . "
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

/* =========================================================
 * SUMMARY CARD
 * ========================================================= */

$summaryStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN kadep_operasional_approved_at IS NULL THEN 1 ELSE 0 END) AS waiting_sla,
        SUM(CASE WHEN kadep_operasional_approved_at IS NOT NULL THEN 1 ELSE 0 END) AS waiting_execution
    FROM change_requests
    WHERE workflow_stage = 'OTOMASI'
      AND status <> 'Draft'
");

$summary = $summaryStmt->fetch() ?: [];

$waitingSla = (int) ($summary['waiting_sla'] ?? 0);
$waitingExecution = (int) ($summary['waiting_execution'] ?? 0);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Otomasi';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .queue-filter-row {
        align-items: center;
    }

    .queue-filter-row .form-control,
    .queue-filter-row .btn {
        min-height: 38px;
    }

    .queue-filter-buttons {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .queue-filter-buttons .btn {
        white-space: nowrap;
    }
</style>

<div class="crf-page">
    <div class="container">

        <div class="crf-page-header">
            <h1>Otomasi</h1>
            <p>Menangani permintaan, menentukan Level Urgensi dan SLA, serta mengisi hasil implementasi.</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- =====================================================
             SUMMARY
             ===================================================== -->
        <div class="crf-stat-grid mb-4">
            <div class="crf-stat-card">
                <span>Menunggu Penentuan SLA</span>
                <strong><?= $waitingSla ?></strong>
            </div>

            <div class="crf-stat-card">
                <span>Menunggu Eksekusi</span>
                <strong><?= $waitingExecution ?></strong>
            </div>
        </div>

        <div class="crf-table-card">

            <div class="crf-table-heading">
                <h2><?= $queue === 'history' ? 'Riwayat Otomasi' : 'Antrean Otomasi' ?></h2>

                <!-- FILTER ANTREAN -->
                <div class="btn-group">
                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'all'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'all' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Semua
                    </a>

                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'sla'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'sla' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Penentuan SLA
                    </a>

                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'execution'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'execution' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Eksekusi
                    </a>
                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'history'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'history' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Riwayat
                    </a>
                </div>
            </div>

            <!-- SEARCH -->
            <form method="GET" class="row g-2 mb-3 queue-filter-row">
                <input type="hidden" name="queue" value="<?= h($queue) ?>">
                <div class="col-md-8">
                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        placeholder="Cari Nomor Register atau nama pengaju..."
                        value="<?= h($search) ?>"
                    >
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-crf-primary w-100">
                        <i class="bi bi-search"></i> Cari
                    </button>
                </div>

                <div class="col-md-2">
                    <a href="?queue=<?= h($queue) ?>" class="btn btn-crf-outline w-100">
                        Reset
                    </a>
                </div>
            </form>

            <div class="table-responsive crf-table-responsive-cards">
                <table class="table crf-table align-middle">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nomor Register</th>
                            <th>Pengaju</th>
                            <th>Level</th>
                            <th>SLA</th>
                            <?php if ($queue === 'history'): ?>
                                <th>Status</th>
                                <th>Tahap Saat Ini</th>
                            <?php else: ?>
                                <th>Mulai</th>
                            <?php endif; ?>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="<?= $queue === 'history' ? 8 : 7 ?>" class="text-center text-muted py-4">
                                <?= $queue === 'history'
                                    ? 'Belum ada CRF yang pernah diproses oleh Otomasi.'
                                    : 'Tidak ada CRF pada antrean Otomasi sesuai filter yang dipilih.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $i => $row): ?>
                            <tr>
                                <td data-label="No"><?= $offset + $i + 1 ?></td>

                                <td data-label="Nomor Register">
                                    <strong><?= h($row['request_number']) ?></strong>
                                </td>

                                <td data-label="Pengaju">
                                    <?= h($row['full_name']) ?>
                                </td>

                                <td data-label="Level">
                                    <span class="crf-badge <?= levelBadgeClass($row['level']) ?>">
                                        <?= h($row['level'] ?? 'Belum ditentukan') ?>
                                    </span>
                                </td>

                                <td data-label="SLA">
                                    <?= $row['sla_value'] !== null && $row['sla_unit']
                                        ? h(rtrim(rtrim(number_format((float) $row['sla_value'], 2, '.', ''), '0'), '.')) . ' ' . h($row['sla_unit'])
                                        : '-' ?>
                                </td>

                                <?php if ($queue === 'history'): ?>
                                    <td data-label="Status">
                                        <span class="crf-badge <?= statusBadgeClass($row['status']) ?>">
                                            <?= h(statusLabel($row['status'])) ?>
                                        </span>
                                    </td>
                                    <td data-label="Tahap Saat Ini">
                                        <span class="crf-badge <?= workflowStageBadgeClass($row['workflow_stage']) ?>">
                                            <?= h(workflowStageLabel($row['workflow_stage'])) ?>
                                        </span>
                                    </td>
                                <?php else: ?>
                                    <td data-label="Mulai">
                                        <?= !empty($row['automation_started_at'])
                                            ? h(date('d-m-Y H:i', strtotime($row['automation_started_at'])))
                                            : '-' ?>
                                    </td>
                                <?php endif; ?>

                                <td data-label="Aksi">
                                    <div class="d-flex gap-2">
                                        <a
                                            href="view_detail.php?id=<?= (int) $row['id'] ?>"
                                            class="btn btn-sm btn-crf-outline"
                                        >
                                            <i class="bi bi-eye"></i> Detail
                                        </a>

                                        <?php if ($queue !== 'history'): ?>
                                            <a
                                                href="detail.php?id=<?= (int) $row['id'] ?>"
                                                class="btn btn-sm <?= !empty($row['kadep_operasional_approved_at']) ? 'btn-danger' : 'btn-crf-primary' ?>"
                                            >
                                                <i class="bi <?= !empty($row['kadep_operasional_approved_at']) ? 'bi-play-circle' : 'bi-gear' ?>"></i>
                                                <?= !empty($row['kadep_operasional_approved_at']) ? 'Eksekusi' : 'Proses' ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- =================================================
                 PAGINATION
                 ================================================= -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Pagination Otomasi" class="mt-3">
                    <ul class="pagination justify-content-end mb-0">
                        <?php
                        $prevParams = $_GET;
                        $prevParams['page'] = max(1, $page - 1);

                        $nextParams = $_GET;
                        $nextParams['page'] = min($totalPages, $page + 1);
                        ?>

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="?<?= h(http_build_query($prevParams)) ?>"
                                aria-label="Previous"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php
                            $pageParams = $_GET;
                            $pageParams['page'] = $p;
                            ?>

                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a
                                    class="page-link"
                                    href="?<?= h(http_build_query($pageParams)) ?>"
                                >
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="?<?= h(http_build_query($nextParams)) ?>"
                                aria-label="Next"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
