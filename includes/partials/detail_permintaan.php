<?php
/**
 * Partial: Isi permintaan (rincian, alasan, manfaat, dampak, lampiran).
 * Ditampilkan sebagai teks bacaan; isian kosong tidak ditampilkan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *   array $attachments
 *
 * Variabel OPSIONAL:
 *   string $sectionTitle
 */

$sectionTitle = $sectionTitle ?? 'Isi Permintaan';

// Dokumen hasil UAT (kategori 'uat') ditampilkan terpisah dari lampiran pengajuan.
$requestAttachments = array_values(array_filter($attachments ?? [], static fn(array $file): bool => empty($file['kategori'])));
$uatDocuments = array_values(array_filter($attachments ?? [], static fn(array $file): bool => ($file['kategori'] ?? '') === 'uat'));
$attachmentIcon = static function (array $file): string {
    $ext = strtolower(pathinfo((string) ($file['nama_asli'] ?? ''), PATHINFO_EXTENSION));
    return match (true) {
        $ext === 'pdf' => 'bi-file-earmark-pdf text-danger',
        in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => 'bi-file-earmark-image text-info',
        in_array($ext, ['xls', 'xlsx', 'csv'], true) => 'bi-file-earmark-excel text-success',
        in_array($ext, ['doc', 'docx'], true) => 'bi-file-earmark-word text-primary',
        in_array($ext, ['zip', 'rar', '7z'], true) => 'bi-file-earmark-zip text-warning',
        default => 'bi-file-earmark text-secondary',
    };
};
$attachmentSize = static function (array $file): string {
    $bytes = (int) ($file['ukuran_file'] ?? 0);
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
};

// Blok teks bacaan: [label, isi]. Isian kosong dilewati.
$filled = static fn($v): bool => trim((string) $v) !== '';
// Semua pernyataan dampak yang dicentang; CRF lama memakai satu pilihan tunggal.
$impactOptions = crfImpactOptions();
$impactKeys = crfDampakKunci($crf);
$impactLabels = crfDampakLabels($crf)
    ?: array_values(array_filter([crfImpactLabel($crf['kategori_dampak'] ?? null)]));
$impactWeights = (array) (crfUrgencyConfig()['bobot'] ?? []);
// Skor berbobot hanya untuk peran internal (Pemohon cukup melihat Level Urgensi).
$showUrgencyScore = getCrfRole() !== 'pemohon'
    && isset($crf['skor_urgensi']) && $crf['skor_urgensi'] !== '' && $filled($crf['dampak_dipilih'] ?? '');
$proseBlocks = [];
foreach ([
    'Rincian perubahan' => $crf['deskripsi_perubahan'] ?? '',
    'Alasan permohonan' => $crf['alasan'] ?? '',
    'Manfaat yang diharapkan' => $crf['manfaat'] ?? '',
] as $label => $value) {
    if ($filled($value)) {
        $proseBlocks[$label] = $value;
    }
}

// Rincian singkat (tipe, biaya, kategori) sebagai baris meta.
$metaItems = [];
if ($filled($crf['jenis_pengajuan'] ?? '')) {
    $metaItems['Tipe pengajuan'] = (string) $crf['jenis_pengajuan'];
}
$budgetText = budgetTypeLabel($crf['jenis_anggaran'] ?? null);
if (isset($crf['nilai_anggaran']) && $crf['nilai_anggaran'] !== '' && $crf['nilai_anggaran'] !== null) {
    $budgetText .= ' — ' . formatRupiah($crf['nilai_anggaran']);
}
if ($filled($budgetText)) {
    $metaItems['Biaya / anggaran'] = $budgetText;
}
if ($filled($crf['detail_kategori_perubahan'] ?? '')) {
    $metaItems['Detail kategori'] = (string) $crf['detail_kategori_perubahan'];
}
// Meter skor (hanya peran internal; baris CRF lama tidak punya skor).
$scoreInfo = null;
if ($showUrgencyScore) {
    $cfgUrgensi = crfUrgencyConfig();
    $hasilSkor = crfHitungUrgensi($impactKeys);
    $scoreMax = (float) ($cfgUrgensi['maks'] ?? 100);
    $scoreInfo = [
        'skor' => (float) $crf['skor_urgensi'],
        'maks' => $scoreMax,
        'level' => crfUrgensiSistem($crf),
        // Level yang berlaku sekarang (bisa sudah diubah CMO/Admin lewat Forum).
        'levelFinal' => crfEffectiveUrgency($crf),
        'persen' => $scoreMax > 0 ? max(0, min(100, (float) $crf['skor_urgensi'] / $scoreMax * 100)) : 0,
        'ambangNormal' => (float) ($cfgUrgensi['ambang']['Normal'] ?? 10),
        'ambangTinggi' => (float) ($cfgUrgensi['ambang']['Tinggi'] ?? 35),
        'dikunci' => !empty($hasilSkor['dikunci']),
    ];
}
$impactCatatan = $showUrgencyScore ? crfDampakCatatan($impactKeys, (string) ($crf['jenis_pengajuan'] ?? '')) : [];
$formatSkor = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');

