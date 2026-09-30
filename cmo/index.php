<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireCrfRole(['cmo']);

$pdo = getConnection();

$filter = $_GET['stage'] ?? 'all';
$search = $_GET['q'] ?? '';
$allowedFilters = ['all', 'filter', 'final', 'history'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$where = ["cr.status <> 'Draft'"];
$params = [];

/* =========================================================
 * FILTER TAHAP CMO
 * ========================================================= */

if ($filter === 'history') {
    $where[] = "EXISTS (
        SELECT 1
        FROM crf_activity_logs activity_log
        WHERE activity_log.change_request_id = cr.id
          AND (
              activity_log.activity = 'Lolos Filter CMO'
              OR (
                  activity_log.activity = 'Perlu Revisi'
                  AND activity_log.description = 'CRF dikembalikan ke Pemohon untuk revisi.'
              )
              OR activity_log.activity IN ('Cancel', 'Solve')
          )
    )";
} else {
    $where[] = "cr.workflow_stage IN ('CMO_FILTER','CMO_FINAL')";
}

if ($filter === 'filter') {
    $where[] = "cr.workflow_stage = 'CMO_FILTER'";
} elseif ($filter === 'final') {
    $where[] = "cr.workflow_stage = 'CMO_FINAL'";
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
    SELECT cr.*
    FROM change_requests cr
    WHERE " . implode(' AND ', $where) . "
    ORDER BY cr.updated_at DESC
    LIMIT {$perPage} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();


/* =========================================================
 * SUMMARY
 * ========================================================= */

$countStmt = $pdo->query("SELECT workflow_stage, COUNT(*) total FROM change_requests WHERE workflow_stage IN ('CMO_FILTER','CMO_FINAL') AND status <> 'Draft' GROUP BY workflow_stage");
$counts = ['CMO_FILTER' => 0, 'CMO_FINAL' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $counts[$row['workflow_stage']] = (int) $row['total'];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'CMO';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page">
  <div class="container">
    <div class="crf-page-header">
      <h1>CMO</h1>
      <p>Kelola CRF pada tahap filter dan finalisasi.</p>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <div class="crf-stat-grid mb-4">
      <a class="crf-stat-card text-decoration-none" href="?stage=filter">
        <span>Menunggu Filter</span>
        <strong><?= $counts['CMO_FILTER'] ?></strong>
      </a>
      <a class="crf-stat-card text-decoration-none" href="?stage=final">
        <span>Menunggu Finalisasi</span>
        <strong><?= $counts['CMO_FINAL'] ?></strong>
      </a>
    </div>

    <div class="crf-table-card crf-list-table-card">
      <div class="crf-table-heading">
        <h2><?= $filter === 'history' ? 'Riwayat CRF CMO' : 'Daftar CRF CMO' ?></h2>
        <div class="btn-group">
          <a href="?stage=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Semua</a>
          <a href="?stage=filter" class="btn btn-sm <?= $filter === 'filter' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Filter</a>
          <a href="?stage=final" class="btn btn-sm <?= $filter === 'final' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Finalisasi</a>
          <a href="?stage=history" class="btn btn-sm <?= $filter === 'history' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Riwayat</a>
        </div>
      </div>

      <?php
      $listContextName = 'stage';
      $listContextValue = $filter;
      $listResetUrl = 'index.php';
      require __DIR__ . '/../includes/partials/crf_list_filters.php';
      unset($listContextName, $listContextValue, $listResetUrl);
      ?>

      <div class="table-responsive crf-table-responsive-cards">
        <table class="table crf-table align-middle">
          <thead><tr>
            <th>No</th><th>Nomor Register</th><th>Pengaju</th><th>Tanggal</th><th>Level Urgensi</th><th>Status</th><th>Tahap</th><th>Aksi</th>
          </tr></thead>
          <tbody>
          <?php if (!$requests): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Belum ada CRF yang cocok dengan pencarian/filter ini.</td></tr>
          <?php else: ?>
            <?php foreach ($requests as $i => $row): ?>
              <tr>
                <td data-label="No"><?= $offset + $i + 1 ?></td>
                <td data-label="Nomor Register"><strong><?= h($row['request_number']) ?></strong></td>
                <td data-label="Pengaju"><?= h($row['full_name']) ?></td>
                <td data-label="Tanggal"><?= !empty($row['submission_date']) ? h(date('d-m-Y', strtotime($row['submission_date']))) : '-' ?></td>
                <td data-label="Level Urgensi"><span class="crf-badge <?= levelBadgeClass($row['level']) ?>"><?= h(($row['level'] ?? null) === 'Normal' ? 'Sedang' : ($row['level'] ?? 'Belum ditentukan')) ?></span></td>
                <td data-label="Status"><span class="crf-badge <?= statusBadgeClass($row['status']) ?>"><?= h(statusLabel($row['status'])) ?></span></td>
                <td data-label="Tahap"><span class="crf-badge <?= workflowStageBadgeClass($row['workflow_stage']) ?>"><?= h(workflowStageLabel($row['workflow_stage'])) ?></span></td>
                <td data-label="Aksi"><a href="detail.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-crf-primary"><i class="bi bi-eye"></i> Detail</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php
      $paginationLabel = 'Navigasi halaman CMO';
      require __DIR__ . '/../includes/partials/crf_list_pagination.php';
      unset($paginationLabel);
      ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
