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
require_once __DIR__ . '/../includes/forum_discussions.php';

requireAdmin();

$pdo = getConnection();

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
    'display_status' => $_GET['display_status'] ?? '',
]);
$search = $listFilters['search'];
$statusFilter = $listFilters['status'];
$departmentFilter = $listFilters['department'];
$levelFilter = $listFilters['level'];
$dateFrom = $listFilters['date_from'];
$dateTo = $listFilters['date_to'];

/*
 * Rentang tanggal laporan = satu filter periode untuk kartu ringkasan,
 * grafik, daftar pengajuan, dan ekspor. Tanggal pengajuan memakai
 * submission_date (cadangan: tanggal dibuat), sama dengan laporan.
 * Tanpa parameter tanggal, kartu dan daftar menampilkan semua data.
 */
$reportRangeError = '';
$reportFilterActive = false;
$reportDateFromInput = is_string($_GET['report_date_from'] ?? null) ? trim($_GET['report_date_from']) : null;
$reportDateToInput = is_string($_GET['report_date_to'] ?? null) ? trim($_GET['report_date_to']) : null;
$reportDateFromInput = $reportDateFromInput === '' ? null : $reportDateFromInput;
$reportDateToInput = $reportDateToInput === '' ? null : $reportDateToInput;
try {
    $reportDateRange = normalizeAdminCrfReportDateRange($reportDateFromInput, $reportDateToInput);
    $reportFilterActive = $reportDateFromInput !== null || $reportDateToInput !== null;
} catch (InvalidArgumentException $exception) {
    $reportRangeError = 'Rentang tanggal tidak valid. Isi kedua tanggal dan pastikan Tanggal Awal tidak melewati Tanggal Akhir. Menampilkan periode bawaan.';
    $reportDateRange = normalizeAdminCrfReportDateRange(null, null);
}
$reportCarry = $reportFilterActive
    ? ['report_date_from' => $reportDateRange['from'], 'report_date_to' => $reportDateRange['to']]
    : [];
