<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireCrfRole(['otomasi']);

$pdo = getConnection();

$queue = $_GET['queue'] ?? 'all';
$search = $_GET['q'] ?? '';

if (!in_array($queue, ['all', 'sla', 'execution', 'history'], true)) {
    $queue = 'all';
}

// Handler hanya melihat CRF pada kategori yang ditanganinya (Admin: semua).
$handlerScopeSql = crfHandlerScopeSql($pdo, 'cr');
$where = ["cr.status <> 'Draft'", $handlerScopeSql];
$params = [];

/* =========================================================
 * FILTER ANTREAN
 * ========================================================= */

if ($queue === 'history') {
    $where[] = "EXISTS (
        SELECT 1
        FROM crf_activity_logs activity_log
        WHERE activity_log.change_request_id = cr.id
          AND activity_log.activity IN (
              'Proses Otomasi Diperbarui',
              'Otomasi - SLA Ditentukan',
              'Otomasi Selesai'
          )
    )";
} else {
    $where[] = "cr.workflow_stage = 'OTOMASI'";
}

if ($queue === 'sla') {
    $where[] = 'cr.kadep_operasional_approved_at IS NULL';
} elseif ($queue === 'execution') {
    $where[] = 'cr.kadep_operasional_approved_at IS NOT NULL';
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

// Filter kategori hanya menampilkan kategori dalam lingkup handler.
$handlerScope = crfHandlerScope($pdo);
if (!$handlerScope['all']) {
    $listFilters['categories'] = array_values(array_filter(
        $listFilters['categories'],
        static fn($category) => in_array((int) $category['id'], $handlerScope['category_ids'], true)
    ));
}

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
    SELECT cr.*, cc.name AS crf_category_name
    FROM change_requests cr
    LEFT JOIN crf_categories cc ON cc.id = cr.crf_category_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY " . ($queue === 'history'
        ? 'cr.updated_at DESC'
        : "CASE
            WHEN cr.kadep_operasional_approved_at IS NOT NULL THEN 0
            ELSE 1
        END,
        cr.automation_started_at ASC,
        cr.created_at ASC") . "
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

/* =========================================================
 * SUMMARY CARD
 * ========================================================= */

$summaryStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN kadep_operasional_approved_at IS NULL THEN 1 ELSE 0 END) AS waiting_sla,
        SUM(CASE WHEN kadep_operasional_approved_at IS NOT NULL THEN 1 ELSE 0 END) AS waiting_execution
    FROM change_requests cr
    WHERE cr.workflow_stage = 'OTOMASI'
      AND cr.status <> 'Draft'
      AND {$handlerScopeSql}
