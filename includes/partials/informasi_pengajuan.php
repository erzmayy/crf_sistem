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

$infoDisplayStatus = crfDisplayStatus($crf);
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
                <span class="crf-info-value"><?= h($crf['nama_pemohon'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">No. Handphone / WA</span>
                <span class="crf-info-value"><?= h($crf['telepon'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Email Pemohon</span>
                <span class="crf-info-value"><?= h($crf['email'] ?? '-') ?></span>
            </div>
            <?php if (!empty($crf['jabatan_pemohon'])): ?>
                <div class="crf-info-row">
                    <span class="crf-info-label">Jabatan</span>
                    <span class="crf-info-value"><?= h($crf['jabatan_pemohon']) ?></span>
                </div>
            <?php endif; ?>
            <div class="crf-info-row">
                <span class="crf-info-label">Nomor Register</span>
                <span class="crf-info-value crf-info-register"><?= h($crf['nomor_register'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Kategori CRF</span>
                <span class="crf-info-value"><?= h(crfCategoryName($crf)) ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">PIC CRF</span>
                <span class="crf-info-value"><?= h($crf['pic_nama'] ?? '') ?: '<span class="text-muted">Belum ada</span>' ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Tanggal Pengajuan</span>
                <span class="crf-info-value">
                    <?php if (!empty($crf['tgl_pengajuan'])): ?>
                        <?= h(formatTanggalIndonesia(new DateTime($crf['tgl_pengajuan']))) ?>
                    <?php else: ?>
                        <span class="text-muted">Belum diajukan</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Kepada</span>
                <span class="crf-info-value">
                    <?= h($crf['dept_tujuan'] ?? '-') ?>
                    <?php if (!empty($crf['divisi_tujuan'])): ?>
                        <span class="crf-info-sub"><?= h($crf['divisi_tujuan']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Dari</span>
                <span class="crf-info-value">
                    <?= h($crf['dept_asal'] ?? '-') ?>
                    <?php if (!empty($crf['divisi_asal'])): ?>
                        <span class="crf-info-sub"><?= h($crf['divisi_asal']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($showStatusInGrid): ?>
                <div class="crf-info-row">
                    <span class="crf-info-label">Status</span>
                    <span class="crf-info-value">
                        <span class="crf-badge crf-info-status <?= h($infoDisplayStatus['class']) ?>">
                            <?= h($infoDisplayStatus['label']) ?>
                        </span>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
unset($showStatusInGrid, $infoTicket, $infoTicketStmt, $infoDisplayStatus);
