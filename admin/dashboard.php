<?php
/**
 * admin/dashboard.php
 * ---------------------------------------------------------------
 * Menampilkan semua CRF yang sudah diajukan.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pdo = getConnection();

$search         = trim($_GET['q'] ?? '');
$statusFilter   = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';

$allowedStatuses = [
    'Belum Ditindak Lanjuti',
    'Perlu Revisi',
    'Dalam Proses',
    'Solve',
    'Cancel'
];

$allowedCategories = [
    'Aplikasi',
    'Infrastruktur',
    'Proses',
    'Security',
    'Lainnya'
];

$where  = ["cr.status <> 'Draft'"];
$params = [];


/* =========================================================
 * PENCARIAN / FILTER
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

if (in_array($statusFilter, $allowedStatuses, true)) {

    $where[] = 'cr.status = :status';

    $params['status'] = $statusFilter;
}

if (in_array($categoryFilter, $allowedCategories, true)) {

    $where[] = 'cr.change_category = :category';

    $params['category'] = $categoryFilter;
}


/* =========================================================
 * PAGINATION
 * ========================================================= */

$perPage = 10;

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);


/* =========================================================
 * HITUNG TOTAL DATA
 * ========================================================= */

$countSql = "
    SELECT COUNT(*)
    FROM change_requests cr
    WHERE " . implode(' AND ', $where);

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalRows = (int) $countStmt->fetchColumn();

$totalPages = max(
    1,
    (int) ceil($totalRows / $perPage)
);

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
    ORDER BY cr.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$requests = $stmt->fetchAll();


/* =========================================================
 * SUMMARY
 * ========================================================= */

$summaryStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'Belum Ditindak Lanjuti') AS pending,
        SUM(status = 'Perlu Revisi') AS revision,
        SUM(status = 'Dalam Proses') AS processing,
        SUM(status = 'Solve') AS solved,
        SUM(status = 'Cancel') AS cancelled
     FROM change_requests
     WHERE status <> 'Draft'"
);

$summary = $summaryStmt->fetch() ?: [];


$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Dashboard Admin';

require_once __DIR__ . '/../includes/header.php';
?>


