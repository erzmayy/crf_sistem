<?php
/**
 * Partial: Change Request Action (Saran Alternatif).
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *
 * Variabel OPSIONAL:
 *   int $sectionNumber   (default 5)
 */

$sectionNumber = $sectionNumber ?? 5;
?>
<div class="crf-section mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><?= h((string) $sectionNumber) ?></span>
        <h2>Change Request Action</h2>
    </div>

    <div class="crf-section-body">
        <div class="crf-detail-label">SARAN ALTERNATIF</div>
        <div class="crf-detail-value">
            <?php if (!empty($crf['alternative_suggestion'])): ?>
                <?= nl2br(h($crf['alternative_suggestion'])) ?>
            <?php else: ?>
                <span class="text-muted">Belum ada saran alternatif.</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
unset($sectionNumber);
