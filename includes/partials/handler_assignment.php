<?php
/**
 * Kartu penanganan CRF (informasi saja): kategori, target SLA kategori,
 * dan PIC CRF yang memegang CRF. PIC CRF ditetapkan
 * otomatis saat menyelesaikan eksekusi.
 *
 * Variabel: $crf, $pdo.
 */
$assignmentCategory = !empty($crf['crf_category_id']) ? findCrfCategory($pdo, (int) $crf['crf_category_id']) : null;
?>
<div class="crf-assignment-card">
    <div class="crf-assignment-item">
        <span class="crf-request-caption">Kategori CRF</span>
        <strong><?= h($assignmentCategory['name'] ?? ($crf['change_category'] ?? '-')) ?></strong>
        <?php if ($assignmentCategory && $assignmentCategory['sla_value'] !== null): ?>
            <small class="text-muted">Target SLA kategori <?= h(slaLabel($assignmentCategory['sla_value'], $assignmentCategory['sla_unit'])) ?></small>
        <?php endif; ?>
    </div>
    <div class="crf-assignment-item">
        <span class="crf-request-caption">PIC CRF</span>
        <?php if (!empty($crf['assigned_handler_id'])): ?>
            <strong><i class="bi bi-person-check"></i> <?= h($crf['assigned_handler_name'] ?? '-') ?></strong>
            <?php if (!empty($crf['assigned_at'])): ?>
                <small class="text-muted">sejak <?= h(date('d-m-Y H:i', strtotime($crf['assigned_at']))) ?></small>
            <?php endif; ?>
        <?php else: ?>
            <strong class="text-muted"><i class="bi bi-person-dash"></i> Belum ada</strong>
            <small class="text-muted">Tercatat saat eksekusi diselesaikan</small>
        <?php endif; ?>
    </div>
</div>
<?php
unset($assignmentCategory);
