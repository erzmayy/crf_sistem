<?php
/**
 * helpdesk/dashboard.php
 * Dashboard Helpdesk: pemantauan ticket, tindak lanjut & SLA per periode.
 * Admin melihat semua kategori; PIC hanya kategori yang ditanganinya.
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireHelpdeskPic();

$pdo = getConnection();
$scope = helpdeskCategoryScopeForUser($pdo);

$dateFrom = is_string($_GET['date_from'] ?? null) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = is_string($_GET['date_to'] ?? null) ? $_GET['date_to'] : date('Y-m-t');
$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'category_id' => (int) ($_GET['category_id'] ?? 0),
    'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
    'search' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
];

$perPage = getCrfPageSize($_GET['per_page'] ?? 10);
$result = listHelpdeskTickets($pdo, $filters, $scope, $perPage, (int) ($_GET['page'] ?? 1));
$summary = helpdeskTicketSummary($pdo, $filters, $scope);

$categoryOptions = array_values(array_filter(
    helpdeskCategories($pdo, false),
    static fn($category) => $scope === null || in_array((int) $category['id'], $scope, true)
));

$tickets = $result['rows'];
$page = $result['page'];
$totalPages = $result['total_pages'];
$totalRows = $result['total'];
$offset = $result['offset'];
$total = max(1, (int) ($summary['total'] ?? 0));

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Dashboard Helpdesk';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">PT PERSONA PRIMA UTAMA</span>
                <h1>Dashboard Helpdesk</h1>
                <p>Pemantauan permintaan, tindak lanjut &amp; SLA per periode<?= $scope !== null ? ' (kategori yang Anda tangani)' : '' ?>.</p>
            </div>
            <div class="crf-banner-actions">
                <span class="crf-banner-pill"><i class="bi bi-calendar3"></i> <?= h(date('d/m/Y', strtotime($dateFrom))) ?> – <?= h(date('d/m/Y', strtotime($dateTo))) ?></span>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="crf-kpi-grid mb-4">
            <?php
            $kpis = [
                ['label' => 'Total Isi Helpdesk', 'value' => $summary['total'] ?? 0, 'icon' => 'bi-inbox', 'tone' => 'primary'],
                ['label' => 'Selesai', 'value' => $summary['selesai'] ?? 0, 'icon' => 'bi-check-circle', 'tone' => 'success'],
                ['label' => 'Dalam Proses', 'value' => $summary['proses'] ?? 0, 'icon' => 'bi-arrow-repeat', 'tone' => 'info'],
                ['label' => 'Belum Ditindaklanjuti', 'value' => $summary['belum'] ?? 0, 'icon' => 'bi-exclamation-circle', 'tone' => 'danger'],
                ['label' => 'Dibatalkan', 'value' => $summary['batal'] ?? 0, 'icon' => 'bi-slash-circle', 'tone' => 'secondary'],
            ];
            foreach ($kpis as $kpi): ?>
                <div class="crf-kpi-card crf-kpi-card--<?= h($kpi['tone']) ?>">
                    <span class="crf-kpi-icon"><i class="bi <?= h($kpi['icon']) ?>"></i></span>
                    <div>
                        <span class="crf-kpi-label"><?= h($kpi['label']) ?></span>
                        <strong><?= number_format((int) $kpi['value'], 0, ',', '.') ?></strong>
                        <?php if ($kpi['label'] !== 'Total Isi Helpdesk'): ?>
                            <div class="crf-kpi-bar"><span style="width: <?= (int) round(((int) $kpi['value'] / $total) * 100) ?>%"></span></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="crf-table-card crf-list-table-card">
            <form method="GET" class="crf-list-filters crf-list-filters-advanced">
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                <label class="crf-list-filter-field">
                    <span>Tanggal Awal</span>
                    <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                </label>
                <label class="crf-list-filter-field">
                    <span>Tanggal Akhir</span>
                    <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                </label>
                <label class="crf-list-filter-field">
                    <span>Kategori</span>
                    <select name="category_id" class="form-select">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($categoryOptions as $categoryOption): ?>
                            <option value="<?= (int) $categoryOption['id'] ?>" <?= $filters['category_id'] === (int) $categoryOption['id'] ? 'selected' : '' ?>><?= h($categoryOption['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="crf-list-filter-field">
                    <span>Status</span>
                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <?php foreach (HELPDESK_STATUSES as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= $filters['status'] === $statusOption ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="crf-list-filter-field">
                    <span>Cari</span>
                    <input type="search" name="q" class="form-control" placeholder="Nomor / nama / isi" value="<?= h($filters['search']) ?>">
                </label>
                <div class="crf-list-filter-actions">
                    <button type="submit" class="btn btn-crf-primary"><i class="bi bi-search"></i> <span>View</span></button>
                    <a href="dashboard.php" class="btn btn-crf-outline">Reset</a>
                </div>
            </form>

            <?php
            $ticketOffset = $offset;
            $ticketEmptyText = 'Tidak ada ticket pada periode dan filter yang dipilih.';
            require __DIR__ . '/../includes/partials/helpdesk_ticket_table.php';

            $paginationLabel = 'Navigasi halaman ticket';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
