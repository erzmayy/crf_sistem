<?php
/**
 * Partial: formulir unggah dokumen hasil UAT (bukti pengujian).
 * Dipakai di panel UAT CMO dan halaman detail Admin.
 *
 * Variabel yang HARUS sudah ada: array $crf.
 * Opsional: string $uatUploadBack ('admin' bila dipakai di halaman Admin; dipakai akun demo).
 */
$uatUploadBack = $uatUploadBack ?? '';
?>
<form action="../actions/uat_upload.php" method="POST" enctype="multipart/form-data" class="crf-uat-upload" data-loading-form>
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">
    <?php if ($uatUploadBack !== ''): ?>
        <input type="hidden" name="back" value="<?= h($uatUploadBack) ?>">
    <?php endif; ?>

    <label for="uat_files_<?= (int) $crf['id'] ?>" class="form-label fw-semibold">
        Dokumen hasil UAT
    </label>
    <div class="d-flex gap-2 flex-wrap align-items-start">
        <input
            type="file"
            id="uat_files_<?= (int) $crf['id'] ?>"
            name="uat_files[]"
            class="form-control"
            style="max-width: 420px;"
            multiple
            required
        >
        <button type="submit" class="btn btn-crf-outline">
            <i class="bi bi-upload"></i> Unggah Dokumen UAT
        </button>
    </div>
    <div class="form-text">Bukti pengujian (mis. screenshot atau berita acara). Maksimal 5 MB per berkas; jenis berkas sama dengan lampiran pengajuan.</div>
</form>
<?php unset($uatUploadBack); ?>
