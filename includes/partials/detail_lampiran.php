<?php
/**
 * Partial: Bukti dan Informasi Pendukung (daftar lampiran).
 * Path download diasumsikan dipanggil dari file satu level di bawah
 * root (admin/, cmo/, otomasi/, kadep_operasional/, user/), sesuai struktur
 * project saat ini.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $attachments   hasil query dari tabel attachments
 *
 * Variabel OPSIONAL:
 *   int    $sectionNumber   (default 3)
 *   string $sectionTitle    (default 'Bukti dan Informasi Pendukung')
 */

$sectionNumber = $sectionNumber ?? 3;
$sectionTitle  = $sectionTitle ?? 'Bukti dan Informasi Pendukung';
?>
<div class="crf-section crf-detail-card mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><?= h((string) $sectionNumber) ?></span>
        <h2><?= h($sectionTitle) ?></h2>
    </div>

    <div class="crf-section-body">

        <div class="crf-detail-item crf-detail-item-wide crf-detail-item-attachments">
        <?php if (!$attachments): ?>

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
</div>
<?php
unset($sectionNumber, $sectionTitle);
