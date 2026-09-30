<?php
/**
 * Partial: Informasi Pengajuan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *
 * Variabel OPSIONAL:
 *   bool $showStatusInGrid
 */

$showStatusInGrid = $showStatusInGrid ?? false;
?>
<div class="crf-section crf-detail-card mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-person-lines-fill"></i></span>
        <h2>Informasi Pengajuan</h2>
    </div>

    <div class="crf-section-body">
        <div class="crf-info-rows">
            <div class="crf-info-row">
                <span class="crf-info-label">Nama Lengkap</span>
                <span class="crf-info-value"><?= h($crf['full_name'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">No. Handphone / WA</span>
                <span class="crf-info-value"><?= h($crf['phone'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Email Pemohon</span>
                <span class="crf-info-value"><?= h($crf['email'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Nomor Register</span>
                <span class="crf-info-value crf-info-register"><?= h($crf['request_number'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Tanggal Pengajuan</span>
                <span class="crf-info-value">
                    <?php if (!empty($crf['submission_date'])): ?>
                        <?= h(formatTanggalIndonesia(new DateTime($crf['submission_date']))) ?>
                    <?php else: ?>
                        <span class="text-muted">Belum diajukan</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Kepada</span>
                <span class="crf-info-value">
                    <?= h($crf['to_department'] ?? '-') ?>
                    <?php if (!empty($crf['to_division'])): ?>
                        <span class="crf-info-sub"><?= h($crf['to_division']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Dari</span>
                <span class="crf-info-value">
                    <?= h($crf['from_department'] ?? '-') ?>
                    <?php if (!empty($crf['from_division'])): ?>
                        <span class="crf-info-sub"><?= h($crf['from_division']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($showStatusInGrid): ?>
                <div class="crf-info-row">
                    <span class="crf-info-label">Status</span>
                    <span class="crf-info-value">
                        <span class="crf-badge crf-info-status <?= statusBadgeClass($crf['status'] ?? '') ?>">
                            <?= h(statusLabel($crf['status'] ?? '')) ?>
                        </span>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
unset($showStatusInGrid);
