<?php
/**
 * Partial: sel "Urgensi" dan "Status" pada tabel daftar CRF.
 * Status dan Tahap digabung: badge status, tahap sebagai keterangan kecil.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $row
 */
$rowStage = (string) ($row['workflow_stage'] ?? 'PEMOHON');
?>
<td data-label="Urgensi">
    <?php if (!empty($row['level'])): ?>
        <span class="crf-badge <?= h(levelBadgeClass($row['level'])) ?>"><?= h($row['level']) ?></span>
    <?php else: ?>
        <span class="crf-row-muted">Belum ada</span>
    <?php endif; ?>
</td>
<td data-label="Status">
    <div class="crf-row-status">
        <span class="crf-badge <?= h(statusBadgeClass($row['status'] ?? '')) ?>"><?= h(statusLabel($row['status'] ?? '')) ?></span>
        <?php if (!in_array($rowStage, ['SELESAI'], true) && ($row['status'] ?? '') !== 'Draft'): ?>
            <span class="crf-row-stage" title="Tahap saat ini">
                <i class="bi bi-signpost-split"></i> <?= h(crfIsQueued($row) ? 'Antrean Otomasi' : workflowStageLabel($rowStage)) ?>
            </span>
        <?php endif; ?>
    </div>
</td>
<?php
unset($rowStage);
