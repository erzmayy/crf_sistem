<?php
/**
 * Partial: Biaya / Anggaran & Kategori Perubahan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *
 * Variabel OPSIONAL:
 *   int $sectionNumber   (default 4)
 */

$sectionNumber = $sectionNumber ?? 4;
?>
<div class="crf-section mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><?= h((string) $sectionNumber) ?></span>
        <h2>Biaya / Anggaran & Kategori Perubahan</h2>
    </div>

    <div class="crf-section-body">
        <div class="row g-4">

            <div class="col-md-6">
                <div class="crf-detail-label">BIAYA / ANGGARAN</div>
                <div class="crf-detail-value">
                    <?= h(budgetTypeLabel($crf['budget_type'] ?? null)) ?>
                    <?php if ($crf['budget_amount'] !== null && $crf['budget_amount'] !== ''): ?>
                        &mdash; <?= h(formatRupiah($crf['budget_amount'])) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="crf-detail-label">KATEGORI PERUBAHAN</div>
                <div class="crf-detail-value">
                    <?= h($crf['change_category'] ?? '-') ?>
                    <?php if (!empty($crf['change_category_detail'])): ?>
                        &mdash; <?= h($crf['change_category_detail']) ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>
<?php
unset($sectionNumber);
