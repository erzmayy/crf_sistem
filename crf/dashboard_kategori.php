<?php
/**
 * crf/dashboard_kategori.php
 * Dashboard Kategori CRF: kartu per kategori (dinamis dari master).
 * Handler hanya melihat kategori yang ditanganinya; Admin, CMO, dan
 * Kadep melihat semua kategori.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['cmo', 'otomasi', 'kadep_operasional']);

$pdo = getConnection();
$role = getCrfRole();
$conditions = crfDisplayStatusConditions('cr');

if ($role === 'otomasi') {
    $scope = crfHandlerScope($pdo);
    $categoryScopeSql = $scope['category_ids']
        ? 'c.id IN (' . implode(',', array_map('intval', $scope['category_ids'])) . ')'
        : '1 = 0';
} else {
    $categoryScopeSql = '1 = 1';
}

$cards = $pdo->query("
    SELECT c.id, c.name, c.description, c.is_active, c.sla_value, c.sla_unit,
        COALESCE(SUM(cr.status <> 'Draft'), 0) AS masuk,
        COALESCE(SUM(CASE WHEN {$conditions['review']['sql']} THEN 1 ELSE 0 END), 0) AS review,
        COALESCE(SUM(CASE WHEN ({$conditions['diproses']['sql']}) OR ({$conditions['disetujui']['sql']}) THEN 1 ELSE 0 END), 0) AS diproses,
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

// Tujuan tombol "Lihat CRF" sesuai peran.
$listPage = [
    'admin' => '../admin/dashboard.php',
    'cmo' => '../cmo/index.php',
    'otomasi' => '../otomasi/index.php',
    'kadep_operasional' => '../pak_joko/index.php',
][$role];
$listExtra = $role === 'otomasi' ? ['queue' => 'all'] : [];

$pageTitle = 'Dashboard Kategori CRF';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">PORTAL CRF · MONITORING</span>
                <h1>Dashboard Kategori</h1>
                <p>Monitoring CRF berdasarkan kategori<?= $role === 'otomasi' ? ' yang Anda tangani' : '' ?>.</p>
            </div>
            <?php if ($role === 'admin'): ?>
                <div class="crf-banner-actions">
                    <a href="../admin/master_data.php?tab=crf" class="btn btn-light"><i class="bi bi-people"></i> Handling Kategori</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$cards): ?>
            <div class="crf-empty-state">
                <i class="bi bi-collection"></i>
                <p>
                    <?= $role === 'otomasi'
                        ? 'Anda belum terdaftar sebagai handler kategori CRF mana pun.'
                        : 'Belum ada kategori CRF.' ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="crf-category-card-grid">
            <?php foreach ($cards as $card): ?>
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
                        <li><span><i class="bi bi-hourglass-split"></i> Menunggu Review</span><strong><?= (int) $card['review'] ?></strong></li>
                        <li><span><i class="bi bi-gear"></i> Sedang Diproses</span><strong><?= (int) $card['diproses'] ?></strong></li>
                        <li><span><i class="bi bi-person-check"></i> Menunggu Approval</span><strong><?= (int) $card['approval'] ?></strong></li>
                        <li class="is-success"><span><i class="bi bi-check-circle"></i> Selesai</span><strong><?= (int) $card['selesai'] ?></strong></li>
                    </ul>
                    <div class="crf-category-note">
                        <i class="bi bi-people"></i> <?= (int) $card['handler_count'] ?> handler
                        · <i class="bi bi-stopwatch"></i> SLA <?= h(slaLabel($card['sla_value'], $card['sla_unit'])) ?>
                        <?php if ((int) $card['lewat_sla'] > 0): ?>
                            · <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> <?= (int) $card['lewat_sla'] ?> melebihi SLA</span>
                        <?php endif; ?>
                    </div>
                    <a href="<?= h($listPage . '?' . http_build_query($listExtra + ['category_id' => (int) $card['id']])) ?>" class="btn btn-crf-outline w-100 mt-auto">
                        Lihat CRF <i class="bi bi-arrow-right"></i>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
