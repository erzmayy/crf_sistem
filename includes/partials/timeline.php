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
    'Otomasi - SLA Ditentukan' => 'Target Resolution Time Ditentukan',
    'SLA Otomatis' => 'Target Standar Kategori',
    'SLA Disesuaikan Handler' => 'Target Disesuaikan PIC Otomasi',
    'Approval Kepala Departemen Operasional' => 'Persetujuan Kepala Departemen Operasional',
    'Diteruskan ke Kepala Departemen' => 'Diteruskan ke Kepala Departemen Operasional',
    // Istilah riwayat lama sebelum alur Forum diperbarui.
    'Kesepakatan Urgensi dan SLA Forum' => 'Hasil Pembahasan Forum',
    'Penyesuaian Alur' => 'Penyesuaian Alur Sistem',
    'Otomasi Selesai' => 'Implementasi Selesai',
    // Nilai lama yang tersimpan di riwayat; tampil dengan istilah pengerjaan.
    'Eksekusi Selesai' => 'Pengerjaan Selesai',
    'PIR Diisi Pemohon' => 'Post Implementation Review',
    'Pengingat PIR' => 'Pengingat PIR ke Pemohon',
    'Solve' => 'CRF Selesai',
    'Cancel' => 'CRF Dibatalkan',
    // Riwayat lama dari masa modul Helpdesk (sudah dihapus).
    'Dibuat dari Helpdesk' => 'Draft CRF Dibuat',
    'CRF Diambil Handler' => 'CRF Diterima PIC Otomasi',
    'Handler Ditugaskan' => 'PIC Otomasi Ditugaskan',
];

