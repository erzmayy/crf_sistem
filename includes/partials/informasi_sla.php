<?php
/**
 * Partial: Informasi SLA.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 */

$slaValueDisplay = (!empty($crf['sla_value']) && !empty($crf['sla_unit']))
    ? rtrim(rtrim(number_format((float) $crf['sla_value'], 2, '.', ''), '0'), '.') . ' ' . $crf['sla_unit']
    : null;
$slaStatus = getCrfSlaStatus($crf);
$slaIsLive = !empty($slaStatus['live']);
$slaStartedEpoch = !empty($crf['sla_started_at'])
    ? strtotime($crf['sla_started_at'])
    : false;
$slaDueEpoch = !empty($crf['sla_due_at'])
    ? strtotime($crf['sla_due_at'])
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
                    <?php else: ?>
                        <span class="crf-sla-empty">Belum ditentukan</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">MULAI SLA</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['sla_started_at'])): ?>
                        <span class="crf-info-date"><?= h(date('d-m-Y H:i', strtotime($crf['sla_started_at']))) ?></span>
                    <?php else: ?>
                        <span class="crf-sla-empty">-</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">BATAS SLA</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['sla_due_at'])): ?>
                        <span class="crf-info-date"><?= h(date('d-m-Y H:i', strtotime($crf['sla_due_at']))) ?></span>
                    <?php else: ?>
                        <span class="crf-sla-empty">-</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-3 crf-sla-item">
                <div class="crf-detail-label">WAKTU SELESAI</div>
                <div class="crf-detail-value">
                    <?php if (!empty($crf['automation_completed_at'])): ?>
                        <span class="crf-info-date"><?= h(date('d-m-Y H:i', strtotime($crf['automation_completed_at']))) ?></span>
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

        <?php if ($slaIsLive): ?>
            <div
                class="alert <?= $slaStatus['alert'] === null ? 'd-none' : 'alert-' . ($slaStatus['alert'] === 'overdue' ? 'danger' : 'warning') ?> crf-sla-alert"
                role="alert"
                data-sla-alert
                data-sla-alert-state="<?= h($slaStatus['alert'] ?? '') ?>"
            >
                <i class="bi bi-<?= $slaStatus['alert'] === 'overdue' ? 'exclamation-triangle-fill' : 'clock-fill' ?>" data-sla-alert-icon></i>
                <span data-sla-alert-message><?= $slaStatus['alert'] === 'overdue' ? 'Peringatan: batas SLA telah terlewati.' : 'Perhatian: batas waktu SLA tinggal 1 jam atau kurang.' ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
unset($slaValueDisplay, $slaStatus, $slaIsLive, $slaStartedEpoch, $slaDueEpoch);
