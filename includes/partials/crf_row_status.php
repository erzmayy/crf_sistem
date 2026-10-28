<?php
/**
 * Partial: sel "Urgensi" dan "Status" pada tabel daftar CRF.
 * Status dan Tahap digabung: badge status, tahap sebagai keterangan kecil.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $row
 */
$rowStage = (string) ($row['tahap_alur'] ?? 'PEMOHON');
// Tampilan Pemohon: urgensi dan Forum bersifat internal, jadi tidak ditampilkan.
$rowPemohonView = !empty($rowPemohonView);
// Opsional: lama menunggu di tahap CMO/Kadep (crfWaitingInfo). Hanya diisi daftar CMO dan Kadep.
$rowWaiting = $rowWaiting ?? null;
?>
<?php if (!$rowPemohonView): ?>
<td data-label="Urgensi">
    <?php if (!empty($row['level'])): ?>
        <span class="crf-badge <?= h(levelBadgeClass($row['level'])) ?>"><?= h($row['level']) ?></span>
    <?php else: ?>
        <span class="crf-row-muted">Belum ada</span>
    <?php endif; ?>
</td>
<?php endif; ?>
<td data-label="Status">
    <div class="crf-row-status">
        <span class="crf-badge <?= h(statusBadgeClass($row['status'] ?? '')) ?>"><?= h(statusLabel($row['status'] ?? '')) ?></span>
        <?php if (!in_array($rowStage, ['SELESAI'], true) && ($row['status'] ?? '') !== 'Draft'): ?>
            <span class="crf-row-stage" title="Tahap saat ini">
                <i class="bi bi-signpost-split"></i> <?= h(!$rowPemohonView && !empty($row['pembahasan_forum_terbuka']) && $rowStage === 'CMO_FILTER' ? 'Pembahasan Forum' : workflowStageLabel($rowStage)) ?>
            </span>
        <?php endif; ?>
        <?php if (!$rowPemohonView && !empty($rowWaiting)): ?>
            <span class="crf-row-waiting" title="Menunggu sejak <?= h($rowWaiting['since']) ?> (dihitung dalam jam kerja; 1 hr = 1 hari kerja)">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                <?= $rowWaiting['short'] === 'baru saja' ? 'Baru masuk' : 'Menunggu ' . h($rowWaiting['short']) ?>
            </span>
        <?php endif; ?>
    </div>
</td>
<?php
unset($rowStage, $rowPemohonView, $rowWaiting);
