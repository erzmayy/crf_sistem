<?php
/**
 * helpdesk/kategori.php?id=
 * Daftar isi Helpdesk untuk satu kategori (PIC kategori / Admin).
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireHelpdeskPic();

$pdo = getConnection();
$scope = helpdeskCategoryScopeForUser($pdo);
$categoryId = (int) ($_GET['id'] ?? 0);
$category = findHelpdeskCategory($pdo, $categoryId);

if (!$category || ($scope !== null && !in_array($categoryId, $scope, true))) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Kategori tidak ditemukan atau bukan tanggung jawab Anda.'];
    header('Location: handling.php');
    exit;
}

$filters = [
    'category_id' => $categoryId,
    'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
    'search' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
];

$perPage = getCrfPageSize($_GET['per_page'] ?? 10);
$result = listHelpdeskTickets($pdo, $filters, $scope, $perPage, (int) ($_GET['page'] ?? 1));
$openCount = helpdeskTicketSummary($pdo, ['category_id' => $categoryId, 'open_only' => 1], $scope)['total'] ?? 0;

$tickets = $result['rows'];
$page = $result['page'];
$totalPages = $result['total_pages'];
$totalRows = $result['total'];
$offset = $result['offset'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Helpdesk · ' . $category['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">DAFTAR ISI HELPDESK KATEGORI</span>
                <h1><i class="bi <?= h($category['icon']) ?>"></i> <?= h($category['name']) ?></h1>
                <p><?= h($category['description'] ?? '') ?></p>
            </div>
            <div class="crf-banner-actions">
                <span class="crf-banner-pill"><i class="bi bi-inbox"></i> <?= (int) $openCount ?> belum selesai</span>
                <a href="handling.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> Kembali ke Dashboard</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($category['requires_crf']): ?>
            <div class="alert alert-info crf-alert">
                <i class="bi bi-info-circle"></i>
                Jenis <strong>Request/Permintaan</strong> pada kategori ini diajukan langsung lewat <strong>Form CRF</strong>
                (dipantau di Dashboard CRF / tab CRF Dashboard Handling). Daftar di bawah berisi
                <strong>Maintenance</strong> dan <strong>Komplain</strong> yang ditindaklanjuti PIC.
            </div>
        <?php endif; ?>

        <div class="crf-table-card crf-list-table-card">
            <form method="GET" class="crf-list-filters">
                <input type="hidden" name="id" value="<?= $categoryId ?>">
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                <label class="crf-list-filter-field crf-list-search">
                    <span>Cari</span>
                    <input type="search" name="q" class="form-control" placeholder="Nama / isi permintaan" value="<?= h($filters['search']) ?>">
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
                <div class="crf-list-filter-actions">
                    <button type="submit" class="btn btn-crf-primary"><i class="bi bi-search"></i> <span>Terapkan</span></button>
                    <a href="kategori.php?id=<?= $categoryId ?>" class="btn btn-crf-outline">Reset</a>
                </div>
            </form>

            <?php
            $ticketOffset = $offset;
            $ticketEmptyText = 'Belum ada isi helpdesk pada kategori ini.';
            require __DIR__ . '/../includes/partials/helpdesk_ticket_table.php';

            $paginationLabel = 'Navigasi halaman ticket';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
