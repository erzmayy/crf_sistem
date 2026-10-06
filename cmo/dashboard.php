<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forum_discussions.php';
requireCrfRole(['cmo']);
$pdo = getConnection();
$forumUnread = forumUnreadTotal($pdo, (int) getCurrentUser()['id']);
$forumDiscussionsOpen = forumOpenDiscussionTotal($pdo);
$counts = ['CMO_FILTER'=>0,'CMO_FINAL'=>0,'SELESAI'=>0];
$stmt = $pdo->query("SELECT workflow_stage, COUNT(*) total FROM change_requests GROUP BY workflow_stage");
foreach ($stmt->fetchAll() as $row) { if (isset($counts[$row['workflow_stage']])) $counts[$row['workflow_stage']] = (int)$row['total']; }
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$pageTitle='Dashboard CMO'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page crf-dashboard-page"><div class="container">
<div class="crf-page-header"><h1>Dashboard CMO</h1><p>Ringkasan antrean CRF yang menjadi tanggung jawab CMO.</p></div>
<?php if($flash): ?><div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="crf-stat-grid">
<a class="crf-stat-card text-decoration-none" href="index.php?stage=filter"><span>Menunggu Verifikasi</span><strong><?= $counts['CMO_FILTER'] ?></strong></a>
<a class="crf-stat-card text-decoration-none" href="index.php?stage=final"><span>Menunggu Finalisasi</span><strong><?= $counts['CMO_FINAL'] ?></strong></a>
<div class="crf-stat-card"><span>Sudah Selesai</span><strong><?= $counts['SELESAI'] ?></strong></div>
<a class="crf-stat-card text-decoration-none crf-forum-stat" href="../forum/index.php"><span>Komentar Baru di Forum</span><strong><?= $forumUnread ?></strong></a>
<a class="crf-stat-card text-decoration-none crf-forum-stat" href="../forum/index.php?filter=discuss"><span>Menunggu Pembahasan Forum</span><strong><?= $forumDiscussionsOpen ?></strong></a>
</div>
<div class="mt-4"><a href="index.php" class="btn btn-crf-primary"><i class="bi bi-funnel-fill"></i> Buka Antrean CMO</a></div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
