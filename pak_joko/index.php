<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['kadep_operasional']);

$pdo = getConnection();

$view = $_GET['view'] ?? 'queue';
if (!in_array($view, ['queue', 'history'], true)) {
    $view = 'queue';
}

$search = trim($_GET['q'] ?? '');

$where = ["cr.status <> 'Draft'"];
$params = [];

if ($view === 'history') {
    $where[] = "EXISTS (
        SELECT 1
        FROM crf_activity_logs activity_log
        WHERE activity_log.change_request_id = cr.id
          AND (
              activity_log.activity = 'Approval Kepala Departemen Operasional'
              OR (
                  activity_log.activity = 'Perlu Revisi'
                  AND activity_log.description LIKE 'Kepala Departemen Operasional mengembalikan CRF ke Otomasi%'
              )
          )
    )";
} else {
    $where[] = "cr.workflow_stage = 'kadep_operasional'";
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
    ORDER BY cr.updated_at " . ($view === 'history' ? 'DESC' : 'ASC') . "
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

/* =========================================================
 * SUMMARY CARD
 * ========================================================= */

$summaryStmt = $pdo->query(" 
    SELECT COUNT(*)
    FROM change_requests
    WHERE workflow_stage = 'kadep_operasional'
      AND status <> 'Draft'
");

$waitingApproval = (int) $summaryStmt->fetchColumn();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Kepala Departemen Operasional';

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
</style>

<div class="crf-page">
    <div class="container">

        <div class="crf-page-header">
            <h1>Kepala Departemen Operasional - Approval</h1>
            <p>Daftar CRF dengan Level Urgensi dan SLA yang menunggu persetujuan sebelum eksekusi Otomasi.</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- =====================================================
             SUMMARY CARD
             ===================================================== -->
        <div class="crf-stat-grid mb-4">
            <div class="crf-stat-card">
                <span>Menunggu Approval</span>
                <strong><?= $waitingApproval ?></strong>
            </div>
        </div>

        <div class="crf-table-card">

            <div class="crf-table-heading">
                <h2><?= $view === 'history' ? 'Riwayat Persetujuan' : 'Menunggu Approval' ?></h2>
                <div class="btn-group">
                    <a
                        href="?view=queue"
                        class="btn btn-sm <?= $view === 'queue' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Antrean
                    </a>
                    <a
                        href="?view=history"
                        class="btn btn-sm <?= $view === 'history' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Riwayat
                    </a>
                </div>
            </div>

            <!-- SEARCH -->
            <form method="GET" class="row g-2 mb-3 queue-filter-row">
                <input type="hidden" name="view" value="<?= h($view) ?>">
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
                    <a href="?view=<?= h($view) ?>" class="btn btn-crf-outline w-100">
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
                            <?php if ($view === 'history'): ?>
                                <th>Status</th>
                                <th>Tahap Saat Ini</th>
                            <?php endif; ?>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="<?= $view === 'history' ? 8 : 6 ?>" class="text-center text-muted py-4">
                                <?= $view === 'history'
                                    ? 'Belum ada CRF yang pernah diproses oleh Kepala Departemen Operasional.'
                                    : ($search !== ''
                                    ? 'Tidak ada CRF yang sesuai dengan pencarian.'
                                    : 'Tidak ada CRF yang menunggu approval.') ?>
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

                                <?php if ($view === 'history'): ?>
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
                                <?php endif; ?>

                                <td data-label="Aksi">
                                    <a
                                        href="detail.php?id=<?= (int) $row['id'] ?>"
                                        class="btn btn-sm btn-crf-primary"
                                    >
                                        <i class="bi bi-<?= $view === 'history' ? 'eye' : 'check2-square' ?>"></i>
                                        <?= $view === 'history' ? 'Detail' : 'Review' ?>
                                    </a>
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
                <nav aria-label="Pagination Kepala Departemen Operasional" class="mt-3">
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
