<?php
/**
 * helpdesk/saya.php
 * Daftar ticket Helpdesk milik user + status CRF terkait.
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

$pdo = getConnection();
$userId = (int) $_SESSION['user_id'];

$filters = [
    'user_id' => $userId,
    'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
    'search' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
];

$perPage = getCrfPageSize($_GET['per_page'] ?? 10);
$result = listHelpdeskTickets($pdo, $filters, null, $perPage, (int) ($_GET['page'] ?? 1));
$summary = helpdeskTicketSummary($pdo, $filters, null);

$tickets = $result['rows'];
$page = $result['page'];
$totalPages = $result['total_pages'];
$totalRows = $result['total'];
$offset = $result['offset'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Tiket Saya';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">HELPDESK PPU</span>
                <h1>Tiket Saya</h1>
                <p>Pantau permintaan Helpdesk Anda beserta CRF yang terhubung.</p>
            </div>
            <div class="crf-banner-actions">
                <a href="form.php" class="btn btn-light"><i class="bi bi-plus-circle"></i> Permintaan Baru</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="crf-stat-grid crf-helpdesk-summary mb-4">
            <div class="crf-stat-card"><span><i class="bi bi-inbox"></i> Total Ticket</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong></div>
            <div class="crf-stat-card"><span><i class="bi bi-exclamation-circle"></i> Belum Ditindaklanjuti</span><strong><?= (int) ($summary['belum'] ?? 0) ?></strong></div>
            <div class="crf-stat-card"><span><i class="bi bi-arrow-repeat"></i> Dalam Proses</span><strong><?= (int) ($summary['proses'] ?? 0) ?></strong></div>
            <div class="crf-stat-card"><span><i class="bi bi-check-circle"></i> Selesai</span><strong><?= (int) ($summary['selesai'] ?? 0) ?></strong></div>
        </div>

        <div class="crf-table-card crf-list-table-card">
            <form method="GET" class="crf-list-filters">
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                <label class="crf-list-filter-field crf-list-search">
                    <span>Cari Ticket</span>
                    <input type="search" name="q" class="form-control" placeholder="Nomor ticket atau isi pesan..." value="<?= h($filters['search']) ?>">
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
                    <a href="saya.php" class="btn btn-crf-outline">Reset</a>
                </div>
            </form>

            <?php
            $ticketOffset = $offset;
            $ticketEmptyText = 'Belum ada ticket. Klik "Permintaan Baru" untuk membuat permintaan.';
            $ticketShowRequester = false;
            require __DIR__ . '/../includes/partials/helpdesk_ticket_table.php';

            $paginationLabel = 'Navigasi halaman ticket';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
