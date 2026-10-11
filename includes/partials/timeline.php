<?php
/**
 * Partial: Riwayat alur proses CRF.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *   array $timeline   hasil query dari crf_log_aktivitas
 */

$activityLabels = [
    'Pengajuan Diajukan' => 'Pengajuan CRF Dibuat',
    'Kirim Ulang' => 'Pengajuan CRF Dikirim Ulang',
    'Lolos Filter CMO' => 'Verifikasi CMO',
    'Otomasi - SLA Ditentukan' => 'SLA Ditentukan',
    'SLA Otomatis' => 'SLA Standar Kategori',
    'SLA Disesuaikan Handler' => 'SLA Disesuaikan PIC CRF',
    'Approval Kepala Departemen Operasional' => 'Persetujuan Kepala Departemen Operasional',
    // Istilah riwayat lama sebelum alur Forum diperbarui.
    'Kesepakatan Urgensi dan SLA Forum' => 'Hasil Pembahasan Forum',
    'Penyesuaian Alur' => 'Penyesuaian Alur Sistem',
    'Otomasi Selesai' => 'Implementasi Selesai',
    'PIR Diisi Pemohon' => 'Post Implementation Review',
    'Pengingat PIR' => 'Pengingat PIR ke Pemohon',
    'Solve' => 'CRF Selesai',
    'Cancel' => 'CRF Dibatalkan',
    'Dibuat dari Helpdesk' => 'Draft CRF Dibuat dari Helpdesk',
    'CRF Diambil Handler' => 'CRF Diterima PIC CRF',
    'Handler Ditugaskan' => 'PIC CRF Ditugaskan',
];

// Istilah lama pada riwayat yang tersimpan, diseragamkan saat ditampilkan.
$legacyTerms = [
    'Menunggu Approval' => 'Menunggu Persetujuan',
    'Menunggu Review' => 'Menunggu Verifikasi',
    'untuk approval' => 'untuk persetujuan',
    'setelah approval' => 'setelah persetujuan',
    'lolos filter CMO' => 'lolos verifikasi CMO',
    'Handler kategori' => 'PIC CRF',
    'ke Handler' => 'ke PIC CRF',
    // Riwayat lama yang tersimpan sebelum istilah diganti.
    'Petugas Otomasi' => 'PIC CRF',
    'Petugas otomasi' => 'PIC CRF',
];
$modernizeTerm = static fn(?string $text): string => strtr((string) $text, $legacyTerms);

/*
 * Tampilan untuk Pemohon ($timelineHideSla = true): SLA dan Forum adalah
 * urusan internal, jadi entri dan kalimat yang memuat SLA tidak ditampilkan.
 * Pemohon hanya melihat Level Urgensi dan tahap CRF.
 */
if (!empty($timelineHideSla)) {
    $slaHiddenActivities = [
        'SLA Otomatis', 'Otomasi - SLA Ditentukan', 'SLA Disesuaikan Handler', 'Peringatan SLA',
        'Kesepakatan Urgensi dan SLA Forum', 'Pembahasan Forum Diajukan', 'Hasil Pembahasan Forum',
        'Pembahasan Forum Dibatalkan',
    ];
    $timeline = array_values(array_filter(
        $timeline ?? [],
        static fn(array $item): bool => !in_array((string) ($item['aktivitas'] ?? ''), $slaHiddenActivities, true)
    ));

    $modernizeTerm = static fn(?string $text): string => crfStripSlaText(strtr((string) $text, $legacyTerms));
}

$pendingStep = null;
$workflowStage = (string) ($crf['tahap_alur'] ?? '');
$requestStatus = (string) ($crf['status'] ?? '');