$renderFiles = static function (array $files, bool $showDate) use ($attachmentIcon, $attachmentSize): void {
    ?>
    <ul class="crf-file-list">
        <?php foreach ($files as $file): ?>
            <li class="crf-file-item">
                <i class="bi <?= h($attachmentIcon($file)) ?>" aria-hidden="true"></i>
                <span class="crf-file-name" title="<?= h($file['nama_asli'] ?? '-') ?>"><?= h($file['nama_asli'] ?? '-') ?></span>
                <small class="crf-file-size">
                    <?= h($attachmentSize($file)) ?>
                    <?php if ($showDate && !empty($file['tgl_unggah'])): ?>· <?= h(date('d-m-Y H:i', strtotime($file['tgl_unggah']))) ?><?php endif; ?>
                </small>
                <span class="crf-file-actions">
                    <?php if (!empty($file['path_file'])): ?>
                        <a href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>&amp;inline=1" target="_blank" rel="noopener"
                           class="btn btn-sm btn-crf-outline" aria-label="Lihat <?= h($file['nama_asli'] ?? '') ?>">
                            <i class="bi bi-eye" aria-hidden="true"></i> Lihat
                        </a>
                    <?php endif; ?>
                    <a href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>" class="btn btn-sm btn-crf-outline"
                       aria-label="Unduh <?= h($file['nama_asli'] ?? '') ?>">
                        <i class="bi bi-download" aria-hidden="true"></i> Unduh
                    </a>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
};
?>
<section class="crf-section crf-detail-card crf-request-card mb-4" aria-labelledby="crf-request-title">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-card-checklist" aria-hidden="true"></i></span>
        <h2 id="crf-request-title"><?= h($sectionTitle) ?></h2>
    </div>

    <div class="crf-section-body">
        <?php foreach ($proseBlocks as $label => $value): ?>
            <div class="crf-prose-block">
                <h3 class="crf-prose-label"><?= h($label) ?></h3>
                <p class="crf-prose-text"><?= nl2br(h($value)) ?></p>
            </div>
        <?php endforeach; ?>

        <?php if ($impactLabels || $filled($crf['dampak'] ?? '')): ?>
            <div class="crf-prose-block crf-impact-block">
                <h3 class="crf-prose-label">Dampak<?= count($impactLabels) > 1 ? ' (' . count($impactLabels) . ' pernyataan)' : '' ?></h3>
                <?php if ($impactKeys): ?>
                    <ul class="crf-impact-chips">
                        <?php foreach ($impactKeys as $impactKey): ?>
                            <li class="crf-impact-chip">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                <span class="crf-impact-chip-text"><?= h($impactOptions[$impactKey] ?? $impactKey) ?></span>
                                <?php if ($scoreInfo !== null): ?>
                                    <b class="crf-impact-weight" title="Bobot pernyataan">+<?= h($formatSkor((float) ($impactWeights[$impactKey] ?? 0))) ?></b>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($impactLabels): ?>
                    <ul class="crf-impact-chips">
                        <?php foreach ($impactLabels as $impactText): ?>
                            <li class="crf-impact-chip">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                <span class="crf-impact-chip-text"><?= h($impactText) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($scoreInfo !== null && $impactCatatan): ?>
                    <div class="crf-score-flag" role="note">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <div>
                            <strong>Perlu dicek: pilihan dampak mungkin tidak konsisten.</strong>
                            <?php foreach ($impactCatatan as $catatanDampak): ?>
                                <span><?= h($catatanDampak) ?></span>
                            <?php endforeach; ?>
                            <span>Cocokkan dengan isi permintaan sebelum menetapkan level final.</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($scoreInfo !== null): ?>
                    <div class="crf-score" role="group" aria-label="Skor urgensi">
                        <div class="crf-score-head">
                            <span class="crf-score-title">Skor urgensi (sistem)</span>
                            <strong class="crf-score-value"><?= h($formatSkor($scoreInfo['skor'])) ?><small> / <?= h($formatSkor($scoreInfo['maks'])) ?></small></strong>
                            <?php if ($scoreInfo['level'] !== null): ?>
                                <span class="crf-badge <?= h(levelBadgeClass($scoreInfo['level'])) ?>"><?= h($scoreInfo['level']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div
                            class="crf-score-bar is-<?= h(mb_strtolower((string) $scoreInfo['level'])) ?>"
                            role="meter"
                            aria-valuemin="0"
                            aria-valuemax="<?= h($formatSkor($scoreInfo['maks'])) ?>"
                            aria-valuenow="<?= h($formatSkor($scoreInfo['skor'])) ?>"
                            aria-valuetext="Skor <?= h($formatSkor($scoreInfo['skor'])) ?> dari <?= h($formatSkor($scoreInfo['maks'])) ?>, urgensi <?= h((string) $scoreInfo['level']) ?>"
                        >
                            <span class="crf-score-fill" style="width: <?= h(number_format($scoreInfo['persen'], 1, '.', '')) ?>%"></span>
                            <i class="crf-score-mark" style="left: <?= h(number_format($scoreInfo['ambangNormal'] / max(1, $scoreInfo['maks']) * 100, 1, '.', '')) ?>%" aria-hidden="true"></i>
                            <i class="crf-score-mark" style="left: <?= h(number_format($scoreInfo['ambangTinggi'] / max(1, $scoreInfo['maks']) * 100, 1, '.', '')) ?>%" aria-hidden="true"></i>
                        </div>
                        <?php if ($scoreInfo['levelFinal'] !== null && $scoreInfo['level'] !== null && $scoreInfo['levelFinal'] !== $scoreInfo['level']): ?>
                            <p class="crf-score-legend">
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                Level urgensi diubah dari <strong><?= h($scoreInfo['level']) ?></strong>
                                ke <span class="crf-badge <?= h(levelBadgeClass($scoreInfo['levelFinal'])) ?>"><?= h($scoreInfo['levelFinal']) ?></span>
                                lewat keputusan Forum. Skor di atas adalah hitungan sistem saat pengajuan.
                            </p>
                        <?php endif; ?>
                        <p class="crf-score-legend">
                            Rendah &lt; <?= h($formatSkor($scoreInfo['ambangNormal'])) ?>
                            &middot; Normal <?= h($formatSkor($scoreInfo['ambangNormal'])) ?> sampai &lt; <?= h($formatSkor($scoreInfo['ambangTinggi'])) ?>
                            &middot; Tinggi &ge; <?= h($formatSkor($scoreInfo['ambangTinggi'])) ?>
                            <?php if ($scoreInfo['dikunci']): ?>
                                <span class="crf-score-lock"><i class="bi bi-lock-fill" aria-hidden="true"></i> Dikunci Tinggi oleh pernyataan kritis</span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if ($filled($crf['dampak'] ?? '')): ?>
                    <p class="crf-prose-text"><?= nl2br(h($crf['dampak'])) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($filled($crf['saran_alternatif'] ?? '')): ?>
            <div class="crf-prose-block">
                <h3 class="crf-prose-label">Saran alternatif</h3>
                <p class="crf-prose-text"><?= nl2br(h($crf['saran_alternatif'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($metaItems): ?>
            <dl class="crf-meta-row">
                <?php foreach ($metaItems as $label => $value): ?>
                    <div>
                        <dt><?= h($label) ?></dt>
                        <dd><?= h($value) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <div class="crf-prose-block crf-prose-files">
            <h3 class="crf-prose-label">Bukti dan informasi pendukung</h3>
            <?php if (empty($requestAttachments)): ?>
                <p class="crf-prose-text text-muted">Tidak ada file yang dilampirkan.</p>
            <?php else: ?>
                <?php $renderFiles($requestAttachments, false); ?>
            <?php endif; ?>
        </div>

        <?php if ($uatDocuments): ?>
            <div class="crf-prose-block crf-prose-files">
                <h3 class="crf-prose-label">Dokumen hasil UAT</h3>
                <?php $renderFiles($uatDocuments, true); ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php
unset($sectionTitle, $attachmentIcon, $attachmentSize, $filled, $impactLabels, $impactOptions, $impactKeys, $impactWeights, $impactKey, $scoreInfo, $cfgUrgensi, $hasilSkor, $scoreMax, $formatSkor, $impactCatatan, $catatanDampak, $showUrgencyScore, $impactText, $proseBlocks, $metaItems, $budgetText, $renderFiles, $label, $value);
