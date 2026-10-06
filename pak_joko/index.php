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
    'category_id' => $_GET['category_id'] ?? '',
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

<div class="crf-page crf-helpdesk-page">
    <div class="container">

        <div class="crf-helpdesk-banner">
            <div>
                <span class="crf-helpdesk-eyebrow">PORTAL CRF · PERSETUJUAN PERUBAHAN</span>
                <h1>Persetujuan Kepala Departemen Operasional</h1>
                <p>Daftar CRF yang lolos verifikasi CMO dan menunggu persetujuan. Setelah disetujui, CRF masuk antrean Divisi Otomasi.</p>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- =====================================================
             SUMMARY CARD
             ===================================================== -->
        <div class="crf-stat-grid crf-helpdesk-summary mb-4">
            <div class="crf-stat-card">
                <span><i class="bi bi-clipboard-check-fill"></i> Menunggu Persetujuan</span>
                <strong><?= $waitingApproval ?></strong>
            </div>
        </div>

        <div class="crf-table-card crf-list-table-card">

            <div class="crf-table-heading">
                <h2><?= $view === 'history' ? 'Riwayat Persetujuan' : 'Menunggu Persetujuan' ?></h2>
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

            <div class="table-responsive crf-table-responsive-cards crf-helpdesk-table-wrap">
                <table class="table crf-table crf-helpdesk-table crf-helpdesk-table--department align-middle">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Pengajuan</th>
                            <th>Isi Pengajuan</th>
                            <th>Urgensi</th>
                            <th>Status</th>
                            <th>SLA</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td data-label="Pengajuan" colspan="7" class="text-center text-muted py-4">
                                <?= $view === 'history'
                                    ? 'Belum ada CRF yang pernah diproses oleh Kepala Departemen Operasional.'
                                    : ($search !== ''
                                    ? 'Tidak ada CRF yang sesuai dengan pencarian.'
                                    : 'Tidak ada CRF yang menunggu persetujuan.') ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $i => $row): ?>
                            <tr>
                                <td data-label="No"><?= $offset + $i + 1 ?></td>

                                <?php $rowShowDepartment = false; require __DIR__ . '/../includes/partials/crf_row_request.php'; ?>
                                <?php require __DIR__ . '/../includes/partials/crf_row_status.php'; ?>

                                <td data-label="SLA">
                                    <?= $row['sla_value'] !== null && $row['sla_unit']
                                        ? h(rtrim(rtrim(number_format((float) $row['sla_value'], 2, '.', ''), '0'), '.')) . ' ' . h($row['sla_unit'])
                                        : '-' ?>
                                </td>

                                <td data-label="Aksi">
                                    <div class="d-flex gap-2">
                                    <a
                                        href="detail.php?id=<?= (int) $row['id'] ?>"
                                        class="btn btn-sm <?= $view === 'history' ? 'btn-crf-outline' : 'btn-crf-primary' ?>"
                                    >
                                        <i class="bi bi-<?= $view === 'history' ? 'eye' : 'check2-square' ?>"></i>
                                        <?= $view === 'history' ? 'Detail' : 'Tinjau' ?>
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
            $paginationLabel = 'Navigasi halaman Kepala Departemen Operasional';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            unset($paginationLabel);
            ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
