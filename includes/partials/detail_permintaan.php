<?php
/**
 * Partial: Detail Permintaan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *   array $attachments
 *
 * Variabel OPSIONAL:
 *   int    $sectionNumber   (default 2)
 *   string $sectionTitle    (default 'Detail Pengajuan')
 */

$sectionNumber = $sectionNumber ?? 2;
$sectionTitle  = $sectionTitle ?? 'Detail Pengajuan';
?>
<div class="crf-section crf-detail-card mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><?= h((string) $sectionNumber) ?></span>
        <h2><?= h($sectionTitle) ?></h2>
    </div>

    <div class="crf-section-body">

        <div class="crf-detail-grid">
            <div class="crf-detail-item crf-detail-item-wide">
                <div class="crf-detail-label">Rincian Permohonan Perubahan</div>
                <div class="crf-detail-value">
                    <?= nl2br(h($crf['change_description'] ?? '-')) ?>
                </div>
            </div>

            <div class="crf-detail-item">
                <div class="crf-detail-label">Benefit dari Perubahan yang Diharapkan</div>
                <div class="crf-detail-value">
                    <?= nl2br(h($crf['benefit'] ?? '-')) ?>
                </div>
            </div>

            <div class="crf-detail-item">
                <div class="crf-detail-label">Dampak Jika Tidak Dilakukan Perubahan</div>
                <div class="crf-detail-value">
                    <?= nl2br(h($crf['impact'] ?? '-')) ?>
                </div>
            </div>

            <div class="crf-detail-item crf-detail-item-wide">
                <div class="crf-detail-label">Alasan Permohonan Perubahan</div>
                <div class="crf-detail-value">
                    <?= nl2br(h($crf['reason'] ?? '-')) ?>
                </div>
            </div>
        </div>

        <div class="crf-detail-item crf-detail-item-wide crf-detail-item-attachments">
            <div class="crf-detail-label">Bukti dan Informasi Pendukung</div>
            <div class="crf-detail-value">
                <?php if (empty($attachments)): ?>
                    <span class="text-muted">Tidak ada file yang dilampirkan.</span>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($attachments as $file): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-3 py-2 px-3">
                                <div class="d-flex align-items-center gap-2 flex-grow-1 min-width-0">
                                    <i class="bi bi-paperclip text-primary"></i>
                                    <div class="text-truncate">
                                        <div class="fw-medium text-truncate">
                                            <?= h($file['original_name'] ?? '-') ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= round(((int) ($file['file_size'] ?? 0)) / 1024) ?> KB
                                        </small>
                                    </div>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <?php if (!empty($file['file_path'])): ?>
                                        <a
                                            href="<?= h($file['file_path']) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="btn btn-sm btn-crf-outline py-1 px-2"
                                        >
                                            <i class="bi bi-eye"></i> Lihat
                                        </a>
                                    <?php endif; ?>
                                    <a
                                        href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>"
                                        class="btn btn-sm btn-crf-primary py-1 px-2"
                                    >
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="crf-detail-grid crf-detail-grid-secondary">
            <div class="crf-detail-item">
                <div class="crf-detail-label">Biaya / Anggaran</div>
                <div class="crf-detail-value">
                    <?= h(budgetTypeLabel($crf['budget_type'] ?? null)) ?>
                    <?php if (isset($crf['budget_amount']) && $crf['budget_amount'] !== ''): ?>
                        &mdash; <?= h(formatRupiah($crf['budget_amount'])) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="crf-detail-item">
                <div class="crf-detail-label">Kategori Perubahan</div>
                <div class="crf-detail-value">
                    <?= h($crf['change_category'] ?? '-') ?>
                    <?php if (!empty($crf['change_category_detail'])): ?>
                        &mdash; <?= h($crf['change_category_detail']) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="crf-detail-item crf-detail-item-wide">
                <div class="crf-detail-label">Saran Alternatif</div>
                <div class="crf-detail-value">
                    <?= nl2br(h($crf['alternative_suggestion'] ?? '-')) ?>
                </div>
            </div>
        </div>

    </div>
</div>
<?php
unset($sectionNumber, $sectionTitle);
