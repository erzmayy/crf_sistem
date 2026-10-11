<?php
/**
 * Partial: Informasi SLA (hanya untuk peran internal).
 * Satu baris ringkas (target, batas, status); rincian waktu bisa dibuka.
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
?>
<section class="crf-section crf-detail-card crf-sla-card mb-4" aria-labelledby="crf-sla-title">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
        <h2 id="crf-sla-title">Informasi SLA</h2>
    </div>

    <div class="crf-section-body">
        <div
            class="crf-sla-compact"
            <?= $slaIsLive && $slaDueEpoch !== false
                ? 'data-sla-live="true" data-sla-started-at="' . (int) $slaStartedEpoch . '" data-sla-due-at="' . (int) $slaDueEpoch . '"'
                : '' ?>
        >
            <div class="crf-sla-line">
                <span class="crf-sla-target">
                    <?php if ($slaValueDisplay !== null): ?>
                        <strong><?= h($slaValueDisplay) ?></strong> <small>(dihitung pada hari kerja)</small>
                    <?php else: ?>
                        <span class="crf-sla-empty">SLA belum ditentukan</span>
                    <?php endif; ?>
                </span>

                <?php if (!empty($crf['sla_tenggat'])): ?>
                    <span class="crf-sla-due">Batas <?= h($slaDate($crf['sla_tenggat'])) ?></span>
                <?php endif; ?>

                <span class="crf-sla-state">
                    <span
                        class="badge text-bg-<?= h($slaStatus['class']) ?>"
                        data-sla-status-label
                        <?= $slaIsLive ? 'data-sla-started-at="' . (int) $slaStartedEpoch . '" data-sla-due-at="' . (int) $slaDueEpoch . '"' : '' ?>
                    ><?= h($slaStatus['label']) ?></span>
                    <span class="crf-sla-status-detail" data-sla-status-detail><?= h($slaStatus['detail']) ?></span>
                </span>
            </div>

            <?php if ($slaAdjusted): ?>
                <p class="crf-sla-note">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                    Disesuaikan dari standar <?= h(slaLabel($slaStandard['value'], $slaStandard['unit'])) ?> (alasan di riwayat).
                </p>
            <?php endif; ?>

            <details class="crf-sla-details">
                <summary>Rincian waktu</summary>
                <dl class="crf-meta-row">
                    <div><dt>Level urgensi</dt><dd><?= h($crf['level'] ?? 'Belum ditentukan') ?></dd></div>
                    <div><dt>Mulai SLA</dt><dd><?= h($slaDate($crf['sla_mulai'] ?? null)) ?></dd></div>
                    <div><dt>Batas SLA</dt><dd><?= h($slaDate($crf['sla_tenggat'] ?? null)) ?></dd></div>
                    <div><dt>Waktu selesai</dt><dd><?= !empty($crf['otomasi_selesai']) ? h($slaDate($crf['otomasi_selesai'])) : 'Belum selesai' ?></dd></div>
                    <div><dt>Durasi pengerjaan</dt><dd><?= $slaStatus['elapsed'] !== null ? h($slaStatus['elapsed']) : '-' ?></dd></div>
                </dl>
            </details>
        </div>

        <?php if (!empty($crf['sla_hasil'])): ?>
            <?php $slaOnTime = $crf['sla_hasil'] === 'Sesuai SLA'; ?>
            <div class="crf-sla-result <?= $slaOnTime ? 'is-success' : 'is-danger' ?>">
                <div><span class="crf-detail-label">Durasi SLA</span><strong><?= h(formatSlaDuration(((int) $crf['sla_menit_aktual']) * 60)) ?></strong></div>
                <div><span class="crf-detail-label">Target SLA</span><strong><?= h($slaValueDisplay ?? '-') ?></strong></div>
                <div><span class="crf-detail-label">Status</span><strong><?= $slaOnTime ? '✓ Sesuai SLA' : '⚠ Melebihi SLA' ?></strong></div>
            </div>
        <?php endif; ?>

        <?php if ($slaIsLive): ?>
            <div
                class="alert <?= $slaStatus['alert'] === null ? 'd-none' : 'alert-' . ($slaStatus['alert'] === 'overdue' ? 'danger' : 'warning') ?> crf-sla-alert"
                role="alert"
                data-sla-alert
                data-sla-alert-state="<?= h($slaStatus['alert'] ?? '') ?>"
            >
                <i class="bi bi-<?= $slaStatus['alert'] === 'overdue' ? 'exclamation-triangle-fill' : 'clock-fill' ?>" data-sla-alert-icon aria-hidden="true"></i>
                <span data-sla-alert-message><?= $slaStatus['alert'] === 'overdue' ? 'Peringatan: batas SLA telah terlewati.' : 'Perhatian: batas waktu SLA hampir terlewati.' ?></span>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php
unset($slaValueDisplay, $slaStatus, $slaStandard, $slaAdjusted, $slaIsLive, $slaStartedEpoch, $slaDueEpoch, $slaDate, $slaOnTime);
