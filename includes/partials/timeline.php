<?php
/**
 * Partial: Timeline Proses Pengajuan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $timeline   hasil query dari crf_activity_logs
 *
 * Variabel OPSIONAL:
 *   int    $sectionNumber   angka section; kosongkan untuk pakai ikon
 *                           jam (bawaan admin/detail.php & user/detail.php)
 *   string $sectionTitle    (default 'Timeline Proses Pengajuan')
 */

$sectionTitle = $sectionTitle ?? 'Timeline Proses Pengajuan';
?>
<div class="crf-section mt-4">
    <div class="crf-section-header">
        <span class="crf-section-number">
            <?php if (!empty($sectionNumber)): ?>
                <?= h((string) $sectionNumber) ?>
            <?php else: ?>
                <i class="bi bi-clock-history"></i>
            <?php endif; ?>
        </span>
        <h2><?= h($sectionTitle) ?></h2>
    </div>

    <div class="crf-section-body">

        <?php if (!$timeline): ?>

            <div class="text-muted">Belum ada riwayat proses pengajuan.</div>

        <?php else: ?>

            <div class="crf-timeline">
                <?php foreach ($timeline as $item): ?>

                    <div class="crf-timeline-item">
                        <div class="crf-timeline-dot"></div>

                        <div class="crf-timeline-content">

                            <div class="crf-timeline-top">
                                <strong>
                                    <?= h(
                                        $item['activity'] === 'Solve'
                                            ? 'Selesai'
                                            : ($item['activity'] === 'Cancel' ? 'Dibatalkan' : $item['activity'])
                                    ) ?>
                                </strong>
                                <span class="crf-timeline-date">
                                    <?= h(date('d-m-Y H:i', strtotime($item['created_at']))) ?>
                                </span>
                            </div>

                            <?php if (!empty($item['description'])): ?>
                                <div class="crf-timeline-description">
                                    <?= nl2br(h($item['description'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($item['actor'])): ?>
                                <div class="crf-timeline-actor">Oleh: <?= h($item['actor']) ?></div>
                            <?php endif; ?>

                        </div>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>
</div>
<?php
unset($sectionNumber, $sectionTitle);
