<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['kadep_operasional']);

$pdo = getConnection();

$search = trim($_GET['q'] ?? '');

$where = [
    "cr.workflow_stage = 'kadep_operasional'",
    "cr.status <> 'Draft'"
];
$params = [];

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
    ORDER BY cr.updated_at ASC
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
                <h2>Menunggu Approval</h2>
            </div>

            <!-- SEARCH -->
            <form method="GET" class="row g-2 mb-3 queue-filter-row">
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
                    <a href="index.php" class="btn btn-crf-outline w-100">
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
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <?= $search !== ''
                                    ? 'Tidak ada CRF yang sesuai dengan pencarian.'
                                    : 'Tidak ada CRF yang menunggu approval.' ?>
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

                                <td data-label="Aksi">
                                    <a
                                        href="detail.php?id=<?= (int) $row['id'] ?>"
                                        class="btn btn-sm btn-crf-primary"
                                    >
                                        <i class="bi bi-check2-square"></i> Review
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
