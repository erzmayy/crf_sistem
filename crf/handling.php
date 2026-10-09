<?php
/**
 * crf/handling.php
 * Handling Kategori CRF: kartu per kategori.
 * Handler (PIC CRF) hanya melihat kategori yang ditanganinya; Admin, CMO,
 * dan Kadep melihat semua kategori.
 * Kartu bersifat dinamis mengikuti master kategori.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$pdo = getConnection();
$role = getCrfRole();

if (!in_array($role, ['admin', 'demo', 'cmo', 'otomasi', 'kadep_operasional'], true)) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Halaman ini khusus pengelola CRF.',
    ];
    header('Location: ../user/pengajuan_saya.php');
    exit;
}

$crfTotals = ['kategori' => 0, 'masuk' => 0, 'review' => 0, 'diproses' => 0, 'approval' => 0, 'selesai' => 0, 'lewat_sla' => 0];

$conditions = crfDisplayStatusConditions('cr');

if ($role === 'otomasi') {
    $crfScope = crfHandlerScope($pdo);
    $categoryScopeSql = $crfScope['category_ids']
        ? 'c.id IN (' . implode(',', array_map('intval', $crfScope['category_ids'])) . ')'
        : '1 = 0';
} else {
    $categoryScopeSql = '1 = 1';
}

$crfCards = $pdo->query("
    SELECT c.id, c.name, c.description, c.is_active, c.sla_value, c.sla_unit,
        COALESCE(SUM(cr.status <> 'Draft'), 0) AS masuk,
        COALESCE(SUM(CASE WHEN ({$conditions['review']['sql']}) OR ({$conditions['pembahasan']['sql']}) THEN 1 ELSE 0 END), 0) AS review,
        COALESCE(SUM(CASE WHEN {$conditions['disetujui']['sql']} THEN 1 ELSE 0 END), 0) AS diproses,
        COALESCE(SUM(CASE WHEN {$conditions['approval']['sql']} THEN 1 ELSE 0 END), 0) AS approval,
        COALESCE(SUM(CASE WHEN {$conditions['selesai']['sql']} THEN 1 ELSE 0 END), 0) AS selesai,
        COALESCE(SUM(cr.sla_result = 'Melebihi SLA'), 0) AS lewat_sla,
        (SELECT COUNT(*) FROM crf_category_handlers h WHERE h.crf_category_id = c.id) AS handler_count
    FROM crf_categories c
    LEFT JOIN change_requests cr ON cr.crf_category_id = c.id
    WHERE c.deleted_at IS NULL AND {$categoryScopeSql}
    GROUP BY c.id, c.name, c.description, c.is_active, c.sla_value, c.sla_unit, c.sort_order
    ORDER BY c.sort_order, c.name
")->fetchAll();

$crfTotals['kategori'] = count($crfCards);
foreach ($crfCards as $card) {
    foreach (['masuk', 'review', 'diproses', 'approval', 'selesai', 'lewat_sla'] as $key) {
        $crfTotals[$key] += (int) $card[$key];
    }
}

// Tujuan tombol "Lihat CRF" sesuai peran.
$crfListPage = [
    'admin' => '../admin/dashboard.php',
    'demo' => '../admin/dashboard.php',
    'cmo' => '../cmo/index.php',
    'otomasi' => '../otomasi/index.php',
    'kadep_operasional' => '../pak_joko/index.php',
][$role];
$crfListExtra = $role === 'otomasi' ? ['queue' => 'all'] : [];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Handling Kategori CRF';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">PT PERSONA PRIMA UTAMA</span>
                <h1>Handling Kategori CRF</h1>
                <p>Ringkasan penanganan CRF per kategori.</p>
            </div>
            <div class="crf-banner-actions">
                <span class="crf-banner-stat">
                    <strong><?= number_format($crfTotals['selesai'], 0, ',', '.') ?></strong>/<?= number_format($crfTotals['masuk'], 0, ',', '.') ?>
                    <small>CRF selesai</small>
                </span>
                <?php if (isAdmin()): ?>
                    <a href="../admin/master_data.php" class="btn btn-light"><i class="bi bi-people"></i> Master Kategori</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="crf-kpi-grid mb-4">
            <div class="crf-kpi-card crf-kpi-card--primary"><span class="crf-kpi-icon"><i class="bi bi-bookmark"></i></span><div><span class="crf-kpi-label">Kategori</span><strong><?= $crfTotals['kategori'] ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--info"><span class="crf-kpi-icon"><i class="bi bi-inbox"></i></span><div><span class="crf-kpi-label">CRF Masuk</span><strong><?= number_format($crfTotals['masuk'], 0, ',', '.') ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--success"><span class="crf-kpi-icon"><i class="bi bi-check-circle"></i></span><div><span class="crf-kpi-label">Selesai</span><strong><?= number_format($crfTotals['selesai'], 0, ',', '.') ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--danger"><span class="crf-kpi-icon"><i class="bi bi-hourglass-split"></i></span><div><span class="crf-kpi-label">Masih Berjalan</span><strong><?= number_format($crfTotals['review'] + $crfTotals['diproses'] + $crfTotals['approval'], 0, ',', '.') ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--secondary"><span class="crf-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span><div><span class="crf-kpi-label">Melebihi SLA</span><strong><?= number_format($crfTotals['lewat_sla'], 0, ',', '.') ?></strong></div></div>
        </div>

        <?php if (!$crfCards): ?>
            <div class="crf-empty-state">
                <i class="bi bi-collection"></i>
                <p>
                    <?= $role === 'otomasi'
                        ? 'Anda belum terdaftar sebagai PIC CRF kategori CRF mana pun.'
                        : 'Belum ada kategori CRF.' ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="crf-category-card-grid">
            <?php foreach ($crfCards as $card): ?>
                <article class="crf-category-card">
                    <header>
                        <span class="crf-category-icon"><i class="bi bi-bookmark"></i></span>
                        <div>
                            <span class="crf-category-eyebrow">Kategori CRF<?= !$card['is_active'] ? ' · Nonaktif' : '' ?></span>
                            <h2><?= h($card['name']) ?></h2>
                        </div>
                        <span class="crf-category-ring <?= (int) $card['masuk'] - (int) $card['selesai'] > 0 ? 'is-pending' : 'is-done' ?>">
                            <strong><?= (int) $card['masuk'] ?></strong><small>Masuk</small>
                        </span>
                    </header>
                    <ul class="crf-category-status-list">
                        <li><span><i class="bi bi-hourglass-split"></i> Menunggu Verifikasi</span><strong><?= (int) $card['review'] ?></strong></li>
                        <li><span><i class="bi bi-gear"></i> Sedang Diproses</span><strong><?= (int) $card['diproses'] ?></strong></li>
                        <li><span><i class="bi bi-person-check"></i> Menunggu Persetujuan</span><strong><?= (int) $card['approval'] ?></strong></li>
                        <li class="is-success"><span><i class="bi bi-check-circle"></i> Selesai</span><strong><?= (int) $card['selesai'] ?></strong></li>
                    </ul>
                    <div class="crf-category-note">
                        <i class="bi bi-people"></i> <?= (int) $card['handler_count'] ?> PIC CRF
                        · <i class="bi bi-stopwatch"></i> SLA <?= h(slaLabel($card['sla_value'], $card['sla_unit'])) ?>
                        <?php if ((int) $card['lewat_sla'] > 0): ?>
                            · <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> <?= (int) $card['lewat_sla'] ?> melebihi SLA</span>
                        <?php endif; ?>
                    </div>
                    <a href="<?= h($crfListPage . '?' . http_build_query($crfListExtra + ['category_id' => (int) $card['id']])) ?>" class="btn btn-crf-outline w-100 mt-auto">
                        Lihat CRF <i class="bi bi-arrow-right"></i>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
