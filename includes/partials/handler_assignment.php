<?php
/**
 * Kartu penanganan CRF (informasi saja): kategori, target SLA kategori,
 * dan Petugas Otomasi yang memegang CRF. Petugas Otomasi ditetapkan
 * otomatis saat menekan "Mulai Kerjakan".
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
        <span class="crf-request-caption">Petugas Otomasi</span>
        <?php if (!empty($crf['assigned_handler_id'])): ?>
            <strong><i class="bi bi-person-check"></i> <?= h($crf['assigned_handler_name'] ?? '-') ?></strong>
            <?php if (!empty($crf['assigned_at'])): ?>
                <small class="text-muted">sejak <?= h(date('d-m-Y H:i', strtotime($crf['assigned_at']))) ?></small>
            <?php endif; ?>
        <?php else: ?>
            <strong class="text-muted"><i class="bi bi-person-dash"></i> Belum ada</strong>
            <small class="text-muted">Ditetapkan otomatis saat "Mulai Kerjakan"</small>
        <?php endif; ?>
    </div>
</div>
<?php
unset($assignmentCategory);
