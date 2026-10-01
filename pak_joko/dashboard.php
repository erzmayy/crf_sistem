<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forum.php';

requireCrfRole(['kadep_operasional']);

$pdo = getConnection();
$forumUnread = forumUnreadTotal($pdo, (int) getCurrentUser()['id']);

$total = (int) $pdo->query(
    "SELECT COUNT(*) FROM change_requests WHERE workflow_stage = 'kadep_operasional'"
)->fetchColumn();

$approvedStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM change_requests WHERE kadep_operasional_approved_by = :user_id'
);
$approvedStmt->execute(['user_id' => (int) getCurrentUser()['id']]);
$approved = (int) $approvedStmt->fetchColumn();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Dashboard Kepala Departemen Operasional';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page crf-dashboard-page"><div class="container">
<div class="crf-page-header"><h1>Dashboard Kepala Departemen Operasional</h1><p>Ringkasan CRF yang menunggu approval.</p></div>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="crf-stat-grid">
<a class="crf-stat-card text-decoration-none" href="index.php"><span>Menunggu Approval</span><strong><?= $total ?></strong></a>
<div class="crf-stat-card"><span>Sudah Di-approve</span><strong><?= $approved ?></strong></div>
<a class="crf-stat-card text-decoration-none crf-forum-stat" href="../forum/index.php"><span>Komentar Baru di Forum</span><strong><?= $forumUnread ?></strong></a>
</div>
<div class="mt-4"><a href="index.php" class="btn btn-crf-primary"><i class="bi bi-check2-square"></i> Buka Antrean Approval</a></div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>