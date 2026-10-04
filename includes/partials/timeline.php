<?php
/**
 * Partial: Riwayat alur proses CRF.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *   array $timeline   hasil query dari crf_activity_logs
 */

$activityLabels = [
    'Pengajuan Diajukan' => 'Pengajuan CRF Dibuat',
    'Kirim Ulang' => 'Pengajuan CRF Dikirim Ulang',
    'Dalam Proses' => 'Diperiksa Admin CAB',
    'Lolos Filter CMO' => 'Review Teknis & Komite',
    'Otomasi - SLA Ditentukan' => 'SLA Ditentukan',
    'SLA Otomatis' => 'SLA Standar Kategori',
    'SLA Disesuaikan Handler' => 'SLA Disesuaikan Handler',
    'Approval Kepala Departemen Operasional' => 'Persetujuan Kepala Departemen',
    'Otomasi Selesai' => 'Implementasi Selesai',
    'PIR Diisi Pemohon' => 'Post Implementation Review',
    'Pengingat PIR' => 'Pengingat PIR ke Pemohon',
    'Solve' => 'CRF Selesai',
    'Cancel' => 'CRF Dibatalkan',
    'Dibuat dari Helpdesk' => 'Draft CRF Dibuat dari Helpdesk',
    'CRF Diambil Handler' => 'CRF Diterima Handler',
    'Handler Ditugaskan' => 'Handler Ditugaskan',
];

$pendingStep = null;
$workflowStage = (string) ($crf['workflow_stage'] ?? '');
$requestStatus = (string) ($crf['status'] ?? '');

if (!in_array($requestStatus, ['Solve', 'Cancel'], true)) {
    switch ($workflowStage) {
        case 'PEMOHON':
            $pendingStep = $requestStatus === 'Draft'
                ? ['title' => 'Pengajuan CRF Diajukan', 'description' => 'Lengkapi dan kirim pengajuan untuk memulai proses.']
                : ['title' => 'Perbaikan oleh Pemohon', 'description' => 'Menunggu pemohon memperbaiki dan mengirim ulang CRF.'];
            break;
        case 'CMO_FILTER':
            $pendingStep = ['title' => 'Review Teknis & Komite', 'description' => 'Menunggu pemeriksaan pengajuan oleh CMO.'];
            break;
        case 'OTOMASI':
            if (array_key_exists('assigned_handler_id', $crf) && empty($crf['assigned_handler_id'])) {
                $pendingStep = ['title' => 'Menunggu Handler', 'description' => 'Menunggu Handler kategori mengambil CRF.'];
                break;
            }
            $pendingStep = empty($crf['kadep_operasional_approved_at'])
                ? ['title' => 'Penetapan SLA', 'description' => 'Menunggu Handler menentukan level urgensi dan SLA.']
                : ['title' => 'Mulai Implementasi / Selesai', 'description' => 'Menunggu Handler mencatat hasil implementasi.'];
            break;
        case 'kadep_operasional':
            $pendingStep = ['title' => 'Persetujuan Kepala Departemen', 'description' => 'Menunggu persetujuan Kepala Departemen Operasional.'];
            break;
        case 'PEMOHON_PIR':
            $pendingStep = ['title' => 'Post Implementation Review', 'description' => 'Menunggu Pemohon mengisi Post Implementation Review.'];
            break;
        case 'CMO_FINAL':
            $pendingStep = ['title' => 'Finalisasi CMO', 'description' => 'Menunggu CMO menyelesaikan atau menutup CRF.'];
            break;
    }
}

if ($workflowStage === 'SELESAI' && $requestStatus !== 'Solve' && $requestStatus !== 'Cancel') {
    $pendingStep = ['title' => 'Penyelesaian CRF', 'description' => 'Proses pengajuan telah mencapai tahap akhir.'];
}
?>
<section class="crf-section crf-detail-timeline" aria-labelledby="crf-timeline-title">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-clock-history"></i></span>
        <h2 id="crf-timeline-title">Riwayat Alur Proses (Timeline)</h2>
    </div>

    <div class="crf-section-body">
        <?php if (empty($timeline) && $pendingStep === null): ?>
            <div class="text-muted">Belum ada riwayat proses pengajuan.</div>
        <?php else: ?>
            <ol class="crf-timeline">
                <?php foreach ($timeline as $item): ?>
                    <?php
                    $activity = (string) ($item['activity'] ?? '');
                    $isAttention = $activity === 'Perlu Revisi';
                    $isCancelled = $activity === 'Cancel';
                    $itemClass = $isAttention ? 'is-attention' : ($isCancelled ? 'is-cancelled' : 'is-complete');
                    $activityTitle = $activityLabels[$activity] ?? $activity;
                    $createdAt = strtotime((string) ($item['created_at'] ?? ''));
                    ?>
                    <li class="crf-timeline-item <?= h($itemClass) ?>">
                        <span class="crf-timeline-dot" aria-hidden="true">
                            <?php if ($isAttention): ?>
                                <i class="bi bi-exclamation"></i>
                            <?php elseif ($isCancelled): ?>
                                <i class="bi bi-x"></i>
                            <?php else: ?>
                                <i class="bi bi-check"></i>
                            <?php endif; ?>
                        </span>
                        <div class="crf-timeline-content">
                            <strong class="crf-timeline-title"><?= h($activityTitle) ?></strong>
                            <?php if ($createdAt !== false): ?>
                                <time class="crf-timeline-date" datetime="<?= h(date('c', $createdAt)) ?>">
                                    <?= h(date('d M Y, h:i A', $createdAt)) ?>
                                </time>
                            <?php endif; ?>
                            <?php if (!empty($item['description'])): ?>
                                <div class="crf-timeline-description"><?= nl2br(h($item['description'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($item['new_status']) && ($item['old_status'] ?? '') !== $item['new_status']): ?>
                                <div class="crf-timeline-status">
                                    <?php if (!empty($item['old_status'])): ?>
                                        <span><?= h($item['old_status']) ?></span> <i class="bi bi-arrow-right"></i>
                                    <?php endif; ?>
                                    <strong><?= h($item['new_status']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($item['actor'])): ?>
                                <div class="crf-timeline-actor">Dilakukan oleh <?= h($item['actor']) ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>

                <?php if ($pendingStep !== null): ?>
                    <li class="crf-timeline-item is-pending">
                        <span class="crf-timeline-dot" aria-hidden="true"></span>
                        <div class="crf-timeline-content">
                            <strong class="crf-timeline-title"><?= h($pendingStep['title']) ?></strong>
                            <span class="crf-timeline-date">-</span>
                            <div class="crf-timeline-description"><?= h($pendingStep['description']) ?></div>
                        </div>
                    </li>
                <?php endif; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>
<?php
unset($activityLabels, $pendingStep, $workflowStage, $requestStatus);