if (!in_array($requestStatus, ['Solve', 'Cancel'], true)) {
    switch ($workflowStage) {
        case 'PEMOHON':
            $pendingStep = $requestStatus === 'Draft'
                ? ['title' => 'Pengajuan CRF Diajukan', 'deskripsi' => 'Lengkapi dan kirim pengajuan untuk memulai proses.']
                : ['title' => 'Perbaikan oleh Pemohon', 'deskripsi' => 'Menunggu pemohon memperbaiki dan mengirim ulang CRF.'];
            break;
        case 'CMO_FILTER':
            // Forum tidak terbuka bagi Pemohon; baginya CRF tetap "Verifikasi CMO".
            $pendingStep = (!empty($crf['pembahasan_forum_terbuka']) && empty($timelineHideSla))
                ? ['title' => 'Menunggu Pembahasan Forum', 'deskripsi' => 'CMO mengajukan pembahasan Level Urgensi dan SLA. Setelah hasil dicatat CMO atau Admin, CRF langsung diteruskan ke Kepala Departemen Operasional.']
                : ['title' => 'Verifikasi CMO', 'deskripsi' => 'Menunggu verifikasi permohonan oleh CMO.'];
            break;
        case 'OTOMASI':
            $otomasiPhase = crfOtomasiPhase($crf);
            $pendingStep = $otomasiPhase === 'perbaikan'
                ? ['title' => 'Perbaikan Hasil UAT', 'deskripsi' => 'CMO meminta perbaikan. Menunggu PIC CRF memperbaiki dan mengirim ulang untuk UAT (tidak dihitung dalam SLA).']
                : ($otomasiPhase === 'implementasi'
                    ? ['title' => 'Isi Implementasi', 'deskripsi' => 'UAT lulus. Menunggu PIC CRF mencatat Tanggal Implementasi dan hasil implementasi.']
                    : ['title' => 'Tindak Lanjut Divisi Otomasi', 'deskripsi' => 'SLA berjalan sejak persetujuan. Menunggu PIC CRF menyelesaikan eksekusi.']);
            break;
        case 'UAT':
            $pendingStep = ['title' => 'UAT oleh CMO', 'deskripsi' => 'Eksekusi selesai dan SLA berhenti. Menunggu CMO menguji bersama PIC CRF (tidak dihitung dalam SLA).'];
            break;
        case 'kadep_operasional':
            $pendingStep = ['title' => 'Persetujuan Kepala Departemen Operasional', 'deskripsi' => 'Menunggu persetujuan Kepala Departemen Operasional.'];
            break;
        case 'PEMOHON_PIR':
            $pendingStep = ['title' => 'Post Implementation Review', 'deskripsi' => 'Menunggu Pemohon mengisi Post Implementation Review.'];
            break;
        case 'CMO_FINAL':
            $pendingStep = ['title' => 'Finalisasi CMO', 'deskripsi' => 'Menunggu CMO menyelesaikan atau menutup CRF.'];
            break;
    }
}

if ($workflowStage === 'SELESAI' && $requestStatus !== 'Solve' && $requestStatus !== 'Cancel') {
    $pendingStep = ['title' => 'Penyelesaian CRF', 'deskripsi' => 'Proses pengajuan telah mencapai tahap akhir.'];
}
?>
<section class="crf-section crf-detail-timeline" aria-labelledby="crf-timeline-title">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
        <h2 id="crf-timeline-title">Riwayat</h2>
    </div>

    <div class="crf-section-body">
        <?php if (empty($timeline) && $pendingStep === null): ?>
            <div class="text-muted">Belum ada riwayat proses pengajuan.</div>
        <?php else: ?>
            <?php
            // Hanya entri terbaru yang ditampilkan; yang lebih lama bisa dibuka.
            $timelineKeep = 4;
            $timelineOlder = max(0, count($timeline) - $timelineKeep);
            ?>
            <?php if ($timelineOlder > 0): ?>
                <button type="button" class="crf-timeline-toggle" data-timeline-toggle
                        data-label-open="Sembunyikan entri lama" data-label-closed="Tampilkan <?= (int) $timelineOlder ?> entri sebelumnya"
                        aria-expanded="false" aria-controls="crf-timeline-list">
                    Tampilkan <?= (int) $timelineOlder ?> entri sebelumnya
                </button>
            <?php endif; ?>
            <ol id="crf-timeline-list" class="crf-timeline<?= $timelineOlder > 0 ? ' is-trimmed' : '' ?>">
                <?php foreach ($timeline as $timelineIndex => $item): ?>
                    <?php
                    $activity = (string) ($item['aktivitas'] ?? '');
                    $isAttention = $activity === 'Perlu Revisi';
                    $isCancelled = $activity === 'Cancel';
                    $itemClass = $isAttention ? 'is-attention' : ($isCancelled ? 'is-cancelled' : 'is-complete');
                    $activityTitle = $activityLabels[$activity] ?? $activity;
                    $createdAt = strtotime((string) ($item['tgl_dibuat'] ?? ''));
                    ?>
                    <li class="crf-timeline-item <?= h($itemClass) ?><?= $timelineIndex < $timelineOlder ? ' crf-timeline-older' : '' ?>">
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
                            <?php if (!empty($item['deskripsi'])): ?>
                                <div class="crf-timeline-description"><?= nl2br(h($modernizeTerm($item['deskripsi']))) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($item['status_baru']) && ($item['status_lama'] ?? '') !== $item['status_baru']): ?>
                                <div class="crf-timeline-status">
                                    <?php if (!empty($item['status_lama'])): ?>
                                        <span><?= h($modernizeTerm($item['status_lama'])) ?></span> <i class="bi bi-arrow-right"></i>
                                    <?php endif; ?>
                                    <strong><?= h($modernizeTerm($item['status_baru'])) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($item['pelaksana'])): ?>
                                <div class="crf-timeline-actor">Dilakukan oleh <?= h($item['pelaksana']) ?></div>
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
                            <div class="crf-timeline-description"><?= h(!empty($timelineHideSla) ? $modernizeTerm($pendingStep['deskripsi']) : $pendingStep['deskripsi']) ?></div>
                        </div>
                    </li>
                <?php endif; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>
<?php
unset($timelineKeep, $timelineOlder, $timelineIndex, $activityLabels, $pendingStep, $workflowStage, $requestStatus);
