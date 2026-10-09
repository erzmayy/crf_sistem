<?php
/**
 * admin/master_data.php
 * Master data Kategori CRF: nama, target SLA per level urgensi, dan PIC CRF.
 * Hapus kategori = soft delete; data CRF lama tetap utuh.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pdo = getConnection();

$crfCategoryRows = $pdo->query('
    SELECT c.*,
        (SELECT COUNT(*) FROM change_requests cr WHERE cr.crf_category_id = c.id) AS usage_count
    FROM crf_categories c
    WHERE c.deleted_at IS NULL
    ORDER BY c.sort_order, c.name
')->fetchAll();

$crfMembers = categoryMembers($pdo);
$totalHandler = (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM crf_category_handlers')->fetchColumn();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Kategori CRF';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">

        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">MASTER DATA · CRF</span>
                <h1>Kategori CRF</h1>
                <p>Kelola kategori CRF, SLA per level urgensi, dan PIC CRF.</p>
            </div>
            <div class="crf-banner-actions">
                <span class="crf-banner-pill"><i class="bi bi-bookmarks"></i> <?= count($crfCategoryRows) ?> Kategori CRF · <?= $totalHandler ?> PIC CRF</span>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="tab-crf" role="tabpanel">

                <div class="crf-master-toolbar">
                    <div class="crf-member-search">
                        <i class="bi bi-search"></i>
                        <input type="search" class="form-control form-control-sm" placeholder="Cari kategori..." data-master-filter="#tab-crf" aria-label="Cari kategori">
                    </div>
                    <button type="button" class="btn btn-sm btn-crf-primary" data-bs-toggle="modal" data-bs-target="#crfCategoryModal" data-category-form="{}">
                        <i class="bi bi-plus-circle"></i> Tambah Kategori
                    </button>
                </div>

                <?php if (!$crfCategoryRows): ?>
                    <div class="crf-empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>Belum ada kategori. Klik <strong>Tambah Kategori</strong>.</p>
                    </div>
                <?php endif; ?>

                <div class="crf-category-member-list">
                    <?php foreach ($crfCategoryRows as $i => $category): ?>
                        <?php
                        $categoryId = (int) $category['id'];
                        $formData = [
                            'id' => $categoryId,
                            'name' => $category['name'],
                            'description' => $category['description'],
                            'sla_value' => $category['sla_value'] !== null ? (float) $category['sla_value'] : '',
                            'sla_unit' => $category['sla_unit'],
                            'is_active' => (int) $category['is_active'],
                            'sla_tinggi_value' => $category['sla_tinggi_value'] !== null ? (float) $category['sla_tinggi_value'] : '',
                            'sla_tinggi_unit' => $category['sla_tinggi_unit'] ?? 'Hari',
                            'sla_rendah_value' => $category['sla_rendah_value'] !== null ? (float) $category['sla_rendah_value'] : '',
                            'sla_rendah_unit' => $category['sla_rendah_unit'] ?? 'Hari',
                            'legacy_change_category' => $category['legacy_change_category'],
                        ];
                        $slaMatrix = crfCategorySlaMatrix($category);
                        ?>
                        <section class="crf-category-member-row is-collapsed" id="crf-<?= $categoryId ?>" data-master-name="<?= h(mb_strtolower($category['name'])) ?>">
                            <div class="crf-category-member-head">
                                <span class="crf-member-index"><?= $i + 1 ?></span>
                                <span class="crf-category-icon"><i class="bi bi-bookmark"></i></span>
                                <div>
                                    <h2><?= h($category['name']) ?></h2>
                                    <span class="crf-banner-pill crf-banner-pill--soft">
                                        <i class="bi bi-people"></i> <?= count($crfMembers[$categoryId] ?? []) ?> PIC CRF
                                    </span>
                                    <?php if (!$category['is_active']): ?>
                                        <span class="crf-badge badge-status-draft">Nonaktif</span>
                                    <?php endif; ?>
                                    <div class="text-muted small mt-1">
                                        <?php if (!empty($category['description'])): ?><?= h($category['description']) ?><br><?php endif; ?>
                                        SLA<?php foreach ($slaMatrix as $slaLevel => $slaItem): ?>
                                            · <?= h($slaLevel) ?>: <?= $slaItem ? h(slaLabel($slaItem['value'], $slaItem['unit'])) : '-' ?>
                                        <?php endforeach; ?>
                                        · <?= (int) $category['usage_count'] ?> CRF
                                        · Laporan: <?= h($category['legacy_change_category']) ?>
                                    </div>
                                    <div class="d-flex gap-1 mt-2">
                                        <button type="button" class="btn btn-sm btn-crf-outline" data-master-toggle aria-expanded="false" title="Tampilkan / sembunyikan PIC CRF">
                                            <i class="bi bi-chevron-down"></i> PIC CRF
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-crf-outline"
                                            title="Edit kategori"
                                            data-bs-toggle="modal"
                                            data-bs-target="#crfCategoryModal"
                                            data-category-form="<?= h(json_encode($formData)) ?>"
                                            aria-label="Edit kategori <?= h($category['name']) ?>"
                                        >
                                            <i class="bi bi-pencil"></i><span class="visually-hidden-sm"> Edit</span>
                                        </button>
                                        <form method="POST" action="../actions/category_master.php" data-confirm="<?= $category['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> kategori <?= h($category['name']) ?>?">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="op" value="toggle">
                                            <input type="hidden" name="return" value="master_data.php">
                                            <input type="hidden" name="id" value="<?= $categoryId ?>">
                                            <button type="submit" class="btn btn-sm btn-crf-outline" aria-label="Ubah status aktif" title="<?= $category['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                <i class="bi <?= $category['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="../actions/category_master.php" data-confirm="Hapus kategori <?= h($category['name']) ?>?<?= (int) $category['usage_count'] > 0 ? ' Sudah dipakai ' . (int) $category['usage_count'] . ' CRF; data tersebut tetap tersimpan.' : '' ?>">
                                            <?= csrfField() ?>
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
                                $memberCategory = $category;
                                $memberList = $crfMembers[$categoryId] ?? [];
                                $memberReturn = 'master_data.php';
                                $memberLabel = 'PIC CRF';
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
        </div>
    </div>
</div>

<!-- Modal Kategori CRF -->
<div class="modal fade" id="crfCategoryModal" tabindex="-1" aria-labelledby="crfCategoryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="../actions/category_master.php" data-loading-form>
            <?= csrfField() ?>
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
                <div class="mb-3">
                    <label class="crf-field-label">Target SLA per Level Urgensi</label>
                    <div class="crf-readonly-note mb-2">
                        Terisi otomatis saat CRF lolos verifikasi CMO. Dihitung dengan hari kerja
                        (Sabtu, Minggu, dan libur tidak dihitung). Tinggi / Rendah kosong = ikut Normal.
                    </div>
                    <?php
                    $slaMatrixFields = [
                        'Tinggi' => ['sla_tinggi_value', 'sla_tinggi_unit'],
                        'Normal' => ['sla_value', 'sla_unit'],
                        'Rendah' => ['sla_rendah_value', 'sla_rendah_unit'],
                    ];
                    foreach ($slaMatrixFields as $slaLevel => [$slaValueField, $slaUnitField]): ?>
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-3">
                                <span class="crf-badge <?= h(levelBadgeClass($slaLevel)) ?>"><?= h($slaLevel) ?></span>
                            </div>
                            <div class="col-4">
                                <input type="number" class="form-control" name="<?= h($slaValueField) ?>" min="0" step="0.5" aria-label="Nilai SLA <?= h($slaLevel) ?>">
                            </div>
                            <div class="col-5">
                                <select class="form-select" name="<?= h($slaUnitField) ?>" aria-label="Satuan SLA <?= h($slaLevel) ?>">
                                    <option value="Hari">Hari</option>
                                    <option value="Jam">Jam</option>
                                    <option value="Menit">Menit</option>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="crfCategoryActive" name="is_active" value="1" checked>
                    <label class="form-check-label" for="crfCategoryActive">Aktif</label>
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
