<?php
/**
 * user/form_crf.php
 * ---------------------------------------------------------------
 * Halaman Form CRF untuk user yang sudah login.
 * Data user aktif diambil melalui getCurrentUser().
 * Informasi nama, kontak, departemen, dan divisi terisi otomatis dari
 * akun aktif. Data Draft atau input sebelumnya tetap dipertahankan.
 * Field otomatis seperti Hari/Tanggal, Kepada, dan Nomor Register
 * diisi oleh sistem.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$pdo  = getConnection();
$user = getCurrentUser(true);

// Cek apakah sedang membuka Draft
$draftId = (int) ($_GET['id'] ?? 0);
$draftData = null;

if ($draftId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM crf_pengajuan
        WHERE id = :id
          AND pengguna_id = :pengguna_id
          AND status IN ('Draft', 'Perlu Revisi')
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $draftId,
        'pengguna_id' => $user['id']
    ]);

    $draftData = $stmt->fetch();

    // Kalau draft tidak ditemukan / bukan milik user
    if (!$draftData) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Draft tidak ditemukan atau tidak dapat diakses.'
        ];

        header('Location: pengajuan_saya.php');
        exit;
    }
}

/*
 * Kategori CRF dinamis.
 */
$crfCategoryOptions = crfCategories($pdo);

// Kategori lama yang sudah dinonaktifkan tetap tampil agar draft tidak kehilangan pilihan.
$selectedCategoryId = (int) ($_SESSION['old_crf']['kategori_id'] ?? ($draftData['kategori_id'] ?? 0));
if ($selectedCategoryId > 0 && !in_array($selectedCategoryId, array_map('intval', array_column($crfCategoryOptions, 'id')), true)) {
    $selectedCategory = findCrfCategory($pdo, $selectedCategoryId);
    if ($selectedCategory) {
        $selectedCategory['nama'] .= ' (nonaktif)';
        $crfCategoryOptions[] = $selectedCategory;
    }
}

$today = new DateTime();
$tanggalDisplay   = formatTanggalIndonesia($today);
$previewRequestNo = !empty($draftData['nomor_register'])
    ? $draftData['nomor_register']
    : 'Dibuat otomatis setelah CRF disubmit';

$toDepartment = 'Departemen Operasional';
$toDivision   = 'Divisi Otomasi';

