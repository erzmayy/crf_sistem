<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forum.php';
requireCrfRole(['otomasi']);
$pdo=getConnection();
$forumUnread=forumUnreadTotal($pdo, (int) getCurrentUser()['id']);
$scopeSql = crfHandlerScopeSql($pdo, 'cr');
$total=(int)$pdo->query("SELECT COUNT(*) FROM change_requests cr WHERE cr.workflow_stage='OTOMASI' AND {$scopeSql}")->fetchColumn();
$unassigned=(int)$pdo->query("SELECT COUNT(*) FROM change_requests cr WHERE cr.workflow_stage='OTOMASI' AND cr.assigned_handler_id IS NULL AND {$scopeSql}")->fetchColumn();
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$pageTitle='Dashboard Tindak Lanjut Otomasi'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page crf-dashboard-page"><div class="container">
<div class="crf-page-header"><h1>Dashboard Tindak Lanjut Otomasi</h1><p>Ringkasan CRF pada kategori yang Anda tangani.</p></div>
<?php if($flash): ?><div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="crf-stat-grid"><a class="crf-stat-card text-decoration-none" href="index.php"><span>Menunggu Penanganan</span><strong><?= $total ?></strong></a><a class="crf-stat-card text-decoration-none" href="index.php"><span>Belum Diambil Petugas Otomasi</span><strong><?= $unassigned ?></strong></a><a class="crf-stat-card text-decoration-none crf-forum-stat" href="../forum/index.php"><span>Komentar Baru di Forum</span><strong><?= $forumUnread ?></strong></a></div>
<div class="mt-4 d-flex gap-2 flex-wrap"><a href="index.php" class="btn btn-crf-primary"><i class="bi bi-gear-fill"></i> Buka Antrean Tindak Lanjut</a><a href="../helpdesk/handling.php?tab=crf" class="btn btn-crf-outline"><i class="bi bi-collection"></i> Dashboard Kategori</a></div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
