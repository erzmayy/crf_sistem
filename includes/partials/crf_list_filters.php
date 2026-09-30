<?php
/**
 * Shared search and filter bar for CRF lists.
 *
 * Required variables: $listFilters, $listContextName, $listContextValue.
 * Optional: $listResetUrl.
 */

$listResetUrl = $listResetUrl ?? basename($_SERVER['PHP_SELF'] ?? '');
?>
<form method="GET" class="crf-list-filters <?= !empty($listCategories) ? 'crf-list-filters-with-category' : '' ?>">
    <?php if (!empty($listContextName)): ?>
        <input type="hidden" name="<?= h($listContextName) ?>" value="<?= h($listContextValue ?? '') ?>">
    <?php endif; ?>
    <input type="hidden" name="per_page" value="<?= (int) ($perPage ?? 6) ?>">

    <input
        type="search"
        name="q"
        class="form-control crf-list-search"
        placeholder="Cari Nama, No CRF, atau Judul..."
        value="<?= h($listFilters['search'] ?? '') ?>"
        aria-label="Cari berdasarkan nama, nomor CRF, atau judul"
    >

    <select name="status" class="form-select" aria-label="Filter status">
        <option value="">Semua Status</option>
        <?php foreach (['Belum Ditindak Lanjuti', 'Perlu Revisi', 'Dalam Proses', 'Solve', 'Cancel'] as $statusOption): ?>
            <option value="<?= h($statusOption) ?>" <?= ($listFilters['status'] ?? '') === $statusOption ? 'selected' : '' ?>>
                <?= h(statusLabel($statusOption)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="department" class="form-select" aria-label="Filter departemen">
        <option value="">Semua Departemen</option>
        <?php foreach (($listFilters['departments'] ?? []) as $departmentOption): ?>
            <option value="<?= h($departmentOption) ?>" <?= ($listFilters['department'] ?? '') === $departmentOption ? 'selected' : '' ?>>
                <?= h($departmentOption) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="level" class="form-select" aria-label="Filter level urgensi">
        <option value="">Level Urgensi</option>
        <?php foreach (['Tinggi', 'Normal', 'Rendah'] as $levelOption): ?>
            <option value="<?= h($levelOption) ?>" <?= ($listFilters['level'] ?? '') === $levelOption ? 'selected' : '' ?>>
                <?= h($levelOption === 'Normal' ? 'Sedang' : $levelOption) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (!empty($listCategories)): ?>
        <select name="category" class="form-select" aria-label="Filter kategori">
            <option value="">Semua Kategori</option>
            <?php foreach ($listCategories as $categoryOption): ?>
                <option value="<?= h($categoryOption) ?>" <?= ($listCategoryValue ?? '') === $categoryOption ? 'selected' : '' ?>>
                    <?= h($categoryOption) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <label class="crf-list-date">
        <span>Dari</span>
        <input type="date" name="date_from" class="form-control" value="<?= h($listFilters['date_from'] ?? '') ?>" aria-label="Tanggal pengajuan dari">
    </label>

    <label class="crf-list-date">
        <span>Sampai</span>
        <input type="date" name="date_to" class="form-control" value="<?= h($listFilters['date_to'] ?? '') ?>" aria-label="Tanggal pengajuan sampai">
    </label>

    <button type="submit" class="btn btn-crf-primary" aria-label="Terapkan filter">
        <i class="bi bi-search"></i>
        <span class="d-md-none">Terapkan</span>
    </button>

    <a href="<?= h($listResetUrl) ?><?= !empty($listContextName) ? '?' . h(http_build_query([$listContextName => $listContextValue])) : '' ?>" class="btn btn-crf-outline">
        Reset
    </a>
</form>
<?php
unset($listResetUrl);