// Istilah lama pada riwayat yang tersimpan, diseragamkan saat ditampilkan.
$legacyTerms = [
    'Menunggu Approval' => 'Menunggu Persetujuan',
    'Menunggu Review' => 'Menunggu Verifikasi',
    'untuk approval' => 'untuk persetujuan',
    'setelah approval' => 'setelah persetujuan',
    'lolos filter CMO' => 'lolos verifikasi CMO',
    'Handler kategori' => 'PIC Otomasi',
    'ke Handler' => 'ke PIC Otomasi',
    // Riwayat lama yang tersimpan sebelum istilah diganti.
    'Petugas Otomasi' => 'PIC Otomasi',
    'Petugas otomasi' => 'PIC Otomasi',
    // Kalimat dan label lama pada riwayat tersimpan.
    'SLA berhenti' => 'Resolution Time berhenti',
    'SLA mulai dihitung' => 'Resolution Time mulai dihitung',
    'SLA berjalan' => 'Resolution Time berjalan',
    'dihitung dalam SLA' => 'dihitung dalam Resolution Time',
    'Ditolak / Perlu Revisi' => 'Perlu Revisi',
    'Tindak Lanjut Divisi Otomasi' => 'Tindak Lanjut Otomasi',
    // Riwayat lama yang masih menulis "PIC Otomasi".
    'PIC CRF' => 'PIC Otomasi',
    // Istilah eksekusi diganti pengerjaan (riwayat lama tetap tampil seragam).
    'dieksekusi' => 'dikerjakan',
    'Eksekusi' => 'Pengerjaan',
    'eksekusi' => 'pengerjaan',
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
                ? ['title' => 'Menunggu Pembahasan Forum', 'deskripsi' => 'CMO mengajukan pembahasan Level Urgensi dan target Resolution Time. Setelah hasil dicatat CMO, CRF langsung diteruskan ke Kepala Departemen Operasional.']
                : ['title' => 'Verifikasi CMO', 'deskripsi' => 'Menunggu verifikasi permohonan oleh CMO.'];
            break;
        case 'OTOMASI':
            $otomasiPhase = crfOtomasiPhase($crf);
            $pendingStep = $otomasiPhase === 'perbaikan'
                ? ['title' => 'Perbaikan Hasil UAT', 'deskripsi' => 'CMO meminta perbaikan. Menunggu PIC Otomasi memperbaiki dan mengirim ulang untuk UAT (tidak dihitung dalam Resolution Time).']
                : ($otomasiPhase === 'implementasi'
                    ? ['title' => 'Isi Implementasi', 'deskripsi' => 'UAT lulus. Menunggu PIC Otomasi mencatat Tanggal Implementasi dan hasil implementasi.']
                    : ['title' => 'Tindak Lanjut Otomasi', 'deskripsi' => 'Resolution Time berjalan sejak persetujuan. Menunggu PIC Otomasi menyelesaikan pengerjaan.']);
            break;
        case 'UAT':
            $pendingStep = ['title' => 'UAT oleh CMO', 'deskripsi' => 'Pengerjaan selesai dan Resolution Time berhenti. Menunggu CMO menguji bersama PIC Otomasi (tidak dihitung dalam Resolution Time).'];
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

/*
 * Keterangan waktu respon (hanya peran internal): tiap entri yang menutup satu tahap menampilkan
 * berapa lama peran itu merespons, dan langkah yang sedang menunggu menampilkan sisa/terlambatnya.
 * Baris crf_sla_respon dicocokkan dengan entri riwayat yang waktunya paling dekat (maks. 10 detik),
 * karena keduanya dicatat dalam satu aksi.
 */
$respNoteByIndex = [];
$respPendingNote = null;
if (empty($timelineHideSla) && !empty($crf['id'])) {
    $respRowsAll = crfRespRows(getConnection(), (int) $crf['id']);
    $respRoleShort = ['CMO_VERIFIKASI' => 'CMO', 'CMO_UAT' => 'CMO', 'CMO_FINAL' => 'CMO', 'KADEP' => 'Kadep', 'PIC_MULAI' => 'PIC Otomasi'];
    $respNoteText = static function (array $row, array $status) use ($respRoleShort): string {
        $who = $respRoleShort[$row['tahap']] ?? '';
        // UAT dan finalisasi bukan soal kecepatan merespons: yang dicatat adalah lamanya.
        $phaseNames = ['CMO_UAT' => 'Lama UAT', 'CMO_FINAL' => 'Lama finalisasi'];
        if (!empty($row['respon_at'])) {
            $text = ($phaseNames[$row['tahap']] ?? 'Respon ' . $who) . ': ' . ($status['elapsed'] ?? '-');
            if ($status['class'] === 'success') {
                $text .= ' · tepat waktu';
            } elseif ($status['class'] === 'danger') {
                $text .= ' · terlambat';
            }

            return $text;
        }

        if (empty($row['tenggat'])) {
            return (isset($phaseNames[$row['tahap']]) ? 'Berjalan' : 'Menunggu ' . $who) . ' sejak ' . date('d-m-Y H:i', strtotime((string) $row['mulai']));
        }

        return 'Menunggu ' . $who . ' · ' . rtrim($status['detail'], '.');
    };

    $usedIndexes = [];
    foreach ($respRowsAll as $respRowItem) {
        $status = crfRespRowStatus($respRowItem);
        if (empty($respRowItem['respon_at'])) {
            $respPendingNote = ['text' => $respNoteText($respRowItem, $status), 'class' => $status['class']];
            continue;
        }
        if (($respRowItem['hasil'] ?? '') === 'Dibatalkan') {
            continue;
        }
        $closedAt = strtotime((string) $respRowItem['respon_at']);
        $bestIndex = null;
        $bestGap = 11;
        foreach (($timeline ?? []) as $respIndex => $respItem) {
            if (isset($usedIndexes[$respIndex])) {
                continue;
            }
            $gap = abs(strtotime((string) ($respItem['tgl_dibuat'] ?? '')) - $closedAt);
            if ($gap < $bestGap) {
                $bestGap = $gap;
                $bestIndex = $respIndex;
            }
        }
        if ($bestIndex !== null) {
            $usedIndexes[$bestIndex] = true;
            $respNoteByIndex[$bestIndex] = ['text' => $respNoteText($respRowItem, $status), 'class' => $status['class']];
        }
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
                                    <?= h(date('d-m-Y H:i', $createdAt)) ?>
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
                            <?php if (!empty($respNoteByIndex[$timelineIndex])): ?>
                                <div class="crf-timeline-response is-<?= h($respNoteByIndex[$timelineIndex]['class']) ?>"><i class="bi bi-stopwatch" aria-hidden="true"></i> <?= h($respNoteByIndex[$timelineIndex]['text']) ?></div>
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
                            <?php if ($respPendingNote !== null): ?>
                                <div class="crf-timeline-response is-<?= h($respPendingNote['class']) ?>"><i class="bi bi-stopwatch" aria-hidden="true"></i> <?= h($respPendingNote['text']) ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endif; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>
<?php
unset($timelineKeep, $timelineOlder, $timelineIndex, $activityLabels, $pendingStep, $workflowStage, $requestStatus, $respNoteByIndex, $respPendingNote, $respRowsAll, $respRoleShort, $respNoteText, $usedIndexes, $respRowItem, $respIndex, $respItem);
