<?php
/**
 * Shared search and filter bar for CRF lists.
 *
 * Required variables: $listFilters, $listContextName, $listContextValue.
 * Optional: $listResetUrl.
 */

$listResetUrl = $listResetUrl ?? basename($_SERVER['PHP_SELF'] ?? '');
?>
<form method="GET" class="crf-list-filters <?= !empty($listFilters['categories']) ? 'crf-list-filters-with-category' : '' ?>">
    <?php if (!empty($listContextName)): ?>
        <input type="hidden" name="<?= h($listContextName) ?>" value="<?= h($listContextValue ?? '') ?>">
    <?php endif; ?>
    <input type="hidden" name="per_page" value="<?= (int) ($perPage ?? 6) ?>">
    <?php foreach (($listCarryParams ?? []) as $carryName => $carryValue): ?>
        <input type="hidden" name="<?= h((string) $carryName) ?>" value="<?= h((string) $carryValue) ?>">
    <?php endforeach; ?>

    <label class="crf-list-filter-field crf-list-search">
        <span>Cari Pengajuan</span>
        <input
            type="search"
            name="q"
            class="form-control"
            placeholder="Nama, nomor register, atau isi..."
            value="<?= h($listFilters['search'] ?? '') ?>"
            aria-label="Cari berdasarkan nama, nomor CRF, atau judul"
        >
    </label>

    <label class="crf-list-filter-field">
        <span>Status</span>
        <select name="status" class="form-select" aria-label="Filter status">
            <option value="">Semua Status</option>
            <?php foreach (['Belum Ditindak Lanjuti', 'Perlu Revisi', 'Dalam Proses', 'Solve', 'Cancel'] as $statusOption): ?>
                <option value="<?= h($statusOption) ?>" <?= ($listFilters['status'] ?? '') === $statusOption ? 'selected' : '' ?>>
                    <?= h(statusLabel($statusOption)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="crf-list-filter-field">
        <span>Level Urgensi</span>
        <select name="level" class="form-select" aria-label="Filter level urgensi">
            <option value="">Semua Level</option>
            <?php foreach (['Tinggi', 'Normal', 'Rendah'] as $levelOption): ?>
                <option value="<?= h($levelOption) ?>" <?= ($listFilters['level'] ?? '') === $levelOption ? 'selected' : '' ?>>
                    <?= h($levelOption) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <?php if (!empty($listFilters['categories'])): ?>
        <label class="crf-list-filter-field">
            <span>Kategori CRF</span>
            <select name="category_id" class="form-select" aria-label="Filter kategori CRF">
                <option value="">Semua Kategori</option>
                <?php foreach ($listFilters['categories'] as $categoryOption): ?>
                    <option value="<?= (int) $categoryOption['id'] ?>" <?= (int) ($listFilters['category_id'] ?? 0) === (int) $categoryOption['id'] ? 'selected' : '' ?>>
                        <?= h($categoryOption['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>

    <div class="crf-list-filter-actions">
        <button type="submit" class="btn btn-crf-primary" aria-label="Terapkan filter">
            <i class="bi bi-search"></i>
            <span>Terapkan</span>
        </button>
        <a href="<?= h($listResetUrl) ?><?= !empty($listContextName) ? '?' . h(http_build_query([$listContextName => $listContextValue])) : '' ?>" class="btn btn-crf-outline">
            Reset
        </a>
    </div>
</form>
<?php
unset($listResetUrl);
