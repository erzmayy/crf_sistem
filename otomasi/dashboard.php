<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireCrfRole(['otomasi']);
$pdo=getConnection();
$total=(int)$pdo->query("SELECT COUNT(*) FROM change_requests WHERE workflow_stage='OTOMASI'")->fetchColumn();
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$pageTitle='Dashboard Otomasi'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page"><div class="container">
<div class="crf-page-header"><h1>Dashboard Otomasi</h1><p>Ringkasan CRF yang sedang ditangani oleh Otomasi.</p></div>
<?php if($flash): ?><div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="crf-stat-grid"><a class="crf-stat-card text-decoration-none" href="index.php"><span>Menunggu Penanganan</span><strong><?= $total ?></strong></a></div>
<div class="mt-4"><a href="index.php" class="btn btn-crf-primary"><i class="bi bi-gear-fill"></i> Buka Antrean Otomasi</a></div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
