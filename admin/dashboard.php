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
    'type' => $_GET['type'] ?? '',
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
 * tgl_pengajuan (cadangan: tanggal dibuat), sama dengan laporan.
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
$submittedDateSql = 'COALESCE(cr.tgl_pengajuan, DATE(cr.tgl_dibuat))';
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
    FROM crf_pengajuan cr
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
    SELECT cr.*, cc.nama AS crf_category_name
    FROM crf_pengajuan cr
    LEFT JOIN crf_kategori cc ON cc.id = cr.kategori_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY cr.tgl_dibuat DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$requests = $stmt->fetchAll();


/* =========================================================
 * SUMMARY
 * ========================================================= */

// Ringkasan per status tampilan alur CRF (termasuk Draft).
$displayConditions = crfDisplayStatusConditions('cr');
$displaySumSql = implode(",\n", array_map(
    static fn($key, $condition) => "SUM(CASE WHEN {$condition['sql']} THEN 1 ELSE 0 END) AS `{$key}`",
    array_keys($displayConditions),
    $displayConditions
));
$displaySummaryStmt = $pdo->prepare("
    SELECT {$displaySumSql}, SUM(cr.status <> 'Draft') AS total
    FROM crf_pengajuan cr
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
$slaCompliance = getAdminCrfSlaCompliance($pdo, $reportDateRange['from'], $reportDateRange['to']);
// Perlu perhatian = CRF yang masih berjalan dan sudah melewati target Response atau Resolution.
$attentionCount = 0;
foreach ($slaCompliance['violations'] as $attentionRow) {
    if (($attentionRow['response']['state'] ?? '') === 'open_late' || ($attentionRow['resolution']['state'] ?? '') === 'open_late') {
        $attentionCount++;
    }
}
unset($attentionRow);

// Sel persentase kepatuhan: pil berwarna + keterangan kecil (n/diukur, rata-rata).
$slaPercentLabel = static fn(?float $percent): string => $percent === null ? '-' : number_format($percent, 0, ',', '.') . '%';
$slaPill = static function (array $group) use ($slaPercentLabel): string {
    if ($group['percent'] === null) {
        return '<span class="admin-sla-empty">-</span>';
    }
    $average = $group['average'] !== null ? ' · rata-rata ' . formatSlaDurationShort($group['average']) : '';

    return '<span class="admin-sla-pill is-' . h($group['tone']) . '">' . h($slaPercentLabel($group['percent'])) . '</span>'
        . '<small>' . (int) $group['met'] . '/' . (int) $group['measured'] . h($average) . '</small>';
};
// Status satu CRF pada daftar pelanggaran.
$slaStateBadge = static function (array $result): string {
    switch ($result['state']) {
        case 'late':
            return '<span class="admin-sla-state is-danger">Lewat ' . h(formatSlaDurationShort($result['over'])) . '</span>';
        case 'open_late':
            return '<span class="admin-sla-state is-danger">Terlewati ' . h(formatSlaDurationShort($result['over'])) . '</span><small>masih berjalan</small>';
        case 'met':
            return '<span class="admin-sla-state is-ok">Sesuai</span>';
        case 'open':
            return '<span class="admin-sla-state is-muted">Berjalan</span>';
        default:
            return '<span class="admin-sla-empty">-</span>';
    }
};
$reportExportQuery = http_build_query([
    'report_date_from' => $reportDateRange['from'],
    'report_date_to' => $reportDateRange['to'],
]);
$maxMonthlyCount = max(array_column($report['months'], 'count')) ?: 1;
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

$pageTitle = 'Dashboard CRF';

require_once __DIR__ . '/../includes/header.php';
?>




<div class="crf-page crf-portal-page">

    <div class="container">

        <div class="crf-portal-banner">
            <div>
                <h1>Dashboard CRF</h1>
                <p>Periode <?= h(date('d-m-Y', strtotime($report['date_from']))) ?> sampai <?= h(date('d-m-Y', strtotime($report['date_to']))) ?>.
                    <?php if ($reportFilterActive): ?>Kartu, grafik, daftar, dan ekspor mengikuti periode ini.<?php else: ?>Kartu dan daftar menampilkan semua data; grafik dan ekspor memakai periode ini.<?php endif; ?></p>
            </div>
        </div>

        <?php if ($reportRangeError !== ''): ?>
            <div class="alert alert-warning py-2 mb-2" role="alert"><?= h($reportRangeError) ?></div>
        <?php endif; ?>

        <div class="admin-dash-bar">
            <form id="adminReportFilter" class="admin-report-date-filter" method="get"><?= crfRouteFields() ?>
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
                <a class="btn btn-sm btn-outline-secondary" href="<?= h(crfSelfUrl()) ?>">Reset</a>
            </form>
            <div class="admin-report-exports">
                <?php /* Tombol ekspor mengirim isi kolom tanggal saat ini (form=adminReportFilter), bukan rentang saat halaman dimuat. */ ?>
                <button type="submit" form="adminReportFilter" formaction="<?= h(crfActionUrl('export_admin_report.php')) ?>" formmethod="get" name="format" value="pdf" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-file-earmark-pdf"></i> Ekspor PDF
                </button>
                <button type="submit" form="adminReportFilter" formaction="<?= h(crfActionUrl('export_admin_report.php')) ?>" formmethod="get" name="format" value="xlsx" class="btn btn-sm btn-outline-success">
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


        <!-- =====================================================
             SUMMARY
             ===================================================== -->

        <div class="crf-stat-grid crf-portal-summary dashboard-summary-grid">

            <a class="crf-stat-card text-decoration-none <?= ($listFilters['display_status'] === '' && $listFilters['status'] === '') ? 'is-active' : '' ?>" href="<?= h(crfSelfUrl($reportCarry)) ?>#crf-table">
                <span><i class="bi bi-inboxes-fill"></i> Total CRF</span>
                <strong><?= (int) ($displaySummary['total'] ?? 0) ?></strong>
            </a>

            <?php foreach ([
                ['key' => 'menunggu', 'label' => 'Menunggu persetujuan', 'icon' => 'bi-hourglass-split', 'count' => (int) ($displaySummary['menunggu'] ?? 0), 'href' => ['display_status' => 'menunggu'], 'active' => $listFilters['display_status'] === 'menunggu', 'title' => 'Verifikasi CMO, pembahasan Forum, dan persetujuan Kepala Departemen Operasional'],
                ['key' => 'disetujui', 'label' => 'Sudah disetujui', 'icon' => 'bi-gear-wide-connected', 'count' => (int) ($displaySummary['disetujui'] ?? 0), 'href' => ['display_status' => 'disetujui'], 'active' => $listFilters['display_status'] === 'disetujui', 'title' => 'Sudah disetujui dan belum selesai (pengerjaan, UAT, PIR, finalisasi)'],
                ['key' => 'selesai', 'label' => 'Selesai', 'icon' => 'bi-check-circle-fill', 'count' => (int) ($displaySummary['selesai'] ?? 0), 'href' => ['status' => 'Solve'], 'active' => $listFilters['status'] === 'Solve', 'title' => ''],
            ] as $summaryCard): ?>
                <a
                    class="crf-stat-card crf-stat-tone-<?= h($summaryCard['key']) ?> text-decoration-none <?= $summaryCard['active'] ? 'is-active' : '' ?>"
                    href="<?= h(crfSelfUrl($summaryCard['href'] + $reportCarry)) ?>#crf-table"
                    <?= $summaryCard['title'] !== '' ? 'title="' . h($summaryCard['title']) . '"' : '' ?>
                >
                    <span><i class="bi <?= h($summaryCard['icon']) ?>"></i> <?= h($summaryCard['label']) ?></span>
                    <strong><?= $summaryCard['count'] ?></strong>
                </a>
            <?php endforeach; ?>

            <a class="crf-stat-card crf-stat-tone-perhatian text-decoration-none <?= $attentionCount > 0 ? 'is-attention' : '' ?>" href="#adminSlaViolations" data-open-sla="1" title="CRF yang masih berjalan dan sudah melewati target Response atau Resolution">
                <span><i class="bi bi-exclamation-triangle-fill"></i> Perlu perhatian</span>
                <strong><?= (int) $attentionCount ?></strong>
            </a>

        </div>

        <section class="admin-report" aria-label="Grafik laporan CRF">

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

                <section class="admin-report-card" aria-labelledby="type-composition-title">
                    <h3 id="type-composition-title">Tipe Pengajuan</h3>
                    <ul class="admin-type-list">
                        <?php foreach ($report['types'] as $typeItem): ?>
                            <?php $typeLinkable = $typeItem['label'] !== 'Belum diisi'; ?>
                            <li>
                                <<?= $typeLinkable ? 'a' : 'div' ?> class="admin-type-item"<?= $typeLinkable ? ' href="' . h(crfSelfUrl($reportCarry + ['type' => $typeItem['label']])) . '#crf-table" title="Tampilkan CRF bertipe ini di daftar"' : '' ?>>
                                    <span class="admin-type-head">
                                        <span><?= h($typeItem['label']) ?></span>
                                        <strong><?= (int) $typeItem['count'] ?> <small>(<?= number_format($typeItem['percentage'], 1, ',', '.') ?>%)</small></strong>
                                    </span>
                                    <span class="admin-type-bar" aria-hidden="true"><span style="width: <?= h(number_format($typeItem['percentage'], 1, '.', '')) ?>%; background: <?= h($typeItem['color']) ?>;"></span></span>
                                </<?= $typeLinkable ? 'a' : 'div' ?>>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>
        </section>

        <!-- =====================================================
             KEPATUHAN SLA (Response Time & Resolution Time)
             ===================================================== -->
        <section class="admin-report admin-sla" id="adminSla" aria-labelledby="admin-sla-title">
            <div class="admin-report-heading">
                <div>
                    <h2 id="admin-sla-title">Kepatuhan SLA</h2>
                    <p>Dalam jam kerja. Response Time = waktu menunggu sebelum ditanggapi (semua peran); Resolution Time = waktu sampai pengerjaan selesai.</p>
                </div>
                <?php if ($slaCompliance['total'] > 0): ?>
                    <div class="admin-sla-actions">
                        <span class="admin-sla-count"><strong><?= (int) $slaCompliance['total'] ?></strong> CRF disetujui</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="admin-sla-body" id="adminSlaBody">
            <?php if ($slaCompliance['total'] === 0): ?>
                <div class="crf-empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Belum ada CRF yang disetujui Kepala Departemen Operasional pada periode ini, jadi belum ada data kepatuhan.</p>
                </div>
            <?php else: ?>
                <?php
                $kpiResponse = $slaCompliance['response'];
                $kpiResolution = $slaCompliance['resolution'];
                $kpiCombined = !empty($kpiResponse['combined']);
                ?>
                <div class="admin-sla-kpis admin-sla-kpis--v2">

                    <!-- RESPONSE TIME: gabungan semua peran + rincian per peran -->
                    <article class="admin-sla-kpi admin-sla-kpi--response">
                        <header>
                            <h3>Response Time<?= $kpiCombined ? ' (semua peran)' : '' ?></h3>
                            <small><?= $kpiCombined ? 'CMO, Kepala Departemen Operasional, dan PIC Otomasi' : 'Sampai PIC menekan Mulai kerjakan' ?></small>
                        </header>
                        <div class="admin-sla-kpi-main">
                            <strong class="is-<?= h($kpiResponse['tone']) ?>"><?= h($slaPercentLabel($kpiResponse['percent'])) ?></strong>
                            <span><?= $kpiResponse['percent'] === null ? 'belum ada hasil' : 'tepat waktu' ?></span>
                        </div>
                        <div class="admin-sla-bar is-<?= h($kpiResponse['tone']) ?>" role="progressbar" aria-label="Response Time tepat waktu" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round((float) $kpiResponse['percent']) ?>">
                            <span style="width: <?= (int) round((float) $kpiResponse['percent']) ?>%"></span>
                        </div>
                        <dl class="admin-sla-kpi-meta">
                            <div><dt>Sudah diukur</dt><dd><?= (int) $kpiResponse['measured'] ?> <?= $kpiCombined ? 'kali' : 'CRF' ?></dd></div>
                            <?php if (!$kpiCombined): ?>
                                <div><dt>Rata-rata</dt><dd><?= $kpiResponse['average'] !== null ? h(formatSlaDurationShort($kpiResponse['average'])) : '-' ?></dd></div>
                            <?php endif; ?>
                            <div class="<?= $kpiResponse['open_late'] > 0 ? 'is-alert' : '' ?>"><dt>Berjalan, terlewati</dt><dd><?= (int) $kpiResponse['open_late'] ?></dd></div>
                        </dl>

</article>

                    <!-- RESOLUTION TIME -->
                    <article class="admin-sla-kpi admin-sla-kpi--resolution">
                        <header>
                            <h3>Resolution Time</h3>
                            <small>Sampai pengerjaan selesai</small>
                        </header>
                        <div class="admin-sla-kpi-main">
                            <strong class="is-<?= h($kpiResolution['tone']) ?>"><?= h($slaPercentLabel($kpiResolution['percent'])) ?></strong>
                            <span><?= $kpiResolution['percent'] === null ? 'belum ada hasil' : 'tepat waktu' ?></span>
                        </div>
                        <div class="admin-sla-bar is-<?= h($kpiResolution['tone']) ?>" role="progressbar" aria-label="Resolution Time tepat waktu" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round((float) $kpiResolution['percent']) ?>">
                            <span style="width: <?= (int) round((float) $kpiResolution['percent']) ?>%"></span>
                        </div>
                        <dl class="admin-sla-kpi-meta">
                            <div><dt>Sudah diukur</dt><dd><?= (int) $kpiResolution['measured'] ?> CRF</dd></div>
                            <div><dt>Rata-rata</dt><dd><?= $kpiResolution['average'] !== null ? h(formatSlaDurationShort($kpiResolution['average'])) : '-' ?></dd></div>
                            <div class="<?= $kpiResolution['open_late'] > 0 ? 'is-alert' : '' ?>"><dt>Berjalan, terlewati</dt><dd><?= (int) $kpiResolution['open_late'] ?></dd></div>
                        </dl>
                    </article>
                </div>

                <details class="admin-sla-details" id="adminSlaDetails">
                    <summary>Rincian per kategori, urgensi, dan CRF yang melewati target <span class="admin-sla-count"><?= count($slaCompliance['violations']) ?></span></summary>
                <div class="admin-sla-tables">
                    <?php foreach ([
                        ['title' => 'Per Kategori', 'rows' => $slaCompliance['by_category']],
                        ['title' => 'Per Level Urgensi', 'rows' => $slaCompliance['by_urgency']],
                    ] as $slaTable): ?>
                        <section class="admin-report-card">
                            <h3><?= h($slaTable['title']) ?></h3>
                            <div class="table-responsive">
                                <table class="admin-sla-table">
                                    <thead>
                                        <tr><th>Nama</th><th class="text-end">CRF</th><th>Response</th><th>Resolution</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($slaTable['rows'] as $slaGroup): ?>
                                            <tr>
                                                <td><?= h($slaGroup['name']) ?></td>
                                                <td class="text-end"><?= (int) $slaGroup['total'] ?></td>
                                                <td><?= $slaPill($slaGroup['response']) ?></td>
                                                <td><?= $slaPill($slaGroup['resolution']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <section class="admin-report-card admin-sla-violations" id="adminSlaViolations">
                    <h3>
                        CRF yang Melewati Target
                        <span class="admin-sla-count"><?= count($slaCompliance['violations']) ?></span>
                    </h3>
                    <?php if (!$slaCompliance['violations']): ?>
                        <p class="admin-sla-clear"><i class="bi bi-check-circle-fill"></i> Tidak ada CRF yang melewati target pada periode ini.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-sla-table">
                                <thead>
                                    <tr><th>No. Register</th><th>Kategori</th><th>Urgensi</th><th>PIC</th><th>Mulai dikerjakan</th><th>Resolution</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($slaCompliance['violations'], 0, 10) as $violation): ?>
                                        <tr>
                                            <td><a href="<?= h(crfUrl('admin.detail', ['id' => (int) $violation['id']])) ?>"><?= h($violation['nomor_register']) ?></a></td>
                                            <td><?= h($violation['kategori']) ?></td>
                                            <td><?= h($violation['urgensi']) ?></td>
                                            <td><?= $violation['pic'] !== '' ? h($violation['pic']) : '<span class="admin-sla-empty">Belum ada</span>' ?></td>
                                            <td><?= $slaStateBadge($violation['response']) ?></td>
                                            <td><?= $slaStateBadge($violation['resolution']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($slaCompliance['violations']) > 10): ?>
                            <p class="admin-sla-more">Menampilkan 10 dari <?= count($slaCompliance['violations']) ?> CRF. Daftar lengkap ada di ekspor Excel (kolom Response Time dan Resolution Time).</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <p class="admin-sla-note">
                    Persentase = tepat waktu dari yang sudah punya hasil. Response dihitung tiap kali CRF menunggu satu peran
                    (satu CRF bisa dihitung beberapa kali, misalnya setelah revisi); Resolution dihitung satu kali per CRF.
                    Warna: hijau 90% ke atas, kuning 75-89%, merah di bawah 75%. Putaran tanpa target (CRF lama yang
                    disetujui sebelum fitur ini) tidak dihitung pada persentase.
                </p>
                </details>
            <?php endif; ?>

            </div>
        </section>
        <script>
        (function () {
            var details = document.getElementById('adminSlaDetails');
            if (!details) { return; }
            function openDetails() { details.open = true; }
            document.querySelectorAll('[data-open-sla]').forEach(function (link) { link.addEventListener('click', openDetails); });
            if (window.location.hash === '#adminSlaViolations') { openDetails(); }
        })();
        </script>

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
                    Daftar CRF
                </h2>
                <?php if ($reportFilterActive): ?>
                    <p class="mb-0 text-muted small">
                        Periode pengajuan <strong><?= h(date('d-m-Y', strtotime($reportDateRange['from']))) ?></strong> s.d. <strong><?= h(date('d-m-Y', strtotime($reportDateRange['to']))) ?></strong>
                        · <?= number_format($totalRows, 0, ',', '.') ?> CRF
                        · <a href="<?= h(crfSelfUrl()) ?>">Hapus filter periode</a>
                    </p>
                <?php endif; ?>

            </div>


            <!-- FILTER -->
            <?php
            $listContextName = '';
            $listContextValue = '';
            $listShowStageFilter = true;
            $listResetUrl = 'dashboard.php';
            $listCarryParams = $reportCarry;
            require __DIR__ . '/../includes/partials/crf_list_filters.php';
            unset($listContextName, $listContextValue, $listResetUrl, $listCarryParams);
            ?>


            <!-- TABLE -->
            <div class="table-responsive crf-table-responsive-cards crf-portal-table-wrap">

                <table class="table crf-table crf-portal-table crf-portal-table--admin align-middle">

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
                                                href="<?= h(crfUrl('admin.detail', ['id' => (int) $row['id']])) ?>"
                                                class="btn btn-sm btn-crf-outline"
                                            >
                                                <i class="bi bi-eye"></i>
                                                Detail
                                            </a>

                                            <?php if (($row['status'] ?? '') !== 'Draft'): ?><a href="<?= h(crfActionUrl('export_crf.php', ['id' => (int) $row['id']])) ?>" class="btn btn-sm btn-crf-outline" target="_blank" rel="noopener" title="Cetak PDF"><i class="bi bi-printer"></i> Cetak</a><?php endif; ?>

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
