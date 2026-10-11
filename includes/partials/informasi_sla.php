<?php
/**
 * Partial: Informasi SLA.
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
?>
<div class="crf-section crf-detail-card mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-hourglass-split"></i></span>
        <h2>Informasi SLA</h2>
    </div>

    <div class="crf-section-body">
        <div
            class="crf-sla-grid row g-2"
            <?= $slaIsLive && $slaDueEpoch !== false
                ? 'data-sla-live="true" data-sla-started-at="' . (int) $slaStartedEpoch . '" data-sla-due-at="' . (int) $slaDueEpoch . '"'
                : '' ?>
        >

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">LEVEL URGENSI</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['level'])): ?>
                        <span class="crf-sla-value"><?= h($crf['level']) ?></span>
                    <?php else: ?>
                        <span class="crf-sla-empty">Belum ditentukan</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">SLA</div>
                <div class="crf-detail-value">
                    <?php if ($slaValueDisplay !== null): ?>
                        <span class="crf-sla-value"><?= h($slaValueDisplay) ?></span>
                        <small class="d-block text-muted">hari kerja</small>
                        <?php if ($slaAdjusted): ?>
                            <small class="d-block text-warning-emphasis">
                                <i class="bi bi-pencil"></i> Disesuaikan dari standar <?= h(slaLabel($slaStandard['value'], $slaStandard['unit'])) ?> (alasan di timeline)
                            </small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="crf-sla-empty">Belum ditentukan</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">MULAI SLA</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['sla_mulai'])): ?>
                        <span class="crf-info-date"><?= h(date('d-m-Y H:i', strtotime($crf['sla_mulai']))) ?></span>
                    <?php else: ?>
                        <span class="crf-sla-empty">-</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">BATAS SLA</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['sla_tenggat'])): ?>
                        <span class="crf-info-date"><?= h(date('d-m-Y H:i', strtotime($crf['sla_tenggat']))) ?></span>
                    <?php else: ?>
                        <span class="crf-sla-empty">-</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">WAKTU SELESAI</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['otomasi_selesai'])): ?>
                        <span class="crf-info-date"><?= h(date('d-m-Y H:i', strtotime($crf['otomasi_selesai']))) ?></span>
                    <?php else: ?>
                        <span class="crf-sla-empty">Belum selesai</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">DURASI PENGERJAAN</div>
                <div class="crf-detail-value">
                    <?php if ($slaStatus['elapsed'] !== null): ?>
                        <span class="crf-sla-value"><?= h($slaStatus['elapsed']) ?></span>
                    <?php else: ?>
                        <span class="crf-sla-empty">-</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-6 crf-sla-item">
                <div class="crf-detail-label">STATUS SLA</div>
                <div class="crf-detail-value">
                    <span
                        class="badge text-bg-<?= h($slaStatus['class']) ?>"
                        data-sla-status-label
                        <?= $slaIsLive ? 'data-sla-started-at="' . (int) $slaStartedEpoch . '" data-sla-due-at="' . (int) $slaDueEpoch . '"' : '' ?>
                    ><?= h($slaStatus['label']) ?></span>
                    <span class="crf-sla-status-detail" data-sla-status-detail><?= h($slaStatus['detail']) ?></span>
                </div>
            </div>

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
                <i class="bi bi-<?= $slaStatus['alert'] === 'overdue' ? 'exclamation-triangle-fill' : 'clock-fill' ?>" data-sla-alert-icon></i>
                <span data-sla-alert-message><?= $slaStatus['alert'] === 'overdue' ? 'Peringatan: batas SLA telah terlewati.' : 'Perhatian: batas waktu SLA hampir terlewati.' ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
unset($slaValueDisplay, $slaStatus, $slaStandard, $slaAdjusted, $slaIsLive, $slaStartedEpoch, $slaDueEpoch);
