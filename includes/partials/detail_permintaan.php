<?php
/**
 * Partial: Detail Permintaan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *
 * Variabel OPSIONAL:
 *   int    $sectionNumber   (default 2)
 *   string $sectionTitle    (default 'Detail Permintaan')
 */

$sectionNumber = $sectionNumber ?? 2;
$sectionTitle  = $sectionTitle ?? 'Detail Permintaan';
?>
<div class="crf-section mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><?= h((string) $sectionNumber) ?></span>
        <h2><?= h($sectionTitle) ?></h2>
    </div>

    <div class="crf-section-body">

        <div class="crf-detail-label">Rincian Permohonan Perubahan</div>
        <div class="crf-detail-value mb-3">
            <?= nl2br(h($crf['change_description'] ?? '-')) ?>
        </div>

        <div class="crf-detail-label">Benefit dari Perubahan yang Diharapkan</div>
        <div class="crf-detail-value mb-3">
            <?= nl2br(h($crf['benefit'] ?? '-')) ?>
        </div>

        <div class="crf-detail-label">Dampak Jika Tidak Dilakukan Perubahan</div>
        <div class="crf-detail-value mb-3">
            <?= nl2br(h($crf['impact'] ?? '-')) ?>
        </div>

        <div class="crf-detail-label">Alasan Permohonan Perubahan</div>
        <div class="crf-detail-value">
            <?= nl2br(h($crf['reason'] ?? '-')) ?>
        </div>

    </div>
</div>
<?php
unset($sectionNumber, $sectionTitle);
