<?php
/**
 * helpdesk/handling.php
 * Dashboard Handling Kategori (tunggal): kartu per kategori Helpdesk dan
 * kategori CRF dalam satu halaman bertab.
 * - Tab Helpdesk: PIC kategori Helpdesk (Admin melihat semua).
 * - Tab CRF: Handler hanya kategori yang ditanganinya; Admin, CMO, dan
 *   Kadep melihat semua kategori.
 * Kartu bersifat dinamis mengikuti master kategori.
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

$pdo = getConnection();
$role = getCrfRole();

$canHelpdesk = isAdmin() || isHelpdeskPic($pdo, (int) $_SESSION['user_id']);
$canCrf = in_array($role, ['admin', 'demo', 'cmo', 'otomasi', 'kadep_operasional'], true);

if (!$canHelpdesk && !$canCrf) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Halaman ini khusus PIC kategori Helpdesk dan pengelola CRF.',
    ];
    header('Location: saya.php');
    exit;
}

$requestedTab = ($_GET['tab'] ?? '') === 'crf' ? 'crf' : 'helpdesk';
$activeTab = $requestedTab === 'crf'
    ? ($canCrf ? 'crf' : 'helpdesk')
    : ($canHelpdesk ? 'helpdesk' : 'crf');

// ---------------------------------------------------------------
// Kategori Helpdesk
// ---------------------------------------------------------------
$helpdeskCards = [];
$helpdeskTotals = ['kategori' => 0, 'total' => 0, 'selesai' => 0, 'sisa' => 0, 'batal' => 0];

if ($canHelpdesk) {
    $scope = helpdeskCategoryScopeForUser($pdo);
    $scopeSql = $scope === null
        ? '1 = 1'
        : ($scope ? 'c.id IN (' . implode(',', array_map('intval', $scope)) . ')' : '1 = 0');

    $helpdeskCards = $pdo->query("
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

    $helpdeskTotals['kategori'] = count($helpdeskCards);
    foreach ($helpdeskCards as $card) {
        foreach (['total', 'selesai', 'sisa', 'batal'] as $key) {
            $helpdeskTotals[$key] += (int) $card[$key];
        }
    }
}

// ---------------------------------------------------------------
// Kategori CRF
// ---------------------------------------------------------------
$crfCards = [];
$crfTotals = ['kategori' => 0, 'masuk' => 0, 'review' => 0, 'diproses' => 0, 'approval' => 0, 'selesai' => 0, 'lewat_sla' => 0];
$crfListPage = '';
$crfListExtra = [];

if ($canCrf) {
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
            COALESCE(SUM(CASE WHEN {$conditions['review']['sql']} THEN 1 ELSE 0 END), 0) AS review,
            COALESCE(SUM(CASE WHEN ({$conditions['antrean']['sql']}) OR ({$conditions['disetujui']['sql']}) THEN 1 ELSE 0 END), 0) AS diproses,
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
}

$tabs = [];
if ($canHelpdesk) {
    $tabs['helpdesk'] = ['label' => 'Kategori Helpdesk', 'icon' => 'bi-headset', 'count' => $helpdeskTotals['sisa']];
}
if ($canCrf) {
    $tabs['crf'] = ['label' => 'Kategori CRF', 'icon' => 'bi-file-earmark-diff', 'count' => $crfTotals['masuk'] - $crfTotals['selesai']];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Dashboard Handling Kategori';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">PT PERSONA PRIMA UTAMA</span>
                <h1>Dashboard Handling Kategori</h1>
                <p>Ringkasan penanganan per kategori Helpdesk dan CRF dalam satu halaman.</p>
            </div>
            <div class="crf-banner-actions">
                <?php if ($canHelpdesk): ?>
                    <span class="crf-banner-stat">
                        <strong><?= number_format($helpdeskTotals['selesai'], 0, ',', '.') ?></strong>/<?= number_format($helpdeskTotals['total'], 0, ',', '.') ?>
                        <small>Helpdesk selesai</small>
                    </span>
                <?php endif; ?>
                <?php if ($canCrf): ?>
                    <span class="crf-banner-stat">
                        <strong><?= number_format($crfTotals['selesai'], 0, ',', '.') ?></strong>/<?= number_format($crfTotals['masuk'], 0, ',', '.') ?>
                        <small>CRF selesai</small>
                    </span>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                    <a href="../admin/master_data.php?tab=<?= h($activeTab) ?>" class="btn btn-light"><i class="bi bi-people"></i> Master Kategori</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (count($tabs) > 1): ?>
            <ul class="nav nav-tabs crf-master-tabs" role="tablist">
                <?php foreach ($tabs as $tabKey => $tab): ?>
                    <li class="nav-item" role="presentation">
                        <a
                            class="nav-link <?= $activeTab === $tabKey ? 'active' : '' ?>"
                            href="?tab=<?= h($tabKey) ?>"
                            data-bs-toggle="tab"
                            data-bs-target="#tab-<?= h($tabKey) ?>"
                            role="tab"
                            aria-controls="tab-<?= h($tabKey) ?>"
                            aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>"
                        >
                            <i class="bi <?= h($tab['icon']) ?>"></i> <?= h($tab['label']) ?>
                            <?php if ($tab['count'] > 0): ?>
                                <span class="badge rounded-pill text-bg-danger" title="Belum selesai"><?= (int) $tab['count'] ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="tab-content">
            <?php if ($canHelpdesk): ?>
                <div class="tab-pane fade <?= $activeTab === 'helpdesk' ? 'show active' : '' ?>" id="tab-helpdesk" role="tabpanel">
                    <div class="crf-kpi-grid mb-4">
                        <div class="crf-kpi-card crf-kpi-card--primary"><span class="crf-kpi-icon"><i class="bi bi-tags"></i></span><div><span class="crf-kpi-label">Kategori</span><strong><?= $helpdeskTotals['kategori'] ?></strong></div></div>
                        <div class="crf-kpi-card crf-kpi-card--info"><span class="crf-kpi-icon"><i class="bi bi-inbox"></i></span><div><span class="crf-kpi-label">Total Isi Helpdesk</span><strong><?= number_format($helpdeskTotals['total'], 0, ',', '.') ?></strong></div></div>
                        <div class="crf-kpi-card crf-kpi-card--success"><span class="crf-kpi-icon"><i class="bi bi-check-circle"></i></span><div><span class="crf-kpi-label">Sudah Selesai</span><strong><?= number_format($helpdeskTotals['selesai'], 0, ',', '.') ?></strong></div></div>
                        <div class="crf-kpi-card crf-kpi-card--danger"><span class="crf-kpi-icon"><i class="bi bi-exclamation-circle"></i></span><div><span class="crf-kpi-label">Belum Selesai</span><strong><?= number_format($helpdeskTotals['sisa'], 0, ',', '.') ?></strong></div></div>
                        <div class="crf-kpi-card crf-kpi-card--secondary"><span class="crf-kpi-icon"><i class="bi bi-slash-circle"></i></span><div><span class="crf-kpi-label">Dibatalkan</span><strong><?= number_format($helpdeskTotals['batal'], 0, ',', '.') ?></strong></div></div>
                    </div>

                    <?php if (!$helpdeskCards): ?>
                        <div class="crf-empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>Anda belum ditugaskan sebagai PIC kategori mana pun.</p>
                        </div>
                    <?php endif; ?>

                    <div class="crf-category-card-grid">
                        <?php foreach ($helpdeskCards as $card): ?>
                            <article class="crf-category-card">
                                <header>
                                    <span class="crf-category-icon"><i class="bi <?= h($card['icon']) ?>"></i></span>
                                    <div>
                                        <span class="crf-category-eyebrow">Kategori Helpdesk<?= $card['requires_crf'] ? ' · via CRF' : '' ?></span>
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
            <?php endif; ?>

            <?php if ($canCrf): ?>
                <div class="tab-pane fade <?= $activeTab === 'crf' ? 'show active' : '' ?>" id="tab-crf" role="tabpanel">
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
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
