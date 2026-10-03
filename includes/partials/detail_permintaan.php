<?php
/**
 * Partial: Detail Pengajuan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *   array $attachments
 *
 * Variabel OPSIONAL:
 *   string $sectionTitle
 */

$sectionTitle = $sectionTitle ?? 'Detail Pengajuan';
$systemUrgencyLevel = crfUrgencyForImpact($crf['impact_category'] ?? null)
    ?: ($crf['level'] ?? null);
$urgencyLevel = !empty($crf['final_urgency_level'])
    ? $crf['final_urgency_level']
    : $systemUrgencyLevel;
$urgencyLabel = !empty($crf['final_urgency_level'])
    ? 'Level Urgensi Final'
    : 'Level Urgensi';
?>
<div class="crf-section crf-detail-card mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-card-checklist"></i></span>
        <h2><?= h($sectionTitle) ?></h2>
    </div>

    <div class="crf-section-body">
        <div class="crf-info-rows">
            <div class="crf-info-row">
                <span class="crf-info-label">Tipe Pengajuan</span>
                <div class="crf-info-value"><?= h($crf['request_type'] ?? '-') ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Rincian Permohonan Perubahan</span>
                <div class="crf-info-value"><?= nl2br(h($crf['change_description'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Benefit dari Perubahan yang Diharapkan</span>
                <div class="crf-info-value"><?= nl2br(h($crf['benefit'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Dampak Terpilih</span>
                <div class="crf-info-value">
                    <?= h(crfImpactLabel($crf['impact_category'] ?? null) ?: '-') ?>
                </div>
            </div>
            <div class="crf-info-row crf-urgency-detail-row">
                <span class="crf-info-label"><?= h($urgencyLabel) ?></span>
                <div class="crf-info-value crf-urgency-detail-value">
                    <?php if ($urgencyLevel !== null): ?>
                        <span class="crf-badge crf-urgency-detail-badge <?= h(levelBadgeClass($urgencyLevel)) ?>">
                            <?= h($urgencyLevel) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">Belum ditentukan.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Penjelasan Dampak yang Dipilih</span>
                <div class="crf-info-value"><?= nl2br(h($crf['impact'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Alasan Permohonan Perubahan</span>
                <div class="crf-info-value"><?= nl2br(h($crf['reason'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Bukti dan Informasi Pendukung</span>
                <div class="crf-info-value">
                    <?php if (empty($attachments)): ?>
                        <span class="text-muted">Tidak ada file yang dilampirkan.</span>
                    <?php else: ?>
                        <div class="crf-attachment-list">
                            <?php foreach ($attachments as $file): ?>
                                <div class="crf-attachment-row">
                                    <div class="crf-attachment-name">
                                        <i class="bi bi-paperclip text-primary"></i>
                                        <span><?= h($file['original_name'] ?? '-') ?></span>
                                        <small class="text-muted">
                                            <?= round(((int) ($file['file_size'] ?? 0)) / 1024) ?> KB
                                        </small>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <?php if (!empty($file['file_path'])): ?>
                                            <a href="<?= h($file['file_path']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-crf-outline py-1 px-2">
                                                <i class="bi bi-eye"></i> Lihat
                                            </a>
                                        <?php endif; ?>
                                        <a href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>" class="btn btn-sm btn-crf-primary py-1 px-2">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Biaya / Anggaran</span>
                <div class="crf-info-value">
                    <?= h(budgetTypeLabel($crf['budget_type'] ?? null)) ?>
                    <?php if (isset($crf['budget_amount']) && $crf['budget_amount'] !== ''): ?>
                        &mdash; <?= h(formatRupiah($crf['budget_amount'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Kategori Perubahan</span>
                <div class="crf-info-value">
                    <?= h(crfCategoryName($crf)) ?>
                    <?php if (!empty($crf['change_category_detail'])): ?>
                        &mdash; <?= h($crf['change_category_detail']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Saran Alternatif</span>
                <div class="crf-info-value"><?= nl2br(h($crf['alternative_suggestion'] ?? '-')) ?></div>
            </div>
        </div>
    </div>
</div>
<?php
unset($sectionTitle);
unset($urgencyLevel);
unset($systemUrgencyLevel, $urgencyLabel);
