<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireCrfRole(['cmo']);

$pdo = getConnection();
$filter = $_GET['stage'] ?? 'all';
$where = ["cr.status <> 'Draft'", "cr.workflow_stage IN ('CMO_FILTER','CMO_FINAL')"];
$params = [];

if ($filter === 'filter') {
    $where[] = "cr.workflow_stage = 'CMO_FILTER'";
} elseif ($filter === 'final') {
    $where[] = "cr.workflow_stage = 'CMO_FINAL'";
}

$sql = "SELECT cr.*
        FROM change_requests cr
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cr.updated_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

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

    <div class="crf-table-card">
      <div class="crf-table-heading">
        <h2>Daftar CRF CMO</h2>
        <div class="btn-group">
          <a href="?stage=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Semua</a>
          <a href="?stage=filter" class="btn btn-sm <?= $filter === 'filter' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Filter</a>
          <a href="?stage=final" class="btn btn-sm <?= $filter === 'final' ? 'btn-crf-primary' : 'btn-crf-outline' ?>">Finalisasi</a>
        </div>
      </div>

        <div class="table-responsive crf-table-responsive-cards">
        <table class="table crf-table align-middle">
          <thead><tr>
            <th>No</th><th>Nomor Register</th><th>Pengaju</th><th>Tanggal</th><th>Status</th><th>Tahap</th><th>Aksi</th>
          </tr></thead>
          <tbody>
          <?php if (!$requests): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Belum ada CRF pada antrean CMO.</td></tr>
          <?php else: ?>
            <?php foreach ($requests as $i => $row): ?>
              <tr>
                <td data-label="No"><?= $i + 1 ?></td>
                <td data-label="Nomor Register"><strong><?= h($row['request_number']) ?></strong></td>
                <td data-label="Pengaju"><?= h($row['full_name']) ?></td>
                <td data-label="Tanggal"><?= !empty($row['submission_date']) ? h(date('d-m-Y', strtotime($row['submission_date']))) : '-' ?></td>
                <td data-label="Status"><span class="crf-badge <?= statusBadgeClass($row['status']) ?>"><?= h(statusLabel($row['status'])) ?></span></td>
                <td data-label="Tahap"><span class="crf-badge <?= workflowStageBadgeClass($row['workflow_stage']) ?>"><?= h(workflowStageLabel($row['workflow_stage'])) ?></span></td>
                <td data-label="Aksi"><a href="detail.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-crf-primary"><i class="bi bi-eye"></i> Detail</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