<style>
    /* =========================================================
       DASHBOARD TABLE
       ========================================================= */

    .dashboard-table-wrap {
        overflow-x: auto;
        width: 100%;
    }

    .dashboard-table {
        width: 100%;
        min-width: 1180px;
        table-layout: fixed;
        margin-bottom: 0;
        font-size: 0.86rem;
    }

    .dashboard-table th,
    .dashboard-table td {
        vertical-align: middle;
        padding: 0.65rem 0.5rem;
    }

    .dashboard-table thead th {
        white-space: nowrap;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .dashboard-table tbody td {
        line-height: 1.35;
    }

    /* Lebar tiap kolom */
    .dashboard-table th:nth-child(1),
    .dashboard-table td:nth-child(1) {
        width: 42px;
        text-align: center;
    }

    .dashboard-table th:nth-child(2),
    .dashboard-table td:nth-child(2) {
        width: 145px;
    }

    .dashboard-table th:nth-child(3),
    .dashboard-table td:nth-child(3) {
        width: 92px;
    }

    .dashboard-table th:nth-child(4),
    .dashboard-table td:nth-child(4) {
        width: 95px;
    }

    .dashboard-table th:nth-child(5),
    .dashboard-table td:nth-child(5) {
        width: 105px;
    }

    .dashboard-table th:nth-child(6),
    .dashboard-table td:nth-child(6) {
        width: 82px;
    }

    .dashboard-table th:nth-child(7),
    .dashboard-table td:nth-child(7) {
        width: 95px;
    }

    .dashboard-table th:nth-child(8),
    .dashboard-table td:nth-child(8) {
        width: 110px;
    }

    .dashboard-table th:nth-child(9),
    .dashboard-table td:nth-child(9) {
        width: 125px;
    }

    .dashboard-table th:nth-child(10),
    .dashboard-table td:nth-child(10) {
        width: 110px;
    }

    .dashboard-table th:nth-child(11),
    .dashboard-table td:nth-child(11) {
        width: 145px;
    }

    .dashboard-table .register-cell {
        white-space: nowrap;
        font-weight: 600;
    }

    .dashboard-table .date-cell {
        white-space: nowrap;
    }

    .dashboard-table .name-cell,
    .dashboard-table .department-cell,
    .dashboard-table .division-cell,
    .dashboard-table .category-cell {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dashboard-table .stage-cell {
        white-space: nowrap;
    }

    .dashboard-table .action-cell {
        white-space: nowrap;
    }

    .dashboard-actions {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 0.35rem;
        flex-wrap: nowrap;
    }

    .dashboard-actions .btn {
        white-space: nowrap;
    }

    .dashboard-table .crf-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 100%;
        white-space: nowrap;
        font-size: 0.72rem;
    }

    .dashboard-stage-badge {
        display: inline-block;
        max-width: 100%;
        padding: 0.28rem 0.5rem;
        border-radius: 999px;
        background: #eef2ff;
        color: #334155;
        font-size: 0.72rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Filter bar */
    .dashboard-filter-row {
        align-items: center;
    }

    .dashboard-filter-row .form-control,
    .dashboard-filter-row .form-select {
        min-height: 38px;
    }

    .dashboard-filter-row .btn {
        min-height: 38px;
    }
</style>


<div class="crf-page">

    <div class="container">

        <div class="crf-page-header">

            <h1>
                Dashboard Change Request Form
            </h1>

            <p>
                Ringkasan seluruh pengajuan Change Request.
            </p>

        </div>


        <!-- =====================================================
             SUMMARY
             ===================================================== -->

        <div class="crf-stat-grid">

            <div class="crf-stat-card">
                <span>Total Pengajuan</span>
                <strong>
                    <?= (int) ($summary['total'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span>Belum Ditindak Lanjuti</span>
                <strong>
                    <?= (int) ($summary['pending'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span>Perlu Revisi</span>
                <strong>
                    <?= (int) ($summary['revision'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span>Dalam Proses</span>
                <strong>
                    <?= (int) ($summary['processing'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span>Selesai</span>
                <strong>
                    <?= (int) ($summary['solved'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span>Dibatalkan</span>
                <strong>
                    <?= (int) ($summary['cancelled'] ?? 0) ?>
                </strong>
            </div>

        </div>


        <?php if ($flash): ?>

            <div
                class="alert alert-<?= h($flash['type']) ?> crf-alert"
                role="alert"
            >
                <?= h($flash['message']) ?>
            </div>

        <?php endif; ?>


        <!-- =====================================================
             TABLE
             ===================================================== -->

        <div class="crf-table-card">

            <div class="crf-table-heading">

                <h2>
                    Pengajuan Terbaru
                </h2>

                <a
                    href="dashboard.php"
                    class="btn btn-sm btn-crf-outline"
                >
                    Lihat Semua
                </a>

            </div>


            <!-- FILTER -->
            <form
                method="GET"
                class="row g-2 mb-3 dashboard-filter-row"
            >

                <div class="col-md-5">

                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        placeholder="Cari Nomor Register atau nama pengaju..."
                        value="<?= h($search) ?>"
                    >

                </div>


                <div class="col-md-3">

                    <select
                        name="status"
                        class="form-select"
                    >

                        <option value="">
                            Semua Status
                        </option>

                        <?php foreach ($allowedStatuses as $s): ?>

                            <option
                                value="<?= h($s) ?>"
                                <?= $statusFilter === $s ? 'selected' : '' ?>
                            >
                                <?= h(statusLabel($s)) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3">

                    <select
                        name="category"
                        class="form-select"
                    >

                        <option value="">
                            Semua Kategori
                        </option>

                        <?php foreach ($allowedCategories as $c): ?>

                            <option
                                value="<?= h($c) ?>"
                                <?= $categoryFilter === $c ? 'selected' : '' ?>
                            >
                                <?= h($c) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-1">

                    <button
                        type="submit"
                        class="btn btn-crf-primary w-100"
                    >
                        <i class="bi bi-search"></i>
                    </button>

                </div>

            </form>


            <!-- TABLE -->
            <div class="table-responsive crf-table-responsive-cards">

                <table class="table crf-table dashboard-table align-middle">

                    <thead>

                        <tr>

                            <th>No</th>
                            <th>Nomor Register</th>
                            <th>Tanggal</th>
                            <th>Pengaju</th>
                            <th>Departemen</th>
                            <th>Divisi</th>
                            <th>Kategori</th>
                            <th>Level Urgensi</th>
                            <th>Status</th>
                            <th>Tahap</th>
                            <th>Aksi</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (!$requests): ?>

                            <tr>

                                <td
                                    colspan="11"
                                    class="text-center text-muted py-4"
                                >
                                    Belum ada CRF yang cocok dengan pencarian/filter ini.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($requests as $i => $row): ?>

                                <tr>

                                    <!-- NO -->
                                    <td data-label="No">
                                        <?= $offset + $i + 1 ?>
                                    </td>


                                    <!-- NOMOR REGISTER -->
                                    <td data-label="Nomor Register" class="register-cell">
                                        <?= h(
                                            $row['request_number']
                                            ?? '-'
                                        ) ?>
                                    </td>


                                    <!-- TANGGAL -->
                                    <td data-label="Tanggal" class="date-cell">

                                        <?php if (
                                            !empty(
                                                $row['submission_date']
                                            )
                                        ): ?>

                                            <?= h(
                                                date(
                                                    'd-m-Y',
                                                    strtotime(
                                                        $row['submission_date']
                                                    )
                                                )
                                            ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- PENGAJU -->
                                    <td
                                        data-label="Pengaju"
                                        class="name-cell"
                                        title="<?= h($row['full_name'] ?? '-') ?>"
                                    >
                                        <?= h(
                                            $row['full_name']
                                            ?? '-'
                                        ) ?>
                                    </td>


                                    <!-- DEPARTEMEN -->
                                    <td data-label="Departemen"
                                        class="department-cell"
                                        title="<?= h($row['from_department'] ?? '-') ?>"
                                    >
                                        <?= h(
                                            $row['from_department']
                                            ?? '-'
                                        ) ?>
                                    </td>


                                    <!-- DIVISI -->
                                    <td data-label="Divisi"
                                        class="division-cell"
                                        title="<?= h($row['from_division'] ?? '-') ?>"
                                    >
                                        <?= h(
                                            $row['from_division']
                                            ?? '-'
                                        ) ?>
                                    </td>


                                    <!-- KATEGORI -->
                                    <td data-label="Kategori"
                                        class="category-cell"
                                        title="<?= h($row['change_category'] ?? '-') ?>"
                                    >
                                        <?= h(
                                            $row['change_category']
                                            ?? '-'
                                        ) ?>
                                    </td>


                                    <!-- LEVEL -->
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


                                    <!-- STATUS -->
                                    <td data-label="Status" class="status-cell">

                                        <span
                                            class="crf-badge <?= statusBadgeClass($row['status']) ?>"
                                        >
                                            <?= h(
                                                statusLabel(
                                                    $row['status']
                                                )
                                            ) ?>
                                        </span>

                                    </td>


                                    <!-- TAHAP -->
                                    <td data-label=" Tahap" class="stage-cell">

                                        <span
                                            class="dashboard-stage-badge"
                                            title="<?= h(
                                                workflowStageLabel(
                                                    $row['workflow_stage']
                                                    ?? ''
                                                )
                                            ) ?>"
                                        >
                                            <?= h(
                                                workflowStageLabel(
                                                    $row['workflow_stage']
                                                    ?? ''
                                                )
                                            ) ?>
                                        </span>

                                    </td>


                                    <!-- AKSI -->
                                    <td data-label="Aksi" class="action-cell">

                                        <div class="dashboard-actions">

                                            <a
                                                href="detail.php?id=<?= (int) $row['id'] ?>"
                                                class="btn btn-sm btn-crf-outline"
                                            >
                                                <i class="bi bi-eye"></i>
                                                Detail
                                            </a>

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

                <nav
                    aria-label="Pagination dashboard"
                    class="mt-3"
                >

                    <ul class="pagination justify-content-end mb-0">

                        <?php

                        $prevParams = $_GET;
                        $prevParams['page'] = max(
                            1,
                            $page - 1
                        );

                        $nextParams = $_GET;
                        $nextParams['page'] = min(
                            $totalPages,
                            $page + 1
                        );

                        ?>


                        <!-- PREVIOUS -->
                        <li
                            class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                        >

                            <a
                                class="page-link"
                                href="?<?= h(
                                    http_build_query(
                                        $prevParams
                                    )
                                ) ?>"
                                aria-label="Previous"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>

                        </li>


                        <!-- NOMOR HALAMAN -->
                        <?php for (
                            $p = 1;
                            $p <= $totalPages;
                            $p++
                        ): ?>

                            <?php

                            $pageParams = $_GET;
                            $pageParams['page'] = $p;

                            ?>

                            <li
                                class="page-item <?= $p === $page ? 'active' : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="?<?= h(
                                        http_build_query(
                                            $pageParams
                                        )
                                    ) ?>"
                                >
                                    <?= $p ?>
                                </a>

                            </li>

                        <?php endfor; ?>


                        <!-- NEXT -->
                        <li
                            class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"
                        >

                            <a
                                class="page-link"
                                href="?<?= h(
                                    http_build_query(
                                        $nextParams
                                    )
                                ) ?>"
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
