<?php
/**
 * Partial: Header halaman detail CRF (judul, subjek, status, aksi).
 * Dipakai semua halaman detail agar tampilannya seragam dan ringkas.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf, PDO $pdo
 *
 * Variabel OPSIONAL:
 *   string $dhEyebrow     keterangan peran/halaman di atas judul
 *   bool   $dhShowForum   tampilkan tombol Diskusi Forum (bawaan true)
 *   bool   $dhShowExport  tampilkan tombol Ekspor PDF (bawaan true)
 *   bool   $dhShowSla     tampilkan lencana SLA (bawaan true; false untuk Pemohon)
 *   string $dhNumber      nomor register yang ditampilkan (bawaan dari $crf)
 *   string $dhExtraHtml   tombol tambahan (sudah di-escape oleh pemanggil)
 *   string $dhLevelHint   keterangan saat lencana level disorot
 *   array|null $flash     pesan flash (dicetak di sini bila ada)
 */

$dhEyebrow = $dhEyebrow ?? 'Detail CRF';
$dhShowForum = $dhShowForum ?? true;
$dhShowExport = $dhShowExport ?? true;
$dhShowSla = $dhShowSla ?? true;
$dhNumber = $dhNumber ?? (string) ($crf['nomor_register'] ?? '');
$dhExtraHtml = $dhExtraHtml ?? '';
$dhLevelHint = $dhLevelHint ?? '';

$dhStatus = crfDisplayStatus($crf);
// Tampilan Pemohon (tanpa SLA): Forum bersifat internal, jadi tetap tampil sebagai verifikasi.
if (!$dhShowSla && ($dhStatus['key'] ?? '') === 'pembahasan') {
    $dhStatus = ['key' => 'review', 'label' => 'Menunggu Verifikasi', 'class' => 'badge-status-belum'];
}
$dhLevel = crfEffectiveUrgency($crf) ?: ($crf['level'] ?? null);
$dhSubject = trim((string) ($crf['deskripsi_perubahan'] ?? ''));
$dhSubject = $dhSubject !== '' ? mb_strimwidth(preg_replace('/\s+/u', ' ', $dhSubject), 0, 120, '…') : '';
$dhSla = (!empty($crf['sla_nilai']) && !empty($crf['sla_satuan']))
    ? rtrim(rtrim(number_format((float) $crf['sla_nilai'], 2, '.', ''), '0'), '.') . ' ' . $crf['sla_satuan']
    : null;
?>
<header class="crf-dh">
    <div class="crf-dh-head">
        <div class="crf-dh-heading">
    <p class="crf-dh-eyebrow">Portal CRF · <?= h($dhEyebrow) ?></p>
    <h1 class="crf-dh-title"><?= h($dhNumber !== '' ? $dhNumber : 'CRF #' . (int) $crf['id']) ?></h1>
    <?php if ($dhSubject !== ''): ?>
        <p class="crf-dh-subject"><?= h($dhSubject) ?></p>
    <?php endif; ?>
        </div>
        <div class="crf-dh-actions">
            <?php if ($dhShowForum) { require __DIR__ . '/forum_button.php'; } ?>
            <?php if ($dhShowExport): ?>
                <a href="../actions/export_crf.php?id=<?= (int) $crf['id'] ?>" class="btn btn-crf-outline" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Ekspor PDF
                </a>
            <?php endif; ?>
            <?= $dhExtraHtml ?>
        </div>
    </div>

    <div class="crf-dh-badges">

        <span class="crf-badge <?= h($dhStatus['class']) ?>"><?= h($dhStatus['label']) ?></span>
        <?php if ($dhLevel !== null && $dhLevel !== ''): ?>
            <span class="crf-badge <?= h(levelBadgeClass($dhLevel)) ?>"<?= $dhLevelHint !== '' ? ' title="' . h($dhLevelHint) . '"' : '' ?>>
                Urgensi <?= h(mb_strtolower((string) $dhLevel)) ?>
            </span>
        <?php else: ?>
            <span class="crf-badge crf-dh-badge-muted">Urgensi belum ditentukan</span>
        <?php endif; ?>
        <?php $dhCategory = crfCategoryName($crf, ''); ?>
        <?php if ($dhCategory !== ''): ?>
            <span class="crf-badge crf-dh-badge-muted">Kategori <?= h($dhCategory) ?></span>
        <?php endif; ?>
        <?php if ($dhShowSla && $dhSla !== null): ?>
            <span class="crf-badge crf-dh-badge-muted">SLA <?= h($dhSla) ?></span>
        <?php endif; ?>
    </div>
</header>

<?php if (!empty($flash)): ?>
    <div class="alert alert-<?= h($flash['type']) ?> crf-alert" role="alert">
        <?= h($flash['message']) ?>
    </div>
<?php endif; ?>
<?php
unset($dhEyebrow, $dhShowForum, $dhShowExport, $dhShowSla, $dhNumber,
    $dhExtraHtml, $dhLevelHint, $dhStatus, $dhLevel, $dhSubject, $dhSla, $dhCategory);
