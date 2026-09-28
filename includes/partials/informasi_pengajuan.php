<?php
/**
 * Partial: Informasi Pengajuan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf   satu baris data change_requests
 *
 * Variabel OPSIONAL (set sebelum require, dibaca sekali):
 *   bool   $showStatusInGrid   tampilkan kolom STATUS di grid ini
 *                              (default false - biasanya status sudah
 *                              ditampilkan sebagai badge terpisah)
 *   int    $sectionNumber      angka section (default 1)
 */

$showStatusInGrid = $showStatusInGrid ?? false;
$sectionNumber    = $sectionNumber ?? 1;
?>
<div class="crf-section mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><?= h((string) $sectionNumber) ?></span>
        <h2>Informasi Pengajuan</h2>
    </div>

    <div class="crf-section-body">

        <div class="crf-info-grid row g-4 mb-4">

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label">PENGAJU</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['full_name'] ?? '-') ?></span>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label">EMAIL</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['email'] ?? '-') ?></span>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label">NO. HP/WA</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['phone'] ?? '-') ?></span>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label">NOMOR REGISTER</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['request_number'] ?? '-') ?></span>
                </div>
            </div>

        </div>

        <hr>

        <div class="crf-info-grid row g-4 mt-1">

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label">HARI/TANGGAL</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['submission_date'])): ?>
                        <span class="crf-info-date">
                            <?= h(formatTanggalIndonesia(new DateTime($crf['submission_date']))) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">Belum diajukan</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label">KEPADA</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['to_department'] ?? '-') ?></span>
                    <?php if (!empty($crf['to_division'])): ?>
                        <span class="crf-info-sub"><?= h($crf['to_division']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label">DARI</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['from_department'] ?? '-') ?></span>
                    <?php if (!empty($crf['from_division'])): ?>
                        <span class="crf-info-sub"><?= h($crf['from_division']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($showStatusInGrid): ?>
                <div class="col-md-3 crf-info-item">
                    <div class="crf-detail-label">STATUS</div>
                    <div class="crf-detail-value">
                        <span class="crf-badge crf-info-status <?= statusBadgeClass($crf['status'] ?? '') ?>">
                            <?= h(statusLabel($crf['status'] ?? '')) ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </div>
</div>
<?php
// Bersihkan supaya tidak "kebawa" tanpa sengaja ke partial berikutnya.
unset($showStatusInGrid, $sectionNumber);
