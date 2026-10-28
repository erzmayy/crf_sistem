<?php
/**
 * Kartu penanganan CRF (informasi saja): kategori, target SLA kategori,
 * dan PIC CRF yang memegang CRF. PIC CRF ditetapkan saat menekan
 * "Mulai kerjakan" (atau saat menyelesaikan eksekusi bila tombol itu dilewati).
 *
 * Variabel: $crf, $pdo.
 */
$assignmentCategory = !empty($crf['kategori_id']) ? findCrfCategory($pdo, (int) $crf['kategori_id']) : null;
?>
<div class="crf-assignment-card">
    <div class="crf-assignment-item">
        <span class="crf-request-caption">Kategori CRF</span>
        <strong><?= h($assignmentCategory['nama'] ?? ($crf['kategori_perubahan'] ?? '-')) ?></strong>
        <?php if ($assignmentCategory && $assignmentCategory['sla_nilai'] !== null): ?>
            <small class="text-muted">Target SLA kategori <?= h(slaLabel($assignmentCategory['sla_nilai'], $assignmentCategory['sla_satuan'])) ?></small>
        <?php endif; ?>
    </div>
    <div class="crf-assignment-item">
        <span class="crf-request-caption">PIC CRF</span>
        <?php if (!empty($crf['pic_id'])): ?>
            <strong><i class="bi bi-person-check"></i> <?= h($crf['pic_nama'] ?? '-') ?></strong>
            <?php if (!empty($crf['tgl_ditugaskan'])): ?>
                <small class="text-muted">sejak <?= h(date('d-m-Y H:i', strtotime($crf['tgl_ditugaskan']))) ?></small>
            <?php endif; ?>
        <?php else: ?>
            <strong class="text-muted"><i class="bi bi-person-dash"></i> Belum ada</strong>
            <small class="text-muted">Tercatat saat PIC menekan "Mulai kerjakan"</small>
        <?php endif; ?>
    </div>
</div>
<?php
unset($assignmentCategory);
