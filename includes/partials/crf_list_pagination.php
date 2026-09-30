<?php
/**
 * Shared compact pagination footer for CRF lists.
 *
 * Required variables: $page, $totalPages, $totalRows, $perPage, $offset.
 * Optional: $paginationLabel (accessible navigation label).
 */

$paginationLabel = $paginationLabel ?? 'Navigasi halaman daftar CRF';
$rangeStart = $totalRows > 0 ? $offset + 1 : 0;
$rangeEnd = min($offset + $perPage, $totalRows);
$pageStart = max(1, min($page - 1, $totalPages - 2));
$pageEnd = min($totalPages, $pageStart + 2);
$pageParams = $_GET;
?>
<div class="crf-list-pagination">
    <p class="crf-list-count">
        Menampilkan <?= number_format($rangeStart, 0, ',', '.') ?>–<?= number_format($rangeEnd, 0, ',', '.') ?> dari <?= number_format($totalRows, 0, ',', '.') ?> entri
    </p>
    <form method="GET" class="crf-list-page-size">
        <?php foreach ($_GET as $parameterName => $parameterValue): ?>
            <?php if (!in_array($parameterName, ['page', 'per_page'], true) && is_scalar($parameterValue)): ?>
                <input type="hidden" name="<?= h((string) $parameterName) ?>" value="<?= h((string) $parameterValue) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <label for="crf-page-size">Baris per halaman</label>
        <select id="crf-page-size" name="per_page" class="form-select form-select-sm">
            <?php foreach ([6, 10, 20, 50] as $pageSizeOption): ?>
                <option value="<?= $pageSizeOption ?>" <?= $perPage === $pageSizeOption ? 'selected' : '' ?>>
                    <?= $pageSizeOption ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-crf-outline">Terapkan</button>
    </form>
    <nav aria-label="<?= h($paginationLabel) ?>">
        <ul class="pagination mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <?php if ($page <= 1): ?>
                    <span class="page-link">Sebelumnya</span>
                <?php else: ?>
                    <?php $pageParams['page'] = $page - 1; ?>
                    <a class="page-link" href="?<?= h(http_build_query($pageParams)) ?>">Sebelumnya</a>
                <?php endif; ?>
            </li>

            <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
                    <?php if ($pageNumber === $page): ?>
                        <span class="page-link" aria-current="page"><?= $pageNumber ?></span>
                    <?php else: ?>
                        <?php $pageParams['page'] = $pageNumber; ?>
                        <a class="page-link" href="?<?= h(http_build_query($pageParams)) ?>"><?= $pageNumber ?></a>
                    <?php endif; ?>
                </li>
            <?php endfor; ?>

            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <?php if ($page >= $totalPages): ?>
                    <span class="page-link">Selanjutnya</span>
                <?php else: ?>
                    <?php $pageParams['page'] = $page + 1; ?>
                    <a class="page-link" href="?<?= h(http_build_query($pageParams)) ?>">Selanjutnya</a>
                <?php endif; ?>
            </li>
        </ul>
    </nav>
</div>
<?php
unset($paginationLabel, $rangeStart, $rangeEnd, $pageStart, $pageEnd, $pageParams, $pageNumber, $parameterName, $parameterValue, $pageSizeOption);
