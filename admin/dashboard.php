<?php
/**
 * admin/dashboard.php
 * ---------------------------------------------------------------
 * Menampilkan semua CRF yang sudah diajukan.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_crf_report.php';
require_once __DIR__ . '/../includes/forum.php';

requireAdmin();

$pdo = getConnection();
$forumUnread = forumUnreadTotal($pdo, (int) getCurrentUser()['id']);

$search         = $_GET['q'] ?? '';
$statusFilter   = $_GET['status'] ?? '';
$departmentFilter = $_GET['department'] ?? '';
$levelFilter = $_GET['level'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

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
    'category_id' => $_GET['category_id'] ?? '',
    'handler_id' => $_GET['handler_id'] ?? '',
    'requester' => $_GET['requester'] ?? '',
    'display_status' => $_GET['display_status'] ?? '',
]);
$search = $listFilters['search'];
$statusFilter = $listFilters['status'];
$departmentFilter = $listFilters['department'];
$levelFilter = $listFilters['level'];
$dateFrom = $listFilters['date_from'];
$dateTo = $listFilters['date_to'];

// Daftar handler untuk filter (pernah memegang CRF atau terdaftar di kategori).
$listHandlers = $pdo->query("
    SELECT DISTINCT user_id AS id, user_name AS name FROM (
        SELECT assigned_handler_id AS user_id, assigned_handler_name AS user_name
        FROM change_requests WHERE assigned_handler_id IS NOT NULL
        UNION
        SELECT user_id, user_name FROM crf_category_handlers
    ) handlers
    WHERE user_name IS NOT NULL
    ORDER BY name
")->fetchAll();
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
    SELECT cr.*, cc.name AS crf_category_name
    FROM change_requests cr
    LEFT JOIN crf_categories cc ON cc.id = cr.crf_category_id
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

// Ringkasan per status tampilan alur Helpdesk/CRF (termasuk Draft).
$displayConditions = crfDisplayStatusConditions('cr');
$displaySumSql = implode(",\n", array_map(
    static fn($key, $condition) => "SUM(CASE WHEN {$condition['sql']} THEN 1 ELSE 0 END) AS `{$key}`",
    array_keys($displayConditions),
    $displayConditions
));
$displaySummary = array_map('intval', $pdo->query("
    SELECT {$displaySumSql}, SUM(cr.status <> 'Draft') AS total
    FROM change_requests cr
")->fetch() ?: []);
$displayIcons = [
    'draft' => 'bi-pencil', 'review' => 'bi-hourglass-split', 'diproses' => 'bi-gear',
    'approval' => 'bi-person-check', 'disetujui' => 'bi-check2-square', 'revisi' => 'bi-arrow-counterclockwise',
    'selesai' => 'bi-check-circle-fill', 'dibatalkan' => 'bi-slash-circle-fill',
];
if (
    (array_key_exists('report_date_from', $_GET) && !is_string($_GET['report_date_from']))
    || (array_key_exists('report_date_to', $_GET) && !is_string($_GET['report_date_to']))
) {
    http_response_code(400);
    exit('Rentang tanggal tidak valid.');
}
$reportDateFromInput = is_string($_GET['report_date_from'] ?? null)
    ? $_GET['report_date_from']
    : null;
$reportDateToInput = is_string($_GET['report_date_to'] ?? null)
    ? $_GET['report_date_to']
    : null;
try {
    $reportDateRange = normalizeAdminCrfReportDateRange(
        $reportDateFromInput,
        $reportDateToInput
    );
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    exit(h($exception->getMessage()));
}
$report = getAdminCrfReport($pdo, $reportDateRange['from'], $reportDateRange['to']);
$reportExportQuery = http_build_query([
    'report_date_from' => $reportDateRange['from'],
    'report_date_to' => $reportDateRange['to'],
]);
$maxMonthlyCount = max(array_column($report['months'], 'count')) ?: 1;
$donutStops = [];
$donutOffset = 0;
foreach ($report['statuses'] as $reportStatus) {
    if ($reportStatus['percentage'] <= 0) {
        continue;
    }

    $donutEnd = $donutOffset + ($reportStatus['percentage'] * 3.6);
    $donutStops[] = $reportStatus['color'] . ' ' . $donutOffset . 'deg ' . $donutEnd . 'deg';
    $donutOffset = $donutEnd;
}
$statusDonutStyle = $donutStops
    ? 'background: conic-gradient(' . implode(', ', $donutStops) . ');'
    : 'background: #e2e8f0;';


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

    .admin-report {
        margin: 0 0 1rem;
        padding: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
    }

    .admin-report-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.9rem;
    }

    .admin-report-heading h2 {
        margin: 0;
        color: #334155;
        font-size: 1rem;
        font-weight: 700;
    }

    .admin-report-heading p {
        margin: 0.15rem 0 0;
        color: #64748b;
        font-size: 0.78rem;
    }

    .admin-report-date-filter {
        display: flex;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-bottom: 0.85rem;
        padding: 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
    }

    .admin-report-date-filter label {
        display: grid;
        gap: 0.25rem;
        color: #475569;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .admin-report-date-filter input {
        min-height: 34px;
        padding: 0.35rem 0.5rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        color: #334155;
        font: inherit;
    }

    .admin-report-date-filter .btn {
        min-height: 34px;
    }

    .admin-report-exports {
        display: flex;
        gap: 0.45rem;
        flex: 0 0 auto;
    }

    .admin-report-exports .btn {
        white-space: nowrap;
    }

    .admin-report-grid {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(245px, 1fr);
        gap: 0.8rem;
    }

    .admin-report-card {
        min-width: 0;
        padding: 0.85rem;
        border: 1px solid #e2e8f0;
        border-radius: 9px;
    }

    .admin-report-card h3 {
        margin: 0 0 0.8rem;
        color: #334155;
        font-size: 0.85rem;
        font-weight: 700;
    }

    .admin-report-chart {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(32px, 1fr));
        gap: 0.4rem;
        min-height: 150px;
        align-items: end;
    }

    .admin-report-month {
        display: flex;
        min-width: 0;
        height: 100%;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        gap: 0.2rem;
        color: #64748b;
        font-size: 0.68rem;
    }

    .admin-report-month-count {
        color: #475569;
        font-size: 0.65rem;
        font-weight: 600;
    }

    .admin-report-bar-track {
        display: flex;
        width: min(100%, 22px);
        height: 105px;
        align-items: flex-end;
        overflow: hidden;
        border-radius: 4px 4px 0 0;
        background: #f1f5f9;
    }

    .admin-report-bar {
        display: block;
        width: 100%;
        min-height: 2px;
        border-radius: 4px 4px 0 0;
        background: #2563eb;
    }

    .admin-report-bar.is-empty {
        display: none;
    }

    .admin-report-distribution {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        min-height: 150px;
    }

    .admin-report-donut {
        display: grid;
        width: 112px;
        height: 112px;
        flex: 0 0 112px;
        place-items: center;
        border-radius: 50%;
    }

    .admin-report-donut-hole {
        display: grid;
        width: 72px;
        height: 72px;
        align-content: center;
        border-radius: 50%;
        background: #fff;
        text-align: center;
    }

    .admin-report-donut-hole strong {
        color: #334155;
        font-size: 1rem;
        line-height: 1.2;
    }

    .admin-report-donut-hole span {
        color: #64748b;
        font-size: 0.62rem;
    }

    .admin-report-legend {
        display: grid;
        gap: 0.4rem;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .admin-report-legend li {
        display: grid;
        grid-template-columns: 9px minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.4rem;
        color: #475569;
        font-size: 0.68rem;
    }

    .admin-report-legend-swatch {
        width: 9px;
        height: 9px;
        border-radius: 2px;
    }

    .admin-report-legend strong {
        color: #334155;
        white-space: nowrap;
    }

    @media (max-width: 900px) {
        .dashboard-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .admin-report-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .dashboard-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .admin-report {
            padding: 0.75rem;
        }

        .admin-report-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .admin-report-exports {
            width: 100%;
        }

        .admin-report-exports .btn {
            flex: 1 1 50%;
        }

        .admin-report-distribution {
            flex-wrap: wrap;
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

            <a class="crf-stat-card text-decoration-none <?= $listFilters['display_status'] === '' ? 'is-active' : '' ?>" href="dashboard.php#crf-table">
                <span><i class="bi bi-inboxes-fill"></i> Total CRF</span>
                <strong><?= (int) ($displaySummary['total'] ?? 0) ?></strong>
            </a>

            <?php foreach ($displayConditions as $displayKey => $displayCondition): ?>
                <a
                    class="crf-stat-card text-decoration-none <?= $listFilters['display_status'] === $displayKey ? 'is-active' : '' ?>"
                    href="?display_status=<?= h($displayKey) ?>#crf-table"
                >
                    <span><i class="bi <?= h($displayIcons[$displayKey] ?? 'bi-circle') ?>"></i> <?= h($displayCondition['label']) ?></span>
                    <strong><?= (int) ($displaySummary[$displayKey] ?? 0) ?></strong>
                </a>
            <?php endforeach; ?>

            <a class="crf-stat-card crf-forum-stat text-decoration-none" href="../forum/index.php">
                <span><i class="bi bi-chat-square-text"></i> Komentar Baru di Forum</span>
                <strong><?= $forumUnread ?></strong>
            </a>

        </div>

        <section class="admin-report" aria-labelledby="admin-report-title">
            <div class="admin-report-heading">
                <div>
                    <h2 id="admin-report-title">Laporan &amp; Statistik Change Request</h2>
                    <p>Analisis pengajuan CRF <?= h(date('d-m-Y', strtotime($report['date_from']))) ?> sampai <?= h(date('d-m-Y', strtotime($report['date_to']))) ?>.</p>
                </div>
                <div class="admin-report-exports">
                    <a class="btn btn-sm btn-outline-danger" href="../actions/export_admin_report.php?format=pdf&amp;<?= h($reportExportQuery) ?>">
                        <i class="bi bi-file-earmark-pdf"></i> Ekspor PDF
                    </a>
                    <a class="btn btn-sm btn-outline-success" href="../actions/export_admin_report.php?format=xlsx&amp;<?= h($reportExportQuery) ?>">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Ekspor Excel (.xlsx)
                    </a>
                </div>
            </div>

            <form class="admin-report-date-filter" method="get" action="dashboard.php">
                <?php foreach ([
                    'q' => $search,
                    'status' => $statusFilter,
                    'category_id' => $listFilters['category_id'] ?: '',
                    'handler_id' => $listFilters['handler_id'] ?: '',
                    'requester' => $listFilters['requester'],
                    'display_status' => $listFilters['display_status'],
                    'department' => $departmentFilter,
                    'level' => $levelFilter,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                    'page' => $page,
                ] as $filterName => $filterValue): ?>
                    <input type="hidden" name="<?= h($filterName) ?>" value="<?= h((string) $filterValue) ?>">
                <?php endforeach; ?>
                <label>
                    Tanggal Awal
                    <input type="date" name="report_date_from" value="<?= h($report['date_from']) ?>" required>
                </label>
                <label>
                    Tanggal Akhir
                    <input type="date" name="report_date_to" value="<?= h($report['date_to']) ?>" required>
                </label>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel"></i> Terapkan
                </button>
                <a class="btn btn-sm btn-outline-secondary" href="dashboard.php">Reset</a>
            </form>

            <div class="admin-report-grid">
                <section class="admin-report-card" aria-labelledby="monthly-trend-title">
                    <h3 id="monthly-trend-title">Tren CRF per Bulan</h3>
                    <div class="admin-report-chart" role="img" aria-label="Grafik jumlah CRF per bulan pada rentang tanggal yang dipilih">
                        <?php foreach ($report['months'] as $month): ?>
                            <?php $barHeight = $month['count'] > 0 ? max(4, ($month['count'] / $maxMonthlyCount) * 100) : 0; ?>
                            <div class="admin-report-month" title="<?= h($month['label'] . ': ' . $month['count'] . ' CRF') ?>">
                                <span class="admin-report-month-count"><?= (int) $month['count'] ?></span>
                                <span class="admin-report-bar-track">
                                    <span class="admin-report-bar <?= $month['count'] === 0 ? 'is-empty' : '' ?>" style="height: <?= h((string) $barHeight) ?>%;"></span>
                                </span>
                                <span><?= h($month['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="admin-report-card" aria-labelledby="status-distribution-title">
                    <h3 id="status-distribution-title">Status Distribusi CRF</h3>
                    <div class="admin-report-distribution">
                        <div class="admin-report-donut" style="<?= h($statusDonutStyle) ?>" role="img" aria-label="Distribusi status dari <?= (int) $report['total'] ?> CRF">
                            <div class="admin-report-donut-hole">
                                <strong><?= (int) $report['total'] ?></strong>
                                <span>Total CRF</span>
                            </div>
                        </div>
                        <ul class="admin-report-legend">
                            <?php foreach ($report['statuses'] as $reportStatus): ?>
                                <li>
                                    <span class="admin-report-legend-swatch" style="background: <?= h($reportStatus['color']) ?>;"></span>
                                    <span><?= h($reportStatus['label']) ?></span>
                                    <strong><?= (int) $reportStatus['count'] ?> (<?= number_format($reportStatus['percentage'], 1, ',', '.') ?>%)</strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            </div>
        </section>

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
            $listContextName = $listFilters['display_status'] !== '' ? 'display_status' : '';
            $listContextValue = $listFilters['display_status'];
            $listResetUrl = 'dashboard.php';
            require __DIR__ . '/../includes/partials/crf_list_filters.php';
            unset($listContextName, $listContextValue, $listResetUrl);
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
                                            <span class="crf-request-category-chip"><?= h(crfCategoryName($row, 'Lainnya')) ?></span>
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
