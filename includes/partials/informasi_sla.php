<?php
/**
 * Partial: Informasi SLA (hanya untuk peran internal).
 * Resolution Time (sampai pengerjaan selesai). Waktu respon tiap peran ada di Riwayat (timeline.php).
 * Satu tampilan: target, batas, selesai, durasi, bar, dan satu status (tanpa rincian berulang).
 * Atribut data-sla-* dipertahankan karena dipakai script.js untuk hitung mundur.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 */

$slaValueDisplay = (!empty($crf['sla_nilai']) && !empty($crf['sla_satuan']))
    ? rtrim(rtrim(number_format((float) $crf['sla_nilai'], 2, '.', ''), '0'), '.') . ' ' . $crf['sla_satuan']
    : null;
$slaStatus = getCrfSlaStatus($crf);
// Standar matriks Kategori x Urgensi, untuk menandai SLA yang disesuaikan.
$slaStandard = empty($crf['level_urgensi_final'])
    ? crfStandardSla(getConnection(), (int) ($crf['kategori_id'] ?? 0), crfEffectiveUrgency($crf))
    : null;
$slaAdjusted = $slaValueDisplay !== null && $slaStandard !== null
    && !crfSlaEquals($slaStandard, $crf['sla_nilai'], $crf['sla_satuan']);
$slaIsLive = !empty($slaStatus['live']);
$slaStartedEpoch = !empty($crf['sla_mulai'])
    ? strtotime($crf['sla_mulai'])
    : false;
$slaDueEpoch = !empty($crf['sla_tenggat'])
    ? strtotime($crf['sla_tenggat'])
    : false;
$slaDate = static fn(?string $v): string => !empty($v) ? date('d-m-Y H:i', strtotime($v)) : '-';
$slaPercent = crfSlaProgressPercent($crf['sla_mulai'] ?? null, $crf['sla_tenggat'] ?? null, $crf['otomasi_selesai'] ?? null);
// Sebelum persetujuan Kadep SLA belum dimulai dan target belum final (bergantung level urgensi dari Forum):
// panel Resolution tidak ditampilkan, diganti satu keterangan.
$slaNotStarted = empty($crf['sla_mulai']);
$slaNotStartedText = ($crf['status'] ?? '') === 'Cancel'
    ? 'CRF dibatalkan sebelum Resolution Time dimulai.'
    : 'Resolution Time dimulai setelah persetujuan Kepala Departemen Operasional dan dihitung dalam jam kerja 08:00-17:00.';
