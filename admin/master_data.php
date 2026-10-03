<?php
/**
 * admin/master_data.php
 * Satu halaman master data kategori:
 *   - Tab Helpdesk : kategori Helpdesk + PIC (+ penanda "Butuh CRF")
 *   - Tab CRF      : kategori CRF + target SLA + Handler (Handling Kategori)
 * Hapus kategori = soft delete; data ticket/CRF lama tetap utuh.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pdo = getConnection();
$activeTab = ($_GET['tab'] ?? 'helpdesk') === 'crf' ? 'crf' : 'helpdesk';

$helpdeskCategoryRows = $pdo->query('
    SELECT hc.*, cc.name AS crf_category_name,
        (SELECT COUNT(*) FROM helpdesk_tickets t WHERE t.helpdesk_category_id = hc.id) AS usage_count
    FROM helpdesk_categories hc
    LEFT JOIN crf_categories cc ON cc.id = hc.default_crf_category_id
    WHERE hc.deleted_at IS NULL
    ORDER BY hc.sort_order, hc.name
')->fetchAll();

$crfCategoryRows = $pdo->query('
    SELECT c.*,
        (SELECT COUNT(*) FROM change_requests cr WHERE cr.crf_category_id = c.id) AS usage_count
    FROM crf_categories c
    WHERE c.deleted_at IS NULL
    ORDER BY c.sort_order, c.name
')->fetchAll();

$helpdeskMembers = categoryMembers($pdo, 'helpdesk_category_pics', 'helpdesk_category_id');
$crfMembers = categoryMembers($pdo, 'crf_category_handlers', 'crf_category_id');
$totalPic = (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM helpdesk_category_pics')->fetchColumn();
$totalHandler = (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM crf_category_handlers')->fetchColumn();
$crfCategoryOptions = crfCategories($pdo);

$tabs = [
    'helpdesk' => [
        'label' => 'Kategori Helpdesk & PIC',
        'icon' => 'bi-headset',
        'rows' => $helpdeskCategoryRows,
        'members' => $helpdeskMembers,
        'member_label' => 'PIC',
        'modal' => '#helpdeskCategoryModal',
        'usage_label' => 'ticket',
    ],
    'crf' => [
        'label' => 'Kategori CRF & Handler',
        'icon' => 'bi-bookmarks',
        'rows' => $crfCategoryRows,
        'members' => $crfMembers,
        'member_label' => 'Handler',
        'modal' => '#crfCategoryModal',
        'usage_label' => 'CRF',
    ],
];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Master Data Kategori';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">

        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">MASTER DATA</span>
                <h1>Kategori &amp; Handling</h1>
                <p>Kelola kategori Helpdesk beserta PIC, dan kategori CRF beserta Handler, dalam satu halaman.</p>
            </div>
            <div class="crf-banner-actions">
                <span class="crf-banner-pill"><i class="bi bi-headset"></i> <?= count($helpdeskCategoryRows) ?> Kategori Helpdesk · <?= $totalPic ?> PIC</span>
                <span class="crf-banner-pill"><i class="bi bi-bookmarks"></i> <?= count($crfCategoryRows) ?> Kategori CRF · <?= $totalHandler ?> Handler</span>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="crf-master-flow">
            <i class="bi bi-info-circle"></i>
            Alur: <strong>Kategori Helpdesk</strong> bertanda <span class="crf-badge badge-stage-otomasi">Butuh CRF</span>
            meneruskan Permintaan Baru ke Form CRF dengan <strong>Kategori CRF</strong> default, lalu CRF diproses oleh
            <strong>Handler</strong> kategori tersebut.
        </div>

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
                        <span class="badge rounded-pill text-bg-light"><?= count($tab['rows']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content">
            <?php foreach ($tabs as $tabKey => $tab): ?>
                <div class="tab-pane fade <?= $activeTab === $tabKey ? 'show active' : '' ?>" id="tab-<?= h($tabKey) ?>" role="tabpanel">

                    <div class="crf-master-toolbar">
                        <div class="crf-member-search">
                            <i class="bi bi-search"></i>
                            <input type="search" class="form-control form-control-sm" placeholder="Cari kategori..." data-master-filter="#tab-<?= h($tabKey) ?>" aria-label="Cari kategori">
                        </div>
                        <button type="button" class="btn btn-sm btn-crf-primary" data-bs-toggle="modal" data-bs-target="<?= h($tab['modal']) ?>" data-category-form="{}">
                            <i class="bi bi-plus-circle"></i> Tambah Kategori
                        </button>
                    </div>

                    <?php if (!$tab['rows']): ?>
                        <div class="crf-empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>Belum ada kategori. Klik <strong>Tambah Kategori</strong>.</p>
                        </div>
                    <?php endif; ?>

                    <div class="crf-category-member-list">
                        <?php foreach ($tab['rows'] as $i => $category): ?>
                            <?php
                            $categoryId = (int) $category['id'];
                            $formData = [
                                'id' => $categoryId,
                                'name' => $category['name'],
                                'description' => $category['description'],
                                'sla_value' => $category['sla_value'] !== null ? (float) $category['sla_value'] : '',
                                'sla_unit' => $category['sla_unit'],
                                'sort_order' => (int) $category['sort_order'],
                                'is_active' => (int) $category['is_active'],
                            ];
                            if ($tabKey === 'helpdesk') {
                                $formData += [
                                    'icon' => $category['icon'],
                                    'requires_crf' => (int) $category['requires_crf'],
                                    'default_crf_category_id' => $category['default_crf_category_id'] !== null ? (int) $category['default_crf_category_id'] : '',
                                ];
                            } else {
                                $formData['legacy_change_category'] = $category['legacy_change_category'];
                            }
                            ?>
                            <section class="crf-category-member-row" id="<?= h($tabKey) ?>-<?= $categoryId ?>" data-master-name="<?= h(mb_strtolower($category['name'])) ?>">
                                <div class="crf-category-member-head">
                                    <span class="crf-member-index"><?= $i + 1 ?></span>
                                    <span class="crf-category-icon"><i class="bi <?= h($tabKey === 'helpdesk' ? $category['icon'] : 'bi-bookmark') ?>"></i></span>
                                    <div>
                                        <h2><?= h($category['name']) ?></h2>
                                        <span class="crf-banner-pill crf-banner-pill--soft">
                                            <i class="bi bi-people"></i> <?= count($tab['members'][$categoryId] ?? []) ?> <?= h($tab['member_label']) ?>
                                        </span>
                                        <?php if ($tabKey === 'helpdesk' && $category['requires_crf']): ?>
                                            <span class="crf-badge badge-stage-otomasi" title="Permintaan baru diteruskan ke Form CRF">
                                                <i class="bi bi-arrow-right-circle"></i> Butuh CRF<?= $category['crf_category_name'] ? ' · ' . h($category['crf_category_name']) : '' ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!$category['is_active']): ?>
                                            <span class="crf-badge badge-status-draft">Nonaktif</span>
                                        <?php endif; ?>
                                        <div class="text-muted small mt-1">
                                            <?php if (!empty($category['description'])): ?><?= h($category['description']) ?><br><?php endif; ?>
                                            SLA <?= h(slaLabel($category['sla_value'], $category['sla_unit'])) ?>
                                            · <?= (int) $category['usage_count'] ?> <?= h($tab['usage_label']) ?>
                                            <?php if ($tabKey === 'crf'): ?>
                                                · Laporan: <?= h($category['legacy_change_category']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-1 mt-2">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-crf-outline"
                                                data-bs-toggle="modal"
                                                data-bs-target="<?= h($tab['modal']) ?>"
                                                data-category-form="<?= h(json_encode($formData)) ?>"
                                                aria-label="Edit kategori <?= h($category['name']) ?>"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" action="../actions/category_master.php" data-confirm="<?= $category['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> kategori <?= h($category['name']) ?>?">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="type" value="<?= h($tabKey) ?>">
                                                <input type="hidden" name="op" value="toggle">
                                                <input type="hidden" name="return" value="master_data.php">
                                                <input type="hidden" name="id" value="<?= $categoryId ?>">
                                                <button type="submit" class="btn btn-sm btn-crf-outline" aria-label="Ubah status aktif" title="<?= $category['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                    <i class="bi <?= $category['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="../actions/category_master.php" data-confirm="Hapus kategori <?= h($category['name']) ?>?<?= (int) $category['usage_count'] > 0 ? ' Sudah dipakai ' . (int) $category['usage_count'] . ' ' . $tab['usage_label'] . '; data tersebut tetap tersimpan.' : '' ?>">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="type" value="<?= h($tabKey) ?>">
                                                <input type="hidden" name="op" value="delete">
                                                <input type="hidden" name="return" value="master_data.php">
                                                <input type="hidden" name="id" value="<?= $categoryId ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Hapus kategori" title="Hapus">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="crf-category-member-body">
                                    <?php
                                    $memberType = $tabKey;
                                    $memberCategory = $category;
                                    $memberList = $tab['members'][$categoryId] ?? [];
                                    $memberReturn = 'master_data.php';
                                    $memberLabel = $tab['member_label'];
                                    require __DIR__ . '/../includes/partials/category_members.php';
                                    ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                        <div class="crf-empty-state d-none" data-master-empty>
                            <i class="bi bi-search"></i>
                            <p>Tidak ada kategori yang cocok.</p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal Kategori Helpdesk -->
<div class="modal fade" id="helpdeskCategoryModal" tabindex="-1" aria-labelledby="helpdeskCategoryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="../actions/category_master.php" data-loading-form>
            <?= csrfField() ?>
            <input type="hidden" name="type" value="helpdesk">
            <input type="hidden" name="op" value="save">
            <input type="hidden" name="return" value="master_data.php">
            <input type="hidden" name="id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="helpdeskCategoryModalTitle" data-title-new="Tambah Kategori Helpdesk" data-title-edit="Edit Kategori Helpdesk">Kategori Helpdesk</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="crf-field-label" for="hdCategoryName">Nama Kategori<span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="hdCategoryName" name="name" maxlength="100" required>
                </div>
                <div class="mb-3">
                    <label class="crf-field-label" for="hdCategoryDescription">Deskripsi</label>
                    <input type="text" class="form-control" id="hdCategoryDescription" name="description" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="crf-field-label" for="hdCategoryIcon">Ikon (Bootstrap Icons)</label>
                    <input type="text" class="form-control" id="hdCategoryIcon" name="icon" value="bi-tag" placeholder="bi-tag">
                </div>
                <div class="crf-requires-crf-box mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="hdRequiresCrf" name="requires_crf" value="1">
                        <label class="form-check-label" for="hdRequiresCrf">
                            <strong>Butuh CRF</strong> — Permintaan Baru diteruskan ke Form CRF
                        </label>
                    </div>
                    <label class="crf-field-label mt-2" for="hdDefaultCrfCategory">Kategori CRF default</label>
                    <select class="form-select" id="hdDefaultCrfCategory" name="default_crf_category_id">
                        <option value="">— Pemohon memilih sendiri —</option>
                        <?php foreach ($crfCategoryOptions as $crfCategory): ?>
                            <option value="<?= (int) $crfCategory['id'] ?>"><?= h($crfCategory['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="crf-field-label" for="hdCategorySla">Target SLA</label>
                        <input type="number" class="form-control" id="hdCategorySla" name="sla_value" min="0" step="0.5">
                    </div>
                    <div class="col-6">
                        <label class="crf-field-label" for="hdCategorySlaUnit">Satuan</label>
                        <select class="form-select" id="hdCategorySlaUnit" name="sla_unit">
                            <option value="Jam">Jam</option>
                            <option value="Hari">Hari</option>
                            <option value="Menit">Menit</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-6">
                        <label class="crf-field-label" for="hdCategorySort">Urutan</label>
                        <input type="number" class="form-control" id="hdCategorySort" name="sort_order" value="0">
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="hdCategoryActive" name="is_active" value="1" checked>
                            <label class="form-check-label" for="hdCategoryActive">Aktif</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-crf-outline" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-crf-primary"><i class="bi bi-save"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Kategori CRF -->
<div class="modal fade" id="crfCategoryModal" tabindex="-1" aria-labelledby="crfCategoryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="../actions/category_master.php" data-loading-form>
            <?= csrfField() ?>
            <input type="hidden" name="type" value="crf">
            <input type="hidden" name="op" value="save">
            <input type="hidden" name="return" value="master_data.php">
            <input type="hidden" name="id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="crfCategoryModalTitle" data-title-new="Tambah Kategori CRF" data-title-edit="Edit Kategori CRF">Kategori CRF</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="crf-field-label" for="crfCategoryName">Nama Kategori<span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="crfCategoryName" name="name" maxlength="100" required>
                </div>
                <div class="mb-3">
                    <label class="crf-field-label" for="crfCategoryDescription">Deskripsi</label>
                    <input type="text" class="form-control" id="crfCategoryDescription" name="description" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="crf-field-label" for="crfCategoryLegacy">Kelompok Laporan</label>
                    <select class="form-select" id="crfCategoryLegacy" name="legacy_change_category">
                        <?php foreach (['Aplikasi', 'Infrastruktur', 'Proses', 'Security', 'Lainnya'] as $legacy): ?>
                            <option value="<?= h($legacy) ?>"><?= h($legacy) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="crf-readonly-note">Dipakai untuk export laporan &amp; PDF CRF.</div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="crf-field-label" for="crfCategorySla">Target SLA</label>
                        <input type="number" class="form-control" id="crfCategorySla" name="sla_value" min="0" step="0.5">
                    </div>
                    <div class="col-6">
                        <label class="crf-field-label" for="crfCategorySlaUnit">Satuan</label>
                        <select class="form-select" id="crfCategorySlaUnit" name="sla_unit">
                            <option value="Hari">Hari</option>
                            <option value="Jam">Jam</option>
                            <option value="Menit">Menit</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-6">
                        <label class="crf-field-label" for="crfCategorySort">Urutan</label>
                        <input type="number" class="form-control" id="crfCategorySort" name="sort_order" value="0">
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="crfCategoryActive" name="is_active" value="1" checked>
                            <label class="form-check-label" for="crfCategoryActive">Aktif</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-crf-outline" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-crf-primary"><i class="bi bi-save"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
