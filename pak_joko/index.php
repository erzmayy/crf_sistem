<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['pak_joko']);

$pdo = getConnection();
$stmt = $pdo->query("SELECT cr.* FROM change_requests cr WHERE cr.workflow_stage = 'PAK_JOKO' AND cr.status <> 'Draft' ORDER BY cr.updated_at ASC");
$requests = $stmt->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Kepala Departemen Operasional';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page">
  <div class="container">
    <div class="crf-page-header"><h1>Kepala Departemen Operasional - Approval</h1><p>Daftar CRF yang sudah ditangani Otomasi dan menunggu approval.</p></div>
    <?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div><?php endif; ?>
    <div class="crf-table-card">
      <div class="crf-table-heading"><h2>Menunggu Approval</h2></div>
      <div class="table-responsive crf-table-responsive-cards">
        <table class="table crf-table align-middle">
          <thead><tr><th>No</th><th>Nomor Register</th><th>Pengaju</th><th>Level</th><th>SLA</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php if (!$requests): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada CRF yang menunggu approval.</td></tr>
          <?php else: ?>
            <?php foreach ($requests as $i => $row): ?>
              <tr>
                <td data-label="No"><?= $i + 1 ?></td>
                <td data-label="Nomor Register"><strong><?= h($row['request_number']) ?></strong></td>
                <td data-label="Pengaju"><?= h($row['full_name']) ?></td>
                <td data-label="Level"><span class="crf-badge <?= levelBadgeClass($row['level']) ?>"><?= h($row['level'] ?? 'Belum ditentukan') ?></span></td>
                <td data-label="SLA"><?= $row['sla_value'] !== null && $row['sla_unit'] ? h(rtrim(rtrim(number_format((float) $row['sla_value'], 2, '.', ''), '0'), '.')) . ' ' . h($row['sla_unit']) : '-' ?></td>
                <td data-label="Aksi"><a href="detail.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-crf-primary"><i class="bi bi-check2-square"></i> Review</a></td>
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