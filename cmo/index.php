<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireCrfRole(['cmo']);

$pdo = getConnection();

$filter = $_GET['stage'] ?? 'all';
$search = $_GET['q'] ?? '';
$allowedFilters = ['all', 'filter', 'uat', 'pir', 'final', 'history'];
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
    $where[] = "cr.workflow_stage IN ('CMO_FILTER','UAT','PEMOHON_PIR','CMO_FINAL')";
}

if ($filter === 'filter') {
    $where[] = "cr.workflow_stage = 'CMO_FILTER'";
} elseif ($filter === 'uat') {
    $where[] = "cr.workflow_stage = 'UAT'";
} elseif ($filter === 'pir') {
    $where[] = "cr.workflow_stage = 'PEMOHON_PIR'";
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
    'category_id' => $_GET['category_id'] ?? '',
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
    ORDER BY " . ($filter === 'history'
        ? 'cr.updated_at DESC'
        // Antrean kerja: yang menunggu Pemohon di bawah, lalu urgensi (Tinggi dulu), lalu yang paling lama menunggu.
        : "cr.workflow_stage = 'PEMOHON_PIR',
        FIELD(COALESCE(cr.final_urgency_level, cr.level), 'Tinggi', 'Normal', 'Rendah') = 0,
        FIELD(COALESCE(cr.final_urgency_level, cr.level), 'Tinggi', 'Normal', 'Rendah'),
        cr.updated_at ASC,
        cr.id ASC") . "
    LIMIT {$perPage} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();


/* =========================================================
 * SUMMARY
 * ========================================================= */

$countStmt = $pdo->query("SELECT workflow_stage, COUNT(*) total FROM change_requests WHERE workflow_stage IN ('CMO_FILTER','UAT','PEMOHON_PIR','CMO_FINAL') AND status <> 'Draft' GROUP BY workflow_stage");
$counts = ['CMO_FILTER' => 0, 'UAT' => 0, 'PEMOHON_PIR' => 0, 'CMO_FINAL' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $counts[$row['workflow_stage']] = (int) $row['total'];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'CMO';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page crf-helpdesk-page">
  <div class="container">
    <div class="crf-helpdesk-banner">
      <div>
        <span class="crf-helpdesk-eyebrow">PORTAL CRF · VERIFIKASI CMO</span>
        <h1>Verifikasi Permohonan Perubahan</h1>
        <p>Kelola CRF pada tahap filter, UAT, pemantauan PIR, dan finalisasi.</p>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <div class="crf-stat-grid crf-helpdesk-summary mb-4">
      <a class="crf-stat-card text-decoration-none" href="?stage=filter">
        <span><i class="bi bi-funnel-fill"></i> Menunggu Verifikasi</span>
        <strong><?= $counts['CMO_FILTER'] ?></strong>
      </a>
      <a class="crf-stat-card text-decoration-none" href="?stage=uat" title="Eksekusi selesai, menunggu pengujian UAT oleh CMO">
        <span><i class="bi bi-clipboard2-check"></i> Menunggu UAT</span>
        <strong><?= $counts['UAT'] ?></strong>
      </a>
      <a class="crf-stat-card text-decoration-none" href="?stage=pir" title="Implementasi selesai, menunggu Post Implementation Review dari Pemohon">
        <span><i class="bi bi-hourglass-split"></i> Menunggu PIR Pemohon</span>
        <strong><?= $counts['PEMOHON_PIR'] ?></strong>
      </a>
      <a class="crf-stat-card text-decoration-none" href="?stage=final">
        <span><i class="bi bi-check2-square"></i> Menunggu Finalisasi</span>
        <strong><?= $counts['CMO_FINAL'] ?></strong>
      </a>
    </div>

    <div class="crf-table-card crf-list-table-card">
      <div class="crf-table-heading">
        <h2><?= $filter === 'history' ? 'Riwayat CRF CMO' : 'Daftar CRF CMO' ?></h2>
        <div class="btn-group">
          <a href="?stage=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Semua</a>
          <a href="?stage=filter" class="btn btn-sm <?= $filter === 'filter' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Verifikasi</a>
          <a href="?stage=uat" class="btn btn-sm <?= $filter === 'uat' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">UAT</a>
          <a href="?stage=pir" class="btn btn-sm <?= $filter === 'pir' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Menunggu PIR</a>
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

      <div class="table-responsive crf-table-responsive-cards crf-helpdesk-table-wrap">
        <table class="table crf-table crf-helpdesk-table crf-helpdesk-table--cmo align-middle">
          <thead><tr>
            <th>No</th><th>Pengajuan</th><th>Isi Pengajuan</th><th>Urgensi</th><th>Status</th><th>Aksi</th>
          </tr></thead>
          <tbody>
          <?php if (!$requests): ?>
            <tr><td data-label="Pengajuan" colspan="6" class="text-center text-muted py-4">Belum ada CRF yang cocok dengan pencarian/filter ini.</td></tr>
          <?php else: ?>
            <?php foreach ($requests as $i => $row): ?>
              <tr>
                <td data-label="No"><?= $offset + $i + 1 ?></td>
                <?php require __DIR__ . '/../includes/partials/crf_row_request.php'; ?>
                <?php require __DIR__ . '/../includes/partials/crf_row_status.php'; ?>
                <td data-label="Aksi"><div class="d-flex gap-2"><a href="detail.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-crf-outline"><i class="bi bi-eye"></i> Detail</a><?php if (($row['status'] ?? '') !== 'Draft'): ?><a href="../actions/export_crf.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-crf-outline" target="_blank" rel="noopener" title="Cetak PDF"><i class="bi bi-printer"></i> Cetak</a><?php endif; ?></div></td>
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
