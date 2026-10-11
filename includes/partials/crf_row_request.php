<?php
/**
 * Partial: sel "Pengajuan" dan "Isi Pengajuan" pada tabel daftar CRF.
 * Dipakai bersama di Dashboard Admin, CMO, Handler, Kadep, dan Pengajuan Saya.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $row
 *
 * Variabel OPSIONAL:
 *   bool   $rowShowDepartment  tampilkan departemen pemohon (default true)
 *   bool   $rowShowHandler     tampilkan handler di bawah isi (default false)
 *   string $rowCategoryEmpty   label bila kategori kosong (default 'Lainnya')
 */
$rowShowDepartment = $rowShowDepartment ?? true;
$rowShowHandler = $rowShowHandler ?? false;
$rowDate = !empty($row['tgl_pengajuan']) ? date('d-m-Y', strtotime($row['tgl_pengajuan'])) : null;
$rowMeta = array_filter([
    $rowShowDepartment ? ($row['dept_asal'] ?? null) : null,
    $rowDate,
]);
?>
<td data-label="Pengajuan">
    <div class="crf-row-request">
        <strong class="crf-row-name"><?= h($row['nama_pemohon'] ?? '-') ?></strong>
        <span class="crf-row-register"><?= h($row['nomor_register'] ?? '-') ?></span>
        <?php if ($rowMeta): ?>
            <span class="crf-row-meta">
                <?php if ($rowDate !== null): ?><i class="bi bi-calendar3"></i><?php endif; ?>
                <?= h(implode(' · ', array_reverse($rowMeta))) ?>
            </span>
        <?php endif; ?>
    </div>
</td>
<td data-label="Isi Pengajuan">
    <div class="crf-row-content">
        <span class="crf-request-category-chip"><?= h(crfCategoryName($row, $rowCategoryEmpty ?? 'Lainnya')) ?></span>
        <div class="crf-row-description" title="<?= h($row['deskripsi_perubahan'] ?? '') ?>"><?= h($row['deskripsi_perubahan'] ?? '-') ?></div>
        <?php if ($rowShowHandler): ?>
            <div class="crf-row-handler <?= empty($row['pic_id']) ? 'is-empty' : '' ?>">
                <i class="bi <?= empty($row['pic_id']) ? 'bi-person-dash' : 'bi-person-check' ?>"></i>
                <?= empty($row['pic_id']) ? 'Belum ada PIC CRF' : 'PIC CRF: ' . h($row['pic_nama']) ?>
            </div>
        <?php endif; ?>
    </div>
</td>
<?php
unset($rowShowDepartment, $rowShowHandler, $rowCategoryEmpty, $rowDate, $rowMeta);
