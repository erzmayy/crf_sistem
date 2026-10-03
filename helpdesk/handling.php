<?php
/**
 * helpdesk/handling.php
 * Dashboard Handling Helpdesk: kartu ringkasan per kategori.
 * Kartu bersifat dinamis mengikuti kategori yang dibuat Admin dan
 * difilter sesuai kategori PIC (Admin melihat semua).
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireHelpdeskPic();

$pdo = getConnection();
$scope = helpdeskCategoryScopeForUser($pdo);

$scopeSql = $scope === null
    ? '1 = 1'
    : ($scope ? 'c.id IN (' . implode(',', array_map('intval', $scope)) . ')' : '1 = 0');

$cards = $pdo->query("
    SELECT c.id, c.name, c.icon, c.requires_crf, c.is_active,
        COUNT(t.id) AS total,
        COALESCE(SUM(t.status = 'Selesai'), 0) AS selesai,
        COALESCE(SUM(t.status IN ('Belum Ditindaklanjuti', 'Dalam Proses', 'Diteruskan ke CRF')), 0) AS sisa,
        COALESCE(SUM(t.status = 'Dibatalkan'), 0) AS batal
    FROM helpdesk_categories c
    LEFT JOIN helpdesk_tickets t ON t.helpdesk_category_id = c.id
    WHERE c.deleted_at IS NULL AND {$scopeSql}
    GROUP BY c.id, c.name, c.icon, c.requires_crf, c.is_active, c.sort_order
    ORDER BY c.sort_order, c.name
")->fetchAll();

$totals = ['kategori' => count($cards), 'total' => 0, 'selesai' => 0, 'sisa' => 0, 'batal' => 0];
foreach ($cards as $card) {
    foreach (['total', 'selesai', 'sisa', 'batal'] as $key) {
        $totals[$key] += (int) $card[$key];
    }
}

$pageTitle = 'Dashboard Handling Helpdesk';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">PT PERSONA PRIMA UTAMA</span>
                <h1>Dashboard Handling Helpdesk</h1>
                <p>Ringkasan penanganan permintaan per kategori helpdesk.</p>
            </div>
            <div class="crf-banner-actions">
                <span class="crf-banner-stat">
                    <strong><?= number_format($totals['selesai'], 0, ',', '.') ?></strong>/<?= number_format($totals['total'], 0, ',', '.') ?>
                    <small>Helpdesk selesai</small>
                </span>
            </div>
        </div>

        <div class="crf-kpi-grid mb-4">
            <div class="crf-kpi-card crf-kpi-card--primary"><span class="crf-kpi-icon"><i class="bi bi-tags"></i></span><div><span class="crf-kpi-label">Kategori</span><strong><?= $totals['kategori'] ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--info"><span class="crf-kpi-icon"><i class="bi bi-inbox"></i></span><div><span class="crf-kpi-label">Total Isi Helpdesk</span><strong><?= number_format($totals['total'], 0, ',', '.') ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--success"><span class="crf-kpi-icon"><i class="bi bi-check-circle"></i></span><div><span class="crf-kpi-label">Sudah Selesai</span><strong><?= number_format($totals['selesai'], 0, ',', '.') ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--danger"><span class="crf-kpi-icon"><i class="bi bi-exclamation-circle"></i></span><div><span class="crf-kpi-label">Belum Selesai</span><strong><?= number_format($totals['sisa'], 0, ',', '.') ?></strong></div></div>
            <div class="crf-kpi-card crf-kpi-card--secondary"><span class="crf-kpi-icon"><i class="bi bi-slash-circle"></i></span><div><span class="crf-kpi-label">Dibatalkan</span><strong><?= number_format($totals['batal'], 0, ',', '.') ?></strong></div></div>
        </div>

        <?php if (!$cards): ?>
            <div class="crf-empty-state">
                <i class="bi bi-inbox"></i>
                <p>Anda belum ditugaskan sebagai PIC kategori mana pun.</p>
            </div>
        <?php endif; ?>

        <div class="crf-category-card-grid">
            <?php foreach ($cards as $card): ?>
                <article class="crf-category-card">
                    <header>
                        <span class="crf-category-icon"><i class="bi <?= h($card['icon']) ?>"></i></span>
                        <div>
                            <span class="crf-category-eyebrow">Kategori<?= $card['requires_crf'] ? ' · via CRF' : '' ?></span>
                            <h2><?= h($card['name']) ?></h2>
                        </div>
                        <?php if ((int) $card['sisa'] > 0): ?>
                            <span class="crf-category-ring is-pending"><strong><?= (int) $card['sisa'] ?></strong><small>Sisa</small></span>
                        <?php else: ?>
                            <span class="crf-category-ring is-done"><i class="bi bi-check-lg"></i><small>Tuntas</small></span>
                        <?php endif; ?>
                    </header>
                    <div class="crf-category-stats">
                        <div><strong><?= number_format((int) $card['total'], 0, ',', '.') ?></strong><span>Jumlah</span></div>
                        <div class="is-success"><strong><?= number_format((int) $card['selesai'], 0, ',', '.') ?></strong><span>Sudah Selesai</span></div>
                        <div class="is-danger"><strong><?= number_format((int) $card['sisa'], 0, ',', '.') ?></strong><span>Belum Selesai</span></div>
                    </div>
                    <?php if ((int) $card['batal'] > 0): ?>
                        <div class="crf-category-note"><i class="bi bi-slash-circle"></i> <?= (int) $card['batal'] ?> dibatalkan</div>
                    <?php endif; ?>
                    <a href="kategori.php?id=<?= (int) $card['id'] ?>" class="btn btn-crf-outline w-100 mt-auto">
                        Lihat Isi Helpdesk <i class="bi bi-arrow-right"></i>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
