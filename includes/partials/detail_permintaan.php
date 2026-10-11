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
$systemUrgencyLevel = crfUrgencyForImpact($crf['kategori_dampak'] ?? null)
    ?: ($crf['level'] ?? null);
$urgencyLevel = !empty($crf['level_urgensi_final'])
    ? $crf['level_urgensi_final']
    : $systemUrgencyLevel;
$urgencyLabel = !empty($crf['level_urgensi_final'])
    ? 'Level Urgensi Final'
    : 'Level Urgensi';
// Dokumen hasil UAT (category 'uat') ditampilkan terpisah dari lampiran pengajuan.
$requestAttachments = array_values(array_filter($attachments ?? [], static fn(array $file): bool => empty($file['kategori'])));
$uatDocuments = array_values(array_filter($attachments ?? [], static fn(array $file): bool => ($file['kategori'] ?? '') === 'uat'));
$attachmentIcon = static function (array $file): string {
    $ext = strtolower(pathinfo((string) ($file['nama_asli'] ?? ''), PATHINFO_EXTENSION));
    return match (true) {
        $ext === 'pdf' => 'bi-file-earmark-pdf text-danger',
        in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => 'bi-file-earmark-image text-info',
        in_array($ext, ['xls', 'xlsx', 'csv'], true) => 'bi-file-earmark-excel text-success',
        in_array($ext, ['doc', 'docx'], true) => 'bi-file-earmark-word text-primary',
        in_array($ext, ['zip', 'rar', '7z'], true) => 'bi-file-earmark-zip text-warning',
        default => 'bi-file-earmark text-secondary',
    };
};
$attachmentSize = static function (array $file): string {
    $bytes = (int) ($file['ukuran_file'] ?? 0);
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
};
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
                <div class="crf-info-value"><?= h($crf['jenis_pengajuan'] ?? '-') ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Rincian Permohonan Perubahan</span>
                <div class="crf-info-value"><?= nl2br(h($crf['deskripsi_perubahan'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Benefit dari Perubahan yang Diharapkan</span>
                <div class="crf-info-value"><?= nl2br(h($crf['manfaat'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Dampak Terpilih</span>
                <div class="crf-info-value">
                    <?= h(crfImpactLabel($crf['kategori_dampak'] ?? null) ?: '-') ?>
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
                <div class="crf-info-value"><?= nl2br(h($crf['dampak'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Alasan Permohonan Perubahan</span>
                <div class="crf-info-value"><?= nl2br(h($crf['alasan'] ?? '-')) ?></div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Bukti dan Informasi Pendukung</span>
                <div class="crf-info-value">
                    <?php if (empty($requestAttachments)): ?>
                        <span class="text-muted">Tidak ada file yang dilampirkan.</span>
                    <?php else: ?>
                        <div class="crf-attachment-list">
                            <?php foreach ($requestAttachments as $file): ?>
                                <div class="crf-attachment-row">
                                    <div class="crf-attachment-name">
                                        <i class="bi <?= h($attachmentIcon($file)) ?>"></i>
                                        <span title="<?= h($file['nama_asli'] ?? '-') ?>"><?= h($file['nama_asli'] ?? '-') ?></span>
                                        <small class="text-muted">
                                            <?= h($attachmentSize($file)) ?>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <?php if (!empty($file['path_file'])): ?>
                                            <a href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>&amp;inline=1" target="_blank" rel="noopener" class="btn btn-sm btn-crf-outline py-1 px-2">
                                                <i class="bi bi-eye"></i> Lihat
                                            </a>
                                        <?php endif; ?>
                                        <a href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>" class="btn btn-sm btn-crf-outline py-1 px-2" title="Unduh <?= h($file['nama_asli'] ?? '') ?>">
                                            <i class="bi bi-download"></i> Unduh
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($uatDocuments): ?>
                <div class="crf-info-row crf-request-row-long">
                    <span class="crf-info-label">Dokumen Hasil UAT</span>
                    <div class="crf-info-value">
                        <div class="crf-attachment-list">
                            <?php foreach ($uatDocuments as $file): ?>
                                <div class="crf-attachment-row">
                                    <div class="crf-attachment-name">
                                        <i class="bi <?= h($attachmentIcon($file)) ?>"></i>
                                        <span title="<?= h($file['nama_asli'] ?? '-') ?>"><?= h($file['nama_asli'] ?? '-') ?></span>
                                        <small class="text-muted">
                                            <?= h($attachmentSize($file)) ?>
                                            <?php if (!empty($file['tgl_unggah'])): ?>· <?= h(date('d-m-Y H:i', strtotime($file['tgl_unggah']))) ?><?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <?php if (!empty($file['path_file'])): ?>
                                            <a href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>&amp;inline=1" target="_blank" rel="noopener" class="btn btn-sm btn-crf-outline py-1 px-2">
                                                <i class="bi bi-eye"></i> Lihat
                                            </a>
                                        <?php endif; ?>
                                        <a href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>" class="btn btn-sm btn-crf-outline py-1 px-2" title="Unduh <?= h($file['nama_asli'] ?? '') ?>">
                                            <i class="bi bi-download"></i> Unduh
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="crf-info-row">
                <span class="crf-info-label">Biaya / Anggaran</span>
                <div class="crf-info-value">
                    <?= h(budgetTypeLabel($crf['jenis_anggaran'] ?? null)) ?>
                    <?php if (isset($crf['nilai_anggaran']) && $crf['nilai_anggaran'] !== ''): ?>
                        &mdash; <?= h(formatRupiah($crf['nilai_anggaran'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Kategori Perubahan</span>
                <div class="crf-info-value">
                    <?= h(crfCategoryName($crf)) ?>
                    <?php if (!empty($crf['detail_kategori_perubahan'])): ?>
                        &mdash; <?= h($crf['detail_kategori_perubahan']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="crf-info-row crf-request-row-long">
                <span class="crf-info-label">Saran Alternatif</span>
                <div class="crf-info-value"><?= nl2br(h($crf['saran_alternatif'] ?? '-')) ?></div>
            </div>
        </div>
    </div>
</div>
<?php
unset($sectionTitle, $attachmentIcon, $attachmentSize);
unset($urgencyLevel);
unset($systemUrgencyLevel, $urgencyLabel);