// Gunakan nomor profil; bila kosong, pertahankan nomor draft atau nomor
// terakhir yang pernah disimpan pada pengajuan milik akun ini.
$phone = trim((string) ($user['no_wa'] ?? ''));
if ($phone === '') {
    $phone = trim((string) ($draftData['telepon'] ?? ''));
}
if ($phone === '') {
    $phone = trim((string) ($_SESSION['old_crf']['telepon'] ?? ''));
}
if ($phone === '') {
    $stmt = $pdo->prepare("
        SELECT telepon
        FROM crf_pengajuan
        WHERE pengguna_id = :pengguna_id
          AND telepon IS NOT NULL
          AND TRIM(telepon) <> ''
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute(['pengguna_id' => $user['id']]);
    $phone = trim((string) ($stmt->fetchColumn() ?: ''));
}

// Ambil pesan flash (sukses/gagal) dari proses submit.
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Data form: gunakan data Draft jika sedang membuka Draft,
// atau gunakan data lama dari session jika ada error submit.
$old = array_merge(
    $draftData ?? [],
    $_SESSION['old_crf'] ?? [],
    [
        'nama_pemohon' => $user['nama'] ?? '',
        'telepon' => $phone,
        'email' => $user['email'] ?? '',
        'dept_asal' => $user['dept'] ?? '',
        'divisi_asal' => $user['divisi'] ?? '',
    ]
);
unset($_SESSION['old_crf']);

/*
 * Status penyimpanan form (ditampilkan di samping tombol & dipakai untuk
 * konfirmasi browser saat meninggalkan halaman yang belum disimpan).
 */
if (($draftData['status'] ?? '') === 'Perlu Revisi') {
    $saveState = 'revision';
    $saveStateText = 'Perlu revisi · belum dikirim ulang';
} elseif ($draftData) {
    $saveState = 'saved';
    $saveStateText = 'Tersimpan sebagai Draft · ' . date('d-m-Y H:i', strtotime($draftData['tgl_diubah'] ?? $draftData['tgl_dibuat'] ?? 'now'));
} elseif (trim((string) ($old['deskripsi_perubahan'] ?? '')) !== '') {
    // Isian gagal validasi: belum pernah tersimpan.
    $saveState = 'unsaved';
    $saveStateText = 'Belum disimpan · klik Simpan Draft atau Ajukan CRF';
} else {
    $saveState = 'new';
    $saveStateText = 'Belum ada isian';
}

$pageTitle = 'Form CRF';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-form-page">
  <div class="container">

    <div class="crf-page-header crf-page-banner">
      <span class="crf-page-eyebrow">PORTAL CRF · PENGAJUAN</span>
      <div>
        <h1>Change Request Form (CRF)</h1>
        <p>Silakan lengkapi form di bawah sesuai tipe pengajuan Anda.</p>
      </div>
    </div>

    <?php if ($flash): ?>
        <div
            class="alert alert-<?= h($flash['type']) ?>"
            style="white-space: pre-line;"
        >
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div id="validationAlert" class="alert alert-danger crf-alert d-none" role="alert" aria-live="polite">
      <strong>Mohon periksa kembali data berikut:</strong>
      <ul id="validationList" class="mb-0 mt-2"></ul>
    </div>

    <form action="../actions/submit_crf.php" method="POST" enctype="multipart/form-data" id="crfForm" novalidate
          data-unsaved-guard data-save-state="<?= h($saveState) ?>">

    <input
        type="hidden"
        name="id"
        value="<?= (int) ($draftData['id'] ?? 0) ?>"
    >

        <?= csrfField() ?>

      <!-- ============================================================ -->
      <!-- 1. INFORMASI PENGAJUAN                                        -->
      <!-- ============================================================ -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">1</span>
          <h2>Informasi Pengajuan</h2>
        </div>
        <div class="crf-section-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label for="nama_pemohon" class="crf-field-label">Nama Lengkap<span class="text-danger">*</span>
              </label>
              <input
                type="text"
                class="form-control"
                id="nama_pemohon"
                name="nama_pemohon"
                placeholder="Masukkan nama lengkap"
                value="<?= h($old['nama_pemohon'] ?? '') ?>"
                <?= trim((string) ($old['nama_pemohon'] ?? '')) !== '' ? 'readonly' : '' ?>
                required
              >
              <?php if (trim((string) ($old['nama_pemohon'] ?? '')) !== ''): ?>
                <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
              <?php else: ?>
                <div class="crf-readonly-note"><i class="bi bi-info-circle"></i>Belum terisi dari akun; mohon lengkapi</div>
              <?php endif; ?>
            </div>

            <div class="col-md-4">
              <label for="telepon" class="crf-field-label">No. Handphone/WA<span class="text-danger">*</span>
              </label>
              <?php
              // Terkunci hanya bila nomornya sudah ada (dari akun SIAP atau pengajuan
              // sebelumnya). Bila kosong, pemohon harus bisa mengisinya sendiri.
              $phoneLocked = trim((string) ($old['telepon'] ?? '')) !== '';
              ?>
              <input
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                maxlength="25"
                class="form-control"
                id="telepon"
                name="telepon"
                placeholder="Masukkan nomor handphone/WA"
                value="<?= h($old['telepon'] ?? '') ?>"
                <?= $phoneLocked ? 'readonly' : '' ?>
                required
              >
              <?php if ($phoneLocked): ?>
                <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun atau data pengajuan sebelumnya</div>
              <?php else: ?>
                <div class="crf-readonly-note"><i class="bi bi-info-circle"></i>Isi nomor yang bisa dihubungi; akan diingat untuk pengajuan berikutnya</div>
              <?php endif; ?>
            </div>

            <div class="col-md-4">
              <label for="email" class="crf-field-label">Email<span class="text-danger">*</span>
              </label>
              <input
                type="email"
                class="form-control"
                id="email"
                name="email"
                placeholder="Masukkan alamat email"
                value="<?= h($old['email'] ?? '') ?>"
                <?= trim((string) ($old['email'] ?? '')) !== '' ? 'readonly' : '' ?>
                required
              >
              <?php if (trim((string) ($old['email'] ?? '')) !== ''): ?>
                <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
              <?php else: ?>
                <div class="crf-readonly-note"><i class="bi bi-info-circle"></i>Belum terisi dari akun; mohon lengkapi</div>
              <?php endif; ?>
            </div>
            

            <div class="col-md-6">
              <label for="info_tanggal" class="crf-field-label">Hari/Tanggal<span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" id="info_tanggal" value="<?= h($tanggalDisplay) ?>" readonly>
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis oleh sistem</div>
            </div>
            <div class="col-md-6">
              <label for="info_nomor_register" class="crf-field-label">Nomor Register<span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" id="info_nomor_register" value="<?= h($previewRequestNo) ?>" readonly>
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Nomor dibuat sistem setelah CRF disubmit</div>
            </div>
            <div class="col-md-6">
              <label for="info_kepada" class="crf-field-label">Kepada<span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" id="info_kepada" value="<?= h($toDepartment . ' (' . $toDivision . ')') ?>" readonly>
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Tujuan pengajuan CRF ditetapkan sistem</div>
            </div>
            <div class="col-md-6">
              <label class="crf-field-label">Dari<span class="text-danger">*</span>
              </label>

              <div class="row g-2">
                <div class="col-12">
                  <label for="dept_asal" class="form-label">Departemen<span class="text-danger">*</span>
                  </label>
                  <input
                    type="text"
                    class="form-control"
                    id="dept_asal"
                    name="dept_asal"
                    placeholder="Masukkan nama departemen"
                    value="<?= h($old['dept_asal'] ?? '') ?>"
                    <?= trim((string) ($old['dept_asal'] ?? '')) !== '' ? 'readonly' : '' ?>
                    required
                  >
                  <?php if (trim((string) ($old['dept_asal'] ?? '')) !== ''): ?>
                    <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
                  <?php else: ?>
                    <div class="crf-readonly-note"><i class="bi bi-info-circle"></i>Belum terisi dari akun; mohon lengkapi</div>
                  <?php endif; ?>
                </div>

                <div class="col-12">
                  <label for="divisi_asal" class="form-label">Divisi<span class="text-danger">*</span>
                  </label>
                  <input
                    type="text"
                    class="form-control"
                    id="divisi_asal"
                    name="divisi_asal"
                    placeholder="Masukkan nama divisi"
                    value="<?= h($old['divisi_asal'] ?? '') ?>"
                    <?= trim((string) ($old['divisi_asal'] ?? '')) !== '' ? 'readonly' : '' ?>
                    required
                  >
                  <?php if (trim((string) ($old['divisi_asal'] ?? '')) !== ''): ?>
                    <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
                  <?php else: ?>
                    <div class="crf-readonly-note"><i class="bi bi-info-circle"></i>Belum terisi dari akun; mohon lengkapi</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ============================================================ -->
      <!-- 2. CHANGE REQUEST DESCRIPTION                                 -->
      <!-- ============================================================ -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">2</span>
          <h2>Change Request Description</h2>
        </div>
        <div class="crf-section-body">

          <div class="mb-3" style="max-width: 360px;">
            <label for="jenis_pengajuan" class="crf-field-label">Tipe Pengajuan<span class="text-danger">*</span></label>
            <select class="form-select" id="jenis_pengajuan" name="jenis_pengajuan" required>
              <option value="">Pilih tipe pengajuan</option>
              <?php foreach (crfRequestTypeOptions() as $requestType): ?>
                <option
                  value="<?= h($requestType) ?>"
                  <?= ($old['jenis_pengajuan'] ?? '') === $requestType ? 'selected' : '' ?>
                ><?= h($requestType) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="deskripsi_perubahan" class="crf-field-label">Rincian Permohonan Perubahan<span class="text-danger">*</span></label>
            <p class="crf-hint">Silakan tulis penjelasan yang lengkap, jelas, dan rinci mengenai permohonan perubahan yang disampaikan.</p>
            <textarea class="form-control" id="deskripsi_perubahan" name="deskripsi_perubahan" required><?= h($old['deskripsi_perubahan'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label for="manfaat" class="crf-field-label">Benefit dari Perubahan yang Diharapkan<span class="text-danger">*</span></label>
            <p class="crf-hint">Silakan tulis manfaat yang akan diperoleh setelah dilakukan perubahan.</p>
            <textarea class="form-control" id="manfaat" name="manfaat" required><?= h($old['manfaat'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <?php
            // Penilaian urgensi berbobot dilakukan di server (config/urgensi.php).
            // Pemohon TIDAK diberi tahu bobot, kelompok, atau hasilnya, agar tidak
            // selalu memilih dampak tertinggi. Urutan ditampilkan netral (abjad).
            $dampakTerpilih = crfDampakDipilih($old['dampak_dipilih'] ?? ($old['kategori_dampak'] ?? null));
            $dampakOpsi = crfImpactOptions();
            uasort($dampakOpsi, static fn(string $a, string $b): int => strcasecmp($a, $b));
            ?>
            <fieldset class="crf-impact-fieldset" id="dampak_dipilih_group" aria-describedby="dampak_dipilih_hint" data-bertentangan="<?= h(json_encode((object) (crfUrgencyConfig()['bertentangan'] ?? []), JSON_UNESCAPED_UNICODE)) ?>">
              <legend class="crf-field-label">Dampak Jika Tidak Dilakukan Perubahan<span class="text-danger">*</span></legend>
              <p class="crf-hint" id="dampak_dipilih_hint">
                Centang <strong>semua</strong> pernyataan yang sesuai dengan kondisi sebenarnya.
              </p>

              <div class="crf-impact-group">
                <?php foreach ($dampakOpsi as $impactKey => $impactLabel): ?>
                  <label class="crf-impact-option">
                    <input
                      type="checkbox"
                      name="dampak_dipilih[]"
                      value="<?= h($impactKey) ?>"
                      <?= in_array($impactKey, $dampakTerpilih, true) ? 'checked' : '' ?>
                    >
                    <span><?= h($impactLabel) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <label for="dampak" class="form-label mt-3">Penjelasan Dampak yang Dipilih<span class="text-danger">*</span></label>
            <textarea
              class="form-control"
              id="dampak"
              name="dampak"
              rows="4"
              placeholder="Jelaskan kondisi dan dampak yang dialami sesuai pilihan di atas..."
              required
            ><?= h($old['dampak'] ?? '') ?></textarea>
          </div>

          <div class="mb-0">
            <label for="alasan" class="crf-field-label">Alasan Permohonan Perubahan<span class="text-danger">*</span></label>
            <p class="crf-hint">Silakan tulis alasan perubahan yang Saudara sampaikan.</p>
            <textarea class="form-control" id="alasan" name="alasan" required><?= h($old['alasan'] ?? '') ?></textarea>
          </div>

        </div>
      </div>

      <!-- ============================================================ -->
      <!-- 3. BUKTI DAN INFORMASI PENDUKUNG                              -->
      <!-- ============================================================ -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">3</span>
          <h2>Bukti dan Informasi Pendukung</h2>
        </div>
        <div class="crf-section-body">
          <p class="crf-hint">Silakan sampaikan bukti berupa screenshot, printout, atau dokumen pendukung lain (opsional).</p>
            <input type="file" class="form-control" id="attachments" name="attachments[]" multiple
                 accept=".pdf,.jpg,.jpeg,.png" aria-label="Unggah bukti pendukung (PDF, JPG, PNG, maksimal 5 MB per file)">
          <div class="crf-readonly-note mt-2">
            Format yang didukung: PDF, JPG, JPEG, PNG. Maks. 5 MB per file.
          </div>
          <div id="file-list-preview" class="mt-2"></div>
        </div>
      </div>

      <!-- ============================================================ -->
      <!-- 4. BIAYA / ANGGARAN                                           -->
      <!-- ============================================================ -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">4</span>
          <h2>Biaya / Anggaran <small class="text-muted fw-normal">(opsional)</small></h2>
        </div>
        <div class="crf-section-body">
          <?php $budgetChoice = $old['jenis_anggaran'] ?? ''; ?>
          <p class="crf-hint">Bagian ini opsional. Kosongkan bila perubahan ini tidak membutuhkan anggaran. Jika ada biaya, pilih sumber anggarannya lalu isi nominal.</p>

          <div id="budgetOptions" class="crf-budget-options" role="radiogroup" aria-label="Pilihan biaya atau anggaran">
            <div class="form-check mb-2 crf-budget-option">
              <input class="form-check-input" type="radio" name="jenis_anggaran" id="budget_rkap" value="rkap"<?= ($old['jenis_anggaran'] ?? '') === 'rkap' ? 'checked' : '' ?>>
              <label class="form-check-label" for="budget_rkap">RKAP tahun berjalan</label>
            </div>
            <div class="form-check mb-2 crf-budget-option">
              <input class="form-check-input" type="radio" name="jenis_anggaran" id="budget_boq" value="boq_pks"<?= ($old['jenis_anggaran'] ?? '') === 'boq_pks' ? 'checked' : '' ?>>
              <label class="form-check-label" for="budget_boq">Tercantum dalam BoQ PKS</label>
            </div>
            <div class="form-check mb-3 crf-budget-option">
              <input class="form-check-input" type="radio" name="jenis_anggaran" id="budget_baru" value="anggaran_baru" <?= ($old['jenis_anggaran'] ?? '') === 'anggaran_baru' ? 'checked' : '' ?>>
              <label class="form-check-label" for="budget_baru">Akan diajukan anggaran baru</label>
            </div>
          </div>

          <label for="nilai_anggaran" class="crf-field-label">Nominal</label>
          <div class="input-group crf-budget-amount">
            <span class="input-group-text">Rp</span>
            <input type="number" min="0" step="1000" class="form-control"
                   id="nilai_anggaran" name="nilai_anggaran" placeholder="0" value="<?= h($old['nilai_anggaran'] ?? '') ?>" <?= $budgetChoice === '' ? 'disabled' : '' ?>>
          </div>
        </div>
      </div>

      <!-- ============================================================ -->
      <!-- 5. KATEGORI PERUBAHAN                                         -->
      <!-- ============================================================ -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">5</span>
          <h2>Kategori Perubahan</h2>
        </div>
        <div class="crf-section-body">
          <label for="kategori_perubahan" class="crf-field-label">Kategori CRF<span class="text-danger">*</span></label>
          <select class="form-select mb-1" id="kategori_perubahan" name="kategori_id" required style="max-width: 360px;">
            <option value="" disabled <?= $selectedCategoryId === 0 ? 'selected' : '' ?>>
              Pilih kategori...
            </option>
            <?php foreach ($crfCategoryOptions as $categoryOption): ?>
              <option
                value="<?= (int) $categoryOption['id'] ?>"
                data-legacy="<?= h($categoryOption['kategori_perubahan_lama']) ?>"
                <?= $selectedCategoryId === (int) $categoryOption['id'] ? 'selected' : '' ?>
              ><?= h($categoryOption['nama']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$crfCategoryOptions): ?>
            <div class="text-danger small mb-3">Belum ada Kategori CRF aktif. Hubungi Admin.</div>
          <?php else: ?>
            <div class="crf-readonly-note mb-3">Kategori menentukan PIC CRF yang akan memproses CRF Anda.</div>
          <?php endif; ?>

          <div id="category-detail-wrap" class="d-none">
            <label for="detail_kategori_perubahan" class="crf-field-label" id="category-detail-label">Detail Kategori<span class="text-danger">*</span></label>
            <input
                type="text"
                class="form-control"
                id="detail_kategori_perubahan"
                name="detail_kategori_perubahan"
                value="<?= h($old['detail_kategori_perubahan'] ?? '') ?>"
            >
          </div>
        </div>
      </div>

      <!-- ============================================================ -->
      <!-- 6. CHANGE REQUEST ACTION                                      -->
      <!-- ============================================================ -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">6</span>
          <h2>Change Request Action</h2>
        </div>
        <div class="crf-section-body">
          <label for="saran_alternatif" class="crf-field-label">Saran Alternatif <small class="text-muted fw-normal">(opsional)</small></label>
          <p class="crf-hint">Isi bila ada saran alternatif atas perubahan yang disampaikan.</p>
          <textarea class="form-control" id="saran_alternatif" name="saran_alternatif"><?= h($old['saran_alternatif'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- ============================================================ -->
      <!-- TOMBOL FORM (sticky)                                          -->
      <!-- ============================================================ -->
      <div class="crf-sticky-actions">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">

          <div class="crf-save-status is-<?= h($saveState) ?>" data-save-status role="status" aria-live="polite">
            <i class="bi" aria-hidden="true"></i>
            <span data-save-status-text><?= h($saveStateText) ?></span>
          </div>

          <div class="d-flex gap-2 crf-sticky-buttons">

          <button
            type="submit"
            name="action"
            value="draft"
            id="btnSaveDraft"
            class="btn btn-crf-outline px-4"
            formaction="../actions/save_draft.php"
            formnovalidate
          >
            <i class="bi bi-save2"></i>
            Simpan Draft
          </button>

          <button
            type="submit"
            name="action"
            value="submit"
            id="btnSubmitCrf"
            class="btn btn-crf-primary px-4"
            formaction="../actions/submit_crf.php"
            data-resubmission="<?= ($draftData['status'] ?? '') === 'Perlu Revisi' ? '1' : '0' ?>"
          >
            <i class="bi bi-send-check"></i>
            <?= ($draftData['status'] ?? '') === 'Perlu Revisi'
                ? 'Perbaiki & Kirim Ulang'
                : 'Ajukan CRF'
            ?>
          </button>

          </div>
        </div>
      </div>

    </form>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
