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

        <div class="crf-info-grid row g-3 mb-0">

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label"><i class="bi bi-person"></i> Pengaju</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['full_name'] ?? '-') ?></span>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label"><i class="bi bi-envelope"></i> Email</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['email'] ?? '-') ?></span>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label"><i class="bi bi-telephone"></i> No. HP/WA</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['phone'] ?? '-') ?></span>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label"><i class="bi bi-hash"></i> Nomor Register</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main crf-info-register"><?= h($crf['request_number'] ?? '-') ?></span>
                </div>
            </div>

        </div>

        <div class="crf-info-divider"></div>

        <div class="crf-info-grid row g-3">

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label"><i class="bi bi-calendar3"></i> Tanggal Pengajuan</div>
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
                <div class="crf-detail-label"><i class="bi bi-building"></i> Kepada</div>
                <div class="crf-detail-value">
                    <span class="crf-info-main"><?= h($crf['to_department'] ?? '-') ?></span>
                    <?php if (!empty($crf['to_division'])): ?>
                        <span class="crf-info-sub"><?= h($crf['to_division']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-info-item">
                <div class="crf-detail-label"><i class="bi bi-arrow-left-right"></i> Dari</div>
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
