<?php
/**
 * Partial: Informasi SLA.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *
 * Variabel OPSIONAL:
 *   int $sectionNumber   (default 6)
 */

$sectionNumber = $sectionNumber ?? 6;

$slaValueDisplay = (!empty($crf['sla_value']) && !empty($crf['sla_unit']))
    ? rtrim(rtrim(number_format((float) $crf['sla_value'], 2, '.', ''), '0'), '.') . ' ' . $crf['sla_unit']
    : null;
?>
<div class="crf-section mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><?= h((string) $sectionNumber) ?></span>
        <h2>Informasi SLA</h2>
    </div>

    <div class="crf-section-body">
        <div class="crf-sla-grid row g-4">

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

        </div>
    </div>
</div>
<?php
unset($sectionNumber, $slaValueDisplay);