$submittedDateSql = 'COALESCE(cr.submission_date, DATE(cr.created_at))';
$summaryDateSql = '';
$summaryDateParams = [];
if ($reportFilterActive) {
    $where[] = "{$submittedDateSql} >= :report_from";
    $where[] = "{$submittedDateSql} <= :report_to";
    $params['report_from'] = $reportDateRange['from'];
    $params['report_to'] = $reportDateRange['to'];
    $summaryDateSql = "WHERE {$submittedDateSql} >= :report_from AND {$submittedDateSql} <= :report_to";
    $summaryDateParams = ['report_from' => $reportDateRange['from'], 'report_to' => $reportDateRange['to']];
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
$displaySummaryStmt = $pdo->prepare("
    SELECT {$displaySumSql}, SUM(cr.status <> 'Draft') AS total
    FROM change_requests cr
    {$summaryDateSql}
");
$displaySummaryStmt->execute($summaryDateParams);
$displaySummary = array_map('intval', $displaySummaryStmt->fetch() ?: []);
$displayIcons = [
    'draft' => 'bi-pencil', 'review' => 'bi-hourglass-split', 'pembahasan' => 'bi-flag-fill',
    'approval' => 'bi-person-check', 'disetujui' => 'bi-check2-square', 'revisi' => 'bi-arrow-counterclockwise',
    'selesai' => 'bi-check-circle-fill', 'dibatalkan' => 'bi-slash-circle-fill',
];
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

$urgencyStops = [];
$urgencyOffset = 0;
foreach ($report['urgencies'] as $urgencyItem) {
    if ($urgencyItem['percentage'] <= 0) {
        continue;
    }

    $urgencyEnd = $urgencyOffset + ($urgencyItem['percentage'] * 3.6);
    $urgencyStops[] = $urgencyItem['color'] . ' ' . $urgencyOffset . 'deg ' . $urgencyEnd . 'deg';
    $urgencyOffset = $urgencyEnd;
}
$urgencyDonutStyle = $urgencyStops
    ? 'background: conic-gradient(' . implode(', ', $urgencyStops) . ');'
    : 'background: #e2e8f0;';


$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Dashboard Admin';

require_once __DIR__ . '/../includes/header.php';
?>




<div class="crf-page crf-helpdesk-page">

    <div class="container">

        <div class="crf-helpdesk-banner">
            <div>
                <h1>Dashboard CRF</h1>
                <p>Ringkasan seluruh pengajuan Change Request.</p>
            </div>
        </div>


        <!-- =====================================================
             SUMMARY
             ===================================================== -->

        <div class="crf-stat-grid crf-helpdesk-summary dashboard-summary-grid">

            <a class="crf-stat-card text-decoration-none <?= $listFilters['display_status'] === '' ? 'is-active' : '' ?>" href="<?= h('?' . http_build_query($reportCarry)) ?>#crf-table">
                <span><i class="bi bi-inboxes-fill"></i> Total CRF</span>
                <strong><?= (int) ($displaySummary['total'] ?? 0) ?></strong>
            </a>

            <?php foreach ($displayConditions as $displayKey => $displayCondition): ?>
                <?php if (in_array($displayKey, ['draft', 'revisi', 'dibatalkan'], true)) { continue; } // Disembunyikan dari ringkasan; tetap bisa difilter di tabel. ?>
                <a
                    class="crf-stat-card crf-stat-tone-<?= h($displayKey) ?> text-decoration-none <?= $listFilters['display_status'] === $displayKey ? 'is-active' : '' ?>"
                    href="<?= h('?' . http_build_query(['display_status' => $displayKey] + $reportCarry)) ?>#crf-table"
                >
                    <span><i class="bi <?= h($displayIcons[$displayKey] ?? 'bi-circle') ?>"></i> <?= h($displayCondition['label']) ?></span>
                    <strong><?= (int) ($displaySummary[$displayKey] ?? 0) ?></strong>
                </a>
            <?php endforeach; ?>

        </div>

        <section class="admin-report" aria-labelledby="admin-report-title">
            <div class="admin-report-heading">
                <div>
                    <h2 id="admin-report-title">Laporan &amp; Statistik Change Request</h2>
                    <p>Analisis pengajuan CRF <?= h(date('d-m-Y', strtotime($report['date_from']))) ?> sampai <?= h(date('d-m-Y', strtotime($report['date_to']))) ?>.<?php if ($reportFilterActive): ?> Kartu ringkasan, grafik, daftar pengajuan, dan ekspor mengikuti periode ini.<?php endif; ?></p>
                </div>
                <div class="admin-report-exports">
                    <?php /* Tombol ekspor mengirim isi kolom tanggal saat ini (form=adminReportFilter), bukan rentang saat halaman dimuat. */ ?>
                    <button type="submit" form="adminReportFilter" formaction="../actions/export_admin_report.php" formmethod="get" name="format" value="pdf" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-file-earmark-pdf"></i> Ekspor PDF
                    </button>
                    <button type="submit" form="adminReportFilter" formaction="../actions/export_admin_report.php" formmethod="get" name="format" value="xlsx" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Ekspor Excel (.xlsx)
                    </button>
                </div>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function () {
                var form = document.getElementById('adminReportFilter');
                var from = document.getElementById('reportDateFrom');
                var to = document.getElementById('reportDateTo');
                if (!form || !from || !to) { return; }

                var initial = from.value + '|' + to.value;
                var timer = null;
                var isFullDate = function (value) { return /^(19|20)\d{2}-\d{2}-\d{2}$/.test(value); };

                // Periode langsung diterapkan saat tanggal dipilih (tombol Terapkan tetap bisa dipakai).
                function schedule(changed) {
                    if (!isFullDate(from.value) || !isFullDate(to.value)) { return; }
                    // Bila terbalik, tanggal lainnya ikut disamakan agar rentang selalu valid.
                    if (from.value > to.value) {
                        if (changed === from) { to.value = from.value; } else { from.value = to.value; }
                    }
                    if (from.value + '|' + to.value === initial) { return; }
                    clearTimeout(timer);
                    timer = setTimeout(function () { form.requestSubmit(); }, 600);
                }

                from.addEventListener('change', function () { schedule(from); });
                to.addEventListener('change', function () { schedule(to); });
            });
            </script>
            <?php if ($reportRangeError !== ''): ?>
                <div class="alert alert-warning py-2 mb-2" role="alert"><?= h($reportRangeError) ?></div>
            <?php endif; ?>

            <form id="adminReportFilter" class="admin-report-date-filter" method="get" action="dashboard.php">
                <?php foreach ([
                    'q' => $search,
                    'status' => $statusFilter,
                    'category_id' => $listFilters['category_id'] ?: '',
                    'display_status' => $listFilters['display_status'],
                    'department' => $departmentFilter,
                    'level' => $levelFilter,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'per_page' => $perPage,
                ] as $filterName => $filterValue): ?>
                    <input type="hidden" name="<?= h($filterName) ?>" value="<?= h((string) $filterValue) ?>">
                <?php endforeach; ?>
                <label>
                    Tanggal Awal
                    <input type="date" id="reportDateFrom" name="report_date_from" value="<?= h($report['date_from']) ?>" required>
                </label>
                <label>
                    Tanggal Akhir
                    <input type="date" id="reportDateTo" name="report_date_to" value="<?= h($report['date_to']) ?>" required>
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
                                <?php
                                // Label 2 baris yang seragam (Jan / 2026) agar alas batang sejajar.
                                $monthParts = explode(' ', (string) $month['label']);
                                $monthYear = count($monthParts) > 1 ? array_pop($monthParts) : '';
                                ?>
                                <span class="admin-report-month-label">
                                    <span><?= h(mb_substr(implode(' ', $monthParts), 0, 3)) ?></span>
                                    <small><?= h($monthYear) ?></small>
                                </span>
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

                <section class="admin-report-card" aria-labelledby="urgency-percentage-title">
                    <h3 id="urgency-percentage-title">Persentase Tingkat Urgensi</h3>
                    <div class="admin-report-distribution">
                        <div class="admin-report-donut" style="<?= h($urgencyDonutStyle) ?>" role="img" aria-label="Persentase tingkat urgensi dari <?= (int) $report['total'] ?> CRF">
                            <div class="admin-report-donut-hole">
                                <strong><?= (int) $report['total'] ?></strong>
                                <span>Total CRF</span>
                            </div>
                        </div>
                        <ul class="admin-report-legend">
                            <?php foreach ($report['urgencies'] as $urgencyItem): ?>
                                <li>
                                    <span class="admin-report-legend-swatch" style="background: <?= h($urgencyItem['color']) ?>;"></span>
                                    <span><?= h($urgencyItem['label']) ?></span>
                                    <strong><?= (int) $urgencyItem['count'] ?> (<?= number_format($urgencyItem['percentage'], 1, ',', '.') ?>%)</strong>
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
                <?php if ($reportFilterActive): ?>
                    <p class="mb-0 text-muted small">
                        Periode pengajuan <strong><?= h(date('d-m-Y', strtotime($reportDateRange['from']))) ?></strong> s.d. <strong><?= h(date('d-m-Y', strtotime($reportDateRange['to']))) ?></strong>
                        · <?= number_format($totalRows, 0, ',', '.') ?> CRF
                        · <a href="dashboard.php">Hapus filter periode</a>
                    </p>
                <?php endif; ?>

            </div>


            <!-- FILTER -->
            <?php
            $listContextName = $listFilters['display_status'] !== '' ? 'display_status' : '';
            $listContextValue = $listFilters['display_status'];
            $listResetUrl = 'dashboard.php';
            $listCarryParams = $reportCarry;
            require __DIR__ . '/../includes/partials/crf_list_filters.php';
            unset($listContextName, $listContextValue, $listResetUrl, $listCarryParams);
            ?>


            <!-- TABLE -->
            <div class="table-responsive crf-table-responsive-cards crf-helpdesk-table-wrap">

                <table class="table crf-table crf-helpdesk-table crf-helpdesk-table--admin align-middle">

                    <thead>

                        <tr>

                            <th>No</th>
                            <th>Pengajuan</th>
                            <th>Isi Pengajuan</th>
                            <th>Urgensi</th>
                            <th>Status</th>
                            <th>Aksi</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (!$requests): ?>

                            <tr>

                                <td data-label="Pengajuan" colspan="6" class="crf-empty-cell">
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

                                    <?php require __DIR__ . '/../includes/partials/crf_row_request.php'; ?>
                                    <?php require __DIR__ . '/../includes/partials/crf_row_status.php'; ?>

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

                                            <?php if (($row['status'] ?? '') !== 'Draft'): ?><a href="../actions/export_crf.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-crf-outline" target="_blank" rel="noopener" title="Cetak PDF"><i class="bi bi-printer"></i> Cetak</a><?php endif; ?>

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
