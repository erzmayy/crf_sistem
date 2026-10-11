<?php
/**
 * Partial: Ringkasan pengajuan (siapa, dari mana, ke mana, kapan).
 * Menggantikan daftar 11 baris dengan satu kartu ringkas. Status, urgensi,
 * dan kategori sudah ada di header halaman (detail_header.php).
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *
 * Variabel OPSIONAL (dipertahankan agar pemanggil lama tetap kompatibel):
 *   bool $showStatusInGrid  tidak dipakai lagi; status ada di header
 */

$unit = static function (?string $dept, ?string $div): string {
    $dept = trim((string) $dept);
    $div = trim((string) $div);

    return $dept === '' && $div === '' ? '' : ($dept . ($div !== '' ? ' (' . $div . ')' : ''));
};
$unitAsal = $unit($crf['dept_asal'] ?? '', $crf['divisi_asal'] ?? '');
$unitTujuan = $unit($crf['dept_tujuan'] ?? '', $crf['divisi_tujuan'] ?? '');
$kontak = array_filter([trim((string) ($crf['telepon'] ?? '')), trim((string) ($crf['email'] ?? ''))]);
$picNama = trim((string) ($crf['pic_nama'] ?? ''));
?>
<section class="crf-section crf-detail-card crf-summary-card mb-4" aria-labelledby="crf-summary-title">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-person-lines-fill" aria-hidden="true"></i></span>
        <h2 id="crf-summary-title">Ringkasan</h2>
    </div>

    <div class="crf-section-body">
        <dl class="crf-summary-grid">
            <div>
                <dt>Pemohon</dt>
                <dd>
                    <?= h($crf['nama_pemohon'] ?? '-') ?>
                    <?php if (!empty($crf['jabatan_pemohon'])): ?>
                        <span class="crf-summary-sub"><?= h($crf['jabatan_pemohon']) ?></span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Kontak</dt>
                <dd><?= $kontak ? h(implode(' · ', $kontak)) : '<span class="text-muted">-</span>' ?></dd>
            </div>
            <div>
                <dt>Unit asal</dt>
                <dd><?= $unitAsal !== '' ? h($unitAsal) : '<span class="text-muted">-</span>' ?></dd>
            </div>
            <div>
                <dt>Tujuan</dt>
                <dd><?= $unitTujuan !== '' ? h($unitTujuan) : '<span class="text-muted">-</span>' ?></dd>
            </div>
            <div>
                <dt>Diajukan</dt>
                <dd>
                    <?php if (!empty($crf['tgl_pengajuan'])): ?>
                        <?= h(formatTanggalIndonesia(new DateTime($crf['tgl_pengajuan']))) ?>
                    <?php else: ?>
                        <span class="text-muted">Belum diajukan</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>PIC CRF</dt>
                <dd><?= $picNama !== '' ? h($picNama) : '<span class="text-muted">Belum ada</span>' ?></dd>
            </div>
        </dl>
    </div>
</section>
<?php
unset($showStatusInGrid, $unit, $unitAsal, $unitTujuan, $kontak, $picNama);
