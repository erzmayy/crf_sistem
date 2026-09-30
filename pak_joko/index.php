<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['kadep_operasional']);

$pdo = getConnection();

$view = $_GET['view'] ?? 'queue';
if (!in_array($view, ['queue', 'history'], true)) {
    $view = 'queue';
}

$search = $_GET['q'] ?? '';

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

$listFilters = applyCrfRequestFilters($pdo, $where, $params, [
    'search' => $search,
    'status' => $_GET['status'] ?? '',
    'department' => $_GET['department'] ?? '',
    'level' => $_GET['level'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
]);
$search = $listFilters['search'];

/* =========================================================
 * PAGINATION
 * ========================================================= */

$perPage = getCrfPageSize($_GET['per_page'] ?? 6);
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

        <div class="crf-table-card crf-list-table-card">

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
            <?php
            $listContextName = 'view';
            $listContextValue = $view;
            $listResetUrl = 'index.php';
            require __DIR__ . '/../includes/partials/crf_list_filters.php';
            unset($listContextName, $listContextValue, $listResetUrl);
            ?>

            <div class="table-responsive crf-table-responsive-cards">
                <table class="table crf-table align-middle">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nomor Register</th>
                            <th>Pengaju</th>
                            <th>Level Urgensi</th>
                            <th>SLA</th>
                            <th>Tahap Saat Ini</th>
                            <?php if ($view === 'history'): ?>
                                <th>Status</th>
                            <?php endif; ?>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="<?= $view === 'history' ? 8 : 7 ?>" class="text-center text-muted py-4">
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

                                <td data-label="Level Urgensi">
                                    <span class="crf-badge <?= levelBadgeClass($row['level']) ?>">
                                        <?= h(($row['level'] ?? null) === 'Normal' ? 'Sedang' : ($row['level'] ?? 'Belum ditentukan')) ?>
                                    </span>
                                </td>

                                <td data-label="SLA">
                                    <?= $row['sla_value'] !== null && $row['sla_unit']
                                        ? h(rtrim(rtrim(number_format((float) $row['sla_value'], 2, '.', ''), '0'), '.')) . ' ' . h($row['sla_unit'])
                                        : '-' ?>
                                </td>

                                <td data-label="Tahap Saat Ini">
                                    <span class="crf-badge <?= workflowStageBadgeClass($row['workflow_stage']) ?>">
                                        <?= h(workflowStageLabel($row['workflow_stage'])) ?>
                                    </span>
                                </td>

                                <?php if ($view === 'history'): ?>
                                    <td data-label="Status">
                                        <span class="crf-badge <?= statusBadgeClass($row['status']) ?>">
                                            <?= h(statusLabel($row['status'])) ?>
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
            <?php
            $paginationLabel = 'Navigasi halaman Kepala Departemen Operasional';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            unset($paginationLabel);
            ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
