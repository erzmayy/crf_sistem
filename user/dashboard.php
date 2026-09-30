<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo=getConnection();
$user=getCurrentUser();
$stmt=$pdo->prepare("SELECT COUNT(*) total, SUM(status='Draft') draft, SUM(status='Belum Ditindak Lanjuti') pending, SUM(status='Perlu Revisi') revision, SUM(status='Dalam Proses') processing, SUM(status='Solve') solved, SUM(status='Cancel') cancelled FROM change_requests WHERE user_id=:user_id");
$stmt->execute(['user_id'=>$user['id']]);
$summary=$stmt->fetch() ?: [];
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$pageTitle='Dashboard'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="crf-page crf-dashboard-page"><div class="container">
<div class="crf-page-header"><h1>Dashboard</h1><p>Ringkasan pengajuan Change Request Anda.</p></div>
<?php if($flash): ?><div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="crf-stat-grid">
<div class="crf-stat-card"><span>Total Pengajuan</span><strong><?= (int)($summary['total']??0) ?></strong></div>
<div class="crf-stat-card"><span>Draft</span><strong><?= (int)($summary['draft']??0) ?></strong></div>
<div class="crf-stat-card"><span>Perlu Revisi</span><strong><?= (int)($summary['revision']??0) ?></strong></div>
<div class="crf-stat-card"><span>Dalam Proses</span><strong><?= (int)($summary['processing']??0) ?></strong></div>
<div class="crf-stat-card"><span>Selesai</span><strong><?= (int)($summary['solved']??0) ?></strong></div>
<div class="crf-stat-card"><span>Dibatalkan</span><strong><?= (int)($summary['cancelled']??0) ?></strong></div>
</div>
<div class="mt-4 d-flex gap-2"><a href="form_crf.php" class="btn btn-crf-primary"><i class="bi bi-plus-circle"></i> Buat Pengajuan</a><a href="pengajuan_saya.php" class="btn btn-crf-outline"><i class="bi bi-list-check"></i> Pengajuan Saya</a></div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