");
$myQueueStmt = $pdo->prepare("
    SELECT COUNT(*) FROM change_requests cr
    WHERE cr.workflow_stage = 'OTOMASI' AND cr.assigned_handler_id = :user_id
");
$myQueueStmt->execute(['user_id' => (int) $_SESSION['user_id']]);
$myQueue = (int) $myQueueStmt->fetchColumn();
$unassignedStmt = $pdo->query("
    SELECT COUNT(*) FROM change_requests cr
    WHERE cr.workflow_stage = 'OTOMASI' AND cr.assigned_handler_id IS NULL AND {$handlerScopeSql}
");
$unassigned = (int) $unassignedStmt->fetchColumn();

$summary = $summaryStmt->fetch() ?: [];

$waitingSla = (int) ($summary['waiting_sla'] ?? 0);
$waitingExecution = (int) ($summary['waiting_execution'] ?? 0);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Otomasi';

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

    .queue-filter-buttons {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .queue-filter-buttons .btn {
        white-space: nowrap;
    }
</style>

<div class="crf-page crf-helpdesk-page">
    <div class="container">

        <div class="crf-helpdesk-banner">
            <div>
                <span class="crf-helpdesk-eyebrow">PORTAL CRF · TINDAK LANJUT OTOMASI</span>
                <h1>Tindak Lanjut Permohonan Perubahan</h1>
                <p>CRF pada kategori yang Anda tangani: ambil CRF, tentukan Level Urgensi &amp; SLA, lalu isi hasil implementasi.</p>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- =====================================================
             SUMMARY
             ===================================================== -->
        <div class="crf-stat-grid crf-helpdesk-summary mb-4">
            <div class="crf-stat-card">
                <span><i class="bi bi-hourglass-split"></i> Menunggu Penentuan SLA</span>
                <strong><?= $waitingSla ?></strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-gear-wide-connected"></i> Menunggu Eksekusi</span>
                <strong><?= $waitingExecution ?></strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-person-dash"></i> Belum Diambil Petugas Otomasi</span>
                <strong><?= $unassigned ?></strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-person-check"></i> Ditangani Saya</span>
                <strong><?= $myQueue ?></strong>
            </div>
        </div>

        <div class="crf-table-card crf-list-table-card">

            <div class="crf-table-heading">
                <h2><?= $queue === 'history' ? 'Riwayat Tindak Lanjut' : 'Antrean Tindak Lanjut' ?></h2>

                <!-- FILTER ANTREAN -->
                <div class="btn-group">
                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'all'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'all' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Semua
                    </a>

                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'sla'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'sla' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Penentuan SLA
                    </a>

                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'execution'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'execution' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Eksekusi
                    </a>
                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'history'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'history' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Riwayat
                    </a>
                </div>
            </div>

            <!-- SEARCH -->
            <?php
            $listContextName = 'queue';
            $listContextValue = $queue;
            $listResetUrl = 'index.php';
            require __DIR__ . '/../includes/partials/crf_list_filters.php';
            unset($listContextName, $listContextValue, $listResetUrl);
            ?>

            <div class="table-responsive crf-table-responsive-cards crf-helpdesk-table-wrap">
                <table class="table crf-table crf-helpdesk-table crf-helpdesk-table--automation align-middle">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Pengajuan</th>
                            <th>Isi Pengajuan</th>
                            <th>Urgensi</th>
                            <th>Status</th>
                            <th>SLA</th>
                            <?php if ($queue !== 'history'): ?>
                                <th>Status SLA</th>
                            <?php endif; ?>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td data-label="Pengajuan" colspan="<?= $queue === 'history' ? 7 : 8 ?>" class="text-center text-muted py-4">
                                <?= $queue === 'history'
                                    ? 'Belum ada CRF yang pernah diproses oleh Otomasi.'
                                    : 'Tidak ada CRF pada antrean Otomasi sesuai filter yang dipilih.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $i => $row): ?>
                            <tr>
                                <td data-label="No"><?= $offset + $i + 1 ?></td>

                                <?php $rowShowDepartment = false; $rowShowHandler = true; require __DIR__ . '/../includes/partials/crf_row_request.php'; ?>
                                <?php require __DIR__ . '/../includes/partials/crf_row_status.php'; ?>

                                <td data-label="SLA">
                                    <?= $row['sla_value'] !== null && $row['sla_unit']
                                        ? h(rtrim(rtrim(number_format((float) $row['sla_value'], 2, '.', ''), '0'), '.')) . ' ' . h($row['sla_unit'])
                                        : '-' ?>
                                </td>

                                <?php if ($queue !== 'history'): ?>
                                    <?php $rowSlaStatus = getCrfSlaStatus($row); ?>
                                    <td data-label="Status SLA">
                                        <div
                                            class="automation-sla-status"
                                            <?= $rowSlaStatus['live'] && !empty($row['sla_due_at'])
                                                ? 'data-sla-countdown="true" data-sla-due-at="' . (int) strtotime($row['sla_due_at']) . '"'
                                                : '' ?>
                                        >
                                            <span class="badge text-bg-<?= h($rowSlaStatus['class']) ?>" data-sla-status-label>
                                                <?= $rowSlaStatus['label'] === 'Melewati SLA'
                                                    ? 'SLA Terlewati'
                                                    : h($rowSlaStatus['label']) ?>
                                            </span>
                                            <small class="automation-sla-status-detail" data-sla-status-detail>
                                                <?= $rowSlaStatus['label'] === 'Melewati SLA'
                                                    ? 'Sudah melewati SLA selama ' . h(str_replace('Terlambat ', '', rtrim($rowSlaStatus['detail'], '.'))) . '.'
                                                    : h($rowSlaStatus['detail']) ?>
                                            </small>
                                        </div>
                                    </td>
                                <?php endif; ?>

                                <td data-label="Aksi">
                                    <div class="d-flex gap-2">
                                        <a
                                            href="view_detail.php?id=<?= (int) $row['id'] ?>"
                                            class="btn btn-sm btn-crf-outline"
                                        >
                                            <i class="bi bi-eye"></i> Detail
                                        </a>

                                        <?php if ($queue !== 'history'): ?>
                                            <a
                                                href="detail.php?id=<?= (int) $row['id'] ?>"
                                                class="btn btn-sm <?= !empty($row['kadep_operasional_approved_at']) ? 'btn-danger' : 'btn-crf-primary' ?>"
                                            >
                                                <i class="bi <?= !empty($row['kadep_operasional_approved_at']) ? 'bi-play-circle' : 'bi-gear' ?>"></i>
                                                <?= !empty($row['kadep_operasional_approved_at']) ? 'Eksekusi' : 'Proses' ?>
                                            </a>
                                        <?php endif; ?>
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
            $paginationLabel = 'Navigasi halaman Otomasi';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            unset($paginationLabel);
            ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