// Perkiraan target (untuk Kadep yang memeriksa SLA sebelum menyetujui): sama dengan yang akan disalin ke CRF.
$slaPreviewParts = [];
if ($slaNotStarted && ($crf['status'] ?? '') !== 'Cancel') {
    $previewUrgency = crfEffectiveUrgency($crf);
    $previewCategory = !empty($crf['kategori_id']) ? findCrfCategory(getConnection(), (int) $crf['kategori_id']) : null;
    // Sama dengan yang akan dibekukan saat disetujui: target PIC global lebih dulu, matriks kategori sebagai cadangan.
    $previewRespon = (crfRespTableReady(getConnection()) && $previewUrgency) ? crfRespTarget(getConnection(), 'PIC_MULAI', $previewUrgency) : null;
    if ($previewRespon === null && $previewCategory && $previewUrgency) {
        $previewRespon = crfCategoryResponMatrix($previewCategory)[$previewUrgency] ?? null;
    }
    if ($previewRespon !== null) {
        $slaPreviewParts[] = 'Response Time PIC ' . slaLabel($previewRespon['value'], $previewRespon['unit']);
    }
    if ($slaValueDisplay !== null) {
        $slaPreviewParts[] = 'Resolution Time ' . $slaValueDisplay;
    }
}
?>
<section class="crf-section crf-detail-card crf-sla-card mb-4" aria-labelledby="crf-sla-title">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
        <h2 id="crf-sla-title">Informasi SLA</h2>
    </div>

    <div class="crf-section-body">
        <?php if ($slaNotStarted): ?>
            <p class="crf-sla-note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <?= h($slaNotStartedText) ?>
            </p>
            <?php if ($slaPreviewParts): ?>
                <p class="crf-sla-note">
                    <i class="bi bi-flag" aria-hidden="true"></i>
                    Target saat disetujui: <?= h(implode(' · ', $slaPreviewParts)) ?> (jam kerja, mengikuti level urgensi).
                </p>
            <?php endif; ?>
        <?php else: ?>

            <!-- RESOLUSI -->
            <div
                class="crf-sla-panel crf-sla-resolution is-<?= h($slaStatus['class']) ?>"
                <?= $slaIsLive && $slaDueEpoch !== false
                    ? 'data-sla-live="true" data-sla-started-at="' . (int) $slaStartedEpoch . '" data-sla-due-at="' . (int) $slaDueEpoch . '"'
                    : '' ?>
            >
                <div class="crf-sla-panel-head" title="Dihitung sampai pengerjaan selesai">
                    <p class="crf-resp-sub">Resolution Time: dari persetujuan Kepala Departemen Operasional sampai pengerjaan selesai. Waktu UAT dan perbaikan tidak dihitung ulang.</p>
                    <span class="badge text-bg-<?= h($slaStatus['class']) ?>" data-sla-status-label><?= h($slaStatus['label']) ?></span>
                </div>

                <dl class="crf-sla-tiles">
                    <div><dt>Target</dt><dd><?= $slaValueDisplay !== null ? h($slaValueDisplay) . ' <small>jam kerja</small>' : '<span class="crf-sla-empty">Belum ditentukan</span>' ?></dd></div>
                    <div><dt>Batas</dt><dd><?= h($slaDate($crf['sla_tenggat'] ?? null)) ?></dd></div>
                    <div><dt>Selesai</dt><dd><?= !empty($crf['otomasi_selesai']) ? h($slaDate($crf['otomasi_selesai'])) : '<span class="crf-resp-pending">Belum</span>' ?></dd></div>
                    <?php if (!empty($crf['otomasi_selesai']) && $slaStatus['elapsed'] !== null): ?>
                        <div><dt>Durasi</dt><dd><?= h($slaStatus['elapsed']) ?></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if ($slaPercent !== null): ?>
                    <div class="crf-sla-bar is-<?= h($slaStatus['class']) ?>" role="progressbar" aria-label="Resolution time terpakai" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $slaPercent ?>" data-sla-bar>
                        <span style="width: <?= (int) $slaPercent ?>%"></span>
                    </div>
                <?php endif; ?>
                <p class="crf-sla-status-detail" data-sla-status-detail><?= h($slaStatus['detail']) ?></p>

                <?php if ($slaIsLive): ?>
                    <div
                        class="alert <?= $slaStatus['alert'] === null ? 'd-none' : 'alert-' . ($slaStatus['alert'] === 'overdue' ? 'danger' : 'warning') ?> crf-sla-alert"
                        role="alert"
                        data-sla-alert
                        data-sla-alert-state="<?= h($slaStatus['alert'] ?? '') ?>"
                    >
                        <i class="bi bi-<?= $slaStatus['alert'] === 'overdue' ? 'exclamation-triangle-fill' : 'clock-fill' ?>" data-sla-alert-icon aria-hidden="true"></i>
                        <span data-sla-alert-message><?= $slaStatus['alert'] === 'overdue' ? 'Peringatan: batas Resolution Time telah terlewati.' : 'Perhatian: batas waktu Resolution Time hampir terlewati.' ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($slaAdjusted): ?>
                <p class="crf-sla-note">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                    Disesuaikan dari standar <?= h(slaLabel($slaStandard['value'], $slaStandard['unit'])) ?> (alasan di riwayat).
                </p>
            <?php endif; ?>
        <?php endif; /* $slaNotStarted */ ?>

    </div>
</section>
<?php
unset($slaNotStarted, $slaNotStartedText, $slaPreviewParts, $previewUrgency, $previewCategory, $previewRespon, $slaValueDisplay, $slaStatus, $slaStandard, $slaAdjusted, $slaIsLive, $slaStartedEpoch, $slaDueEpoch, $slaDate, $slaPercent);
