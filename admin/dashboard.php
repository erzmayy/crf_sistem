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

$search         = $_GET['q'] ?? '';
$statusFilter   = $_GET['status'] ?? '';
$categoryFilter = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
$departmentFilter = $_GET['department'] ?? '';
$levelFilter = $_GET['level'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

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

$listFilters = applyCrfRequestFilters($pdo, $where, $params, [
    'search' => $search,
    'status' => $statusFilter,
    'department' => $departmentFilter,
    'level' => $levelFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);
$search = $listFilters['search'];
$statusFilter = $listFilters['status'];
$departmentFilter = $listFilters['department'];
$levelFilter = $listFilters['level'];
$dateFrom = $listFilters['date_from'];
$dateTo = $listFilters['date_to'];

if (in_array($categoryFilter, $allowedCategories, true)) {

    $where[] = 'cr.change_category = :category';

    $params['category'] = $categoryFilter;
}
/* =========================================================
 * PAGINATION
 * ========================================================= */

$perPage = getCrfPageSize($_GET['per_page'] ?? 6);

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
        overflow: visible;
        width: 100%;
        max-width: 100%;
        max-height: none;
    }

    .dashboard-table {
        width: 100%;
        min-width: 1028px;
        table-layout: fixed;
        margin-bottom: 0;
        font-size: 0.86rem;
    }

    .dashboard-table th,
    .dashboard-table td {
        vertical-align: middle;
        padding: 0.38rem 0.45rem;
    }

    .dashboard-table thead th {
        white-space: normal;
        overflow-wrap: break-word;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        position: sticky;
        top: var(--crf-topbar-height);
        z-index: 70;
        background: var(--crf-helpdesk-blue);
    }

    .crf-table.dashboard-table thead th {
        top: 0;
    }

    .dashboard-table tbody td {
        line-height: 1.2;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    /* Lebar tiap kolom */
    .dashboard-table th:nth-child(1),
    .dashboard-table td:nth-child(1) {
        width: 34px;
        text-align: center;
    }

    .dashboard-table th:nth-child(2),
    .dashboard-table td:nth-child(2) {
        width: 118px;
    }

    .dashboard-table th:nth-child(3),
    .dashboard-table td:nth-child(3) {
        width: 110px;
    }

    .dashboard-table th:nth-child(4),
    .dashboard-table td:nth-child(4) {
        width: 94px;
    }

    .dashboard-table th:nth-child(5),
    .dashboard-table td:nth-child(5) {
        width: 88px;
    }

    .dashboard-table th:nth-child(6),
    .dashboard-table td:nth-child(6) {
        width: 92px;
    }

    .dashboard-table th:nth-child(7),
    .dashboard-table td:nth-child(7) {
        width: 1px;
    }

    .dashboard-table th:nth-child(8),
    .dashboard-table td:nth-child(8) {
        width: 125px;
    }

    .dashboard-table th:nth-child(9),
    .dashboard-table td:nth-child(9) {
        width: 155px;
    }

    .dashboard-table th:nth-child(10),
    .dashboard-table td:nth-child(10) {
        width: 130px;
    }

    .dashboard-table th:nth-child(11),
    .dashboard-table td:nth-child(11) {
        width: 82px;
    }

    .dashboard-table .register-cell {
        white-space: nowrap;
        font-weight: 600;
    }

    .dashboard-table th:nth-child(7),
    .dashboard-table td:nth-child(7) {
        display: none;
    }

    .dashboard-table .title-cell {
        max-width: 140px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
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
        flex: 0 0 auto;
        white-space: nowrap;
        overflow-wrap: normal;
    }

    .dashboard-table .crf-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: none;
        white-space: nowrap;
        overflow-wrap: normal;
        line-height: 1.2;
        text-align: center;
        font-size: 0.72rem;
    }

    .dashboard-table .urgency-cell,
    .dashboard-table .urgency-cell .crf-badge {
        white-space: nowrap;
        overflow-wrap: normal;
    }

    .dashboard-table .urgency-cell .crf-badge {
        max-width: none;
    }

    .dashboard-table td:nth-child(9) .crf-badge {
        max-width: 100%;
        white-space: normal;
        overflow-wrap: anywhere;
    }


    .dashboard-stage-badge {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        padding: 0.22rem 0.3rem;
        border-radius: 999px;
        background: #eef2ff;
        color: #334155;
        font-size: 0.68rem;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    @media (max-width: 768px) {
        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap {
            overflow: visible;
            max-height: none;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table {
            display: block;
            width: 100%;
            min-width: 0;
            table-layout: auto;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table thead {
            display: none;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table tbody {
            display: block;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table tr {
            display: block;
            margin: 0 0 0.65rem;
            padding: 0.45rem 0.7rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table th,
        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table td {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            width: 100%;
            min-width: 0;
            max-width: none;
            padding: 0.38rem 0;
            border: 0;
            border-bottom: 1px solid #f1f5f9;
            white-space: normal !important;
            overflow-wrap: anywhere;
            text-align: right;
            line-height: 1.25;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table td::before {
            display: block;
            flex: 0 0 38%;
            content: attr(data-label);
            color: #64748b;
            font-size: 0.66rem;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table td:last-child {
            border-bottom: 0;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table td:last-child::before {
            display: block;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table td.crf-empty-cell {
            display: block;
            text-align: center;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table td.crf-empty-cell::before {
            display: none;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table td:nth-child(7) {
            display: none;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .title-cell,
        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .name-cell,
        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .department-cell {
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .urgency-cell .crf-badge,
        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .stage-cell .dashboard-stage-badge {
            max-width: 100%;
            white-space: nowrap;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .status-cell .crf-badge {
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .dashboard-actions {
            justify-content: flex-end;
            flex-wrap: nowrap !important;
        }

        .table-responsive.crf-table-responsive-cards.dashboard-table-wrap table.crf-table.dashboard-table .dashboard-actions .btn {
            width: auto;
        }
    }

    /* Filter bar */
    .dashboard-summary-grid {
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 0.55rem;
        margin-bottom: 0.9rem;
    }

    .dashboard-summary-grid .crf-stat-card {
        min-height: 58px;
        padding: 0.65rem 0.8rem;
    }

    .dashboard-summary-grid .crf-stat-card span {
        margin-bottom: 0.25rem;
        font-size: 0.66rem;
    }

    .dashboard-summary-grid .crf-stat-card strong {
        font-size: 1.15rem;
    }

    @media (max-width: 900px) {
        .dashboard-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 480px) {
        .dashboard-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>


<div class="crf-page crf-helpdesk-page">

    <div class="container">

        <div class="crf-helpdesk-banner">
            <div>
                <span class="crf-helpdesk-eyebrow">PORTAL CRF · ADMINISTRASI</span>
                <h1>Dashboard Change Request Form</h1>
                <p>Ringkasan seluruh pengajuan Change Request.</p>
            </div>
        </div>


        <!-- =====================================================
             SUMMARY
             ===================================================== -->

        <div class="crf-stat-grid crf-helpdesk-summary dashboard-summary-grid">

            <div class="crf-stat-card">
                <span><i class="bi bi-inboxes-fill"></i> Total Pengajuan</span>
                <strong>
                    <?= (int) ($summary['total'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-hourglass-split"></i> Belum Ditindak Lanjuti</span>
                <strong>
                    <?= (int) ($summary['pending'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-pencil-square"></i> Perlu Revisi</span>
                <strong>
                    <?= (int) ($summary['revision'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-arrow-repeat"></i> Dalam Proses</span>
                <strong>
                    <?= (int) ($summary['processing'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-check-circle-fill"></i> Selesai</span>
                <strong>
                    <?= (int) ($summary['solved'] ?? 0) ?>
                </strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-slash-circle-fill"></i> Dibatalkan</span>
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

        <div class="crf-table-card crf-list-table-card" id="crf-table">

            <div class="crf-table-heading">

                <h2>
                    Daftar Pengajuan Change Request
                </h2>

            </div>


            <!-- FILTER -->
            <?php
            $listContextName = '';
            $listContextValue = '';
            $listCategories = $allowedCategories;
            $listCategoryValue = $categoryFilter;
            $listResetUrl = 'dashboard.php';
            require __DIR__ . '/../includes/partials/crf_list_filters.php';
            unset($listContextName, $listContextValue, $listCategories, $listCategoryValue, $listResetUrl);
            ?>


            <!-- TABLE -->
            <div class="table-responsive crf-table-responsive-cards crf-helpdesk-table-wrap">

                <table class="table crf-table crf-helpdesk-table crf-helpdesk-table--admin align-middle">

                    <thead>

                        <tr>

                            <th>No</th>
                            <th>Keterangan Pengajuan</th>
                            <th>Isi Pengajuan</th>
                            <th>Level Urgensi</th>
                            <th>Status</th>
                            <th>Tahap</th>
                            <th>Aksi</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (!$requests): ?>

                            <tr>

                                <td data-label="Pengajuan" colspan="7" class="crf-empty-cell">
                                    <div class="crf-empty-state crf-empty-state-compact">
                                        <div class="crf-empty-icon">
                                            <i class="bi bi-search"></i>
                                        </div>
                                        <h3>CRF tidak ditemukan</h3>
                                        <p>Belum ada pengajuan yang sesuai dengan pencarian atau filter.</p>
                                    </div>
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($requests as $i => $row): ?>

                                <tr>

                                    <td data-label="No">
                                        <?= $offset + $i + 1 ?>
                                    </td>

                                    <td data-label="Keterangan Pengajuan">
                                        <div class="crf-request-meta">
                                            <strong><?= h($row['full_name'] ?? '-') ?></strong>
                                            <span class="crf-request-caption"><?= h($row['from_department'] ?? '-') ?></span>
                                            <span class="crf-request-caption">Nomor Register</span>
                                            <span class="crf-request-register"><?= h($row['request_number'] ?? '-') ?></span>
                                            <div class="crf-request-date-card">
                                                <span class="crf-request-caption">Tanggal Pengajuan</span>
                                                <span><?= !empty($row['submission_date'])
                                                    ? h(date('d-m-Y', strtotime($row['submission_date'])))
                                                    : '-' ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <td data-label="Isi Pengajuan">
                                        <div class="crf-request-content">
                                            <span class="crf-request-category-chip"><?= h($row['change_category'] ?? 'Lainnya') ?></span>
                                            <div class="crf-request-description"><?= h($row['change_description'] ?? '-') ?></div>
                                        </div>
                                    </td>


                                    <!-- LEVEL -->
                                    <td data-label="Level Urgensi">

                                        <span
                                            class="crf-badge <?= levelBadgeClass($row['level']) ?>"
                                        >
                                            <?= h(
                                                ($row['level'] ?? null) === 'Normal'
                                                    ? 'Sedang'
                                                    : ($row['level'] ?? 'Belum ditentukan')
                                            ) ?>
                                        </span>

                                    </td>


                                    <!-- STATUS -->
                                    <td data-label="Status">

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
                                    <td data-label="Tahap">

                                        <span
                                            class="crf-badge <?= workflowStageBadgeClass($row['workflow_stage'] ?? '') ?>"
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
                                    <td data-label="Aksi">

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

            <?php
            $paginationLabel = 'Navigasi halaman dashboard';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            unset($paginationLabel);
            ?>

        </div>

    </div>

</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
