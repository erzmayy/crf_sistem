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
        FROM change_requests
        WHERE id = :id
          AND user_id = :user_id
          AND status IN ('Draft', 'Perlu Revisi')
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $draftId,
        'user_id' => $user['id']
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

$today = new DateTime();
$tanggalDisplay   = formatTanggalIndonesia($today);
$previewRequestNo = !empty($draftData['request_number'])
    ? $draftData['request_number']
    : 'Dibuat otomatis setelah CRF disubmit';

$toDepartment = 'Departemen Operasional';
$toDivision   = 'Divisi Otomasi';

// Gunakan nomor profil; bila kosong, pertahankan nomor draft atau nomor
// terakhir yang pernah disimpan pada pengajuan milik akun ini.
$phone = trim((string) ($user['no_wa'] ?? ''));
if ($phone === '') {
    $phone = trim((string) ($draftData['phone'] ?? ''));
}
if ($phone === '') {
    $phone = trim((string) ($_SESSION['old_crf']['phone'] ?? ''));
}
if ($phone === '') {
    $stmt = $pdo->prepare("
        SELECT phone
        FROM change_requests
        WHERE user_id = :user_id
          AND phone IS NOT NULL
          AND TRIM(phone) <> ''
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $user['id']]);
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
        'full_name' => $user['nama'] ?? '',
        'phone' => $phone,
        'email' => $user['email'] ?? '',
        'from_department' => $user['dept'] ?? '',
        'from_division' => $user['divisi'] ?? '',
    ]
);
unset($_SESSION['old_crf']);

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

    <form action="../actions/submit_crf.php" method="POST" enctype="multipart/form-data" id="crfForm" novalidate>

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
          <div class="mb-3">
            <label for="request_type" class="crf-field-label">Tipe Pengajuan<span class="text-danger">*</span></label>
            <select class="form-select" id="request_type" name="request_type" required>
              <option value="">Pilih tipe pengajuan</option>
              <?php foreach (crfRequestTypeOptions() as $requestType): ?>
                <option
                  value="<?= h($requestType) ?>"
                  <?= ($old['request_type'] ?? '') === $requestType ? 'selected' : '' ?>
                ><?= h($requestType) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label for="full_name" class="crf-field-label">Nama Lengkap<span class="text-danger">*</span>
              </label>
              <input
                type="text"
                class="form-control"
                id="full_name"
                name="full_name"
                placeholder="Masukkan nama lengkap"
                value="<?= h($old['full_name'] ?? '') ?>"
                readonly
                required
              >
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
            </div>

            <div class="col-md-4">
              <label for="phone" class="crf-field-label">No. Handphone/WA<span class="text-danger">*</span>
              </label>
              <input
                type="text"
                class="form-control"
                id="phone"
                name="phone"
                placeholder="Masukkan nomor handphone/WA"
                value="<?= h($old['phone'] ?? '') ?>"
                readonly
                required
              >
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun atau data pengajuan sebelumnya</div>
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
                readonly
                required
              >
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
            </div>
            
            <div class="col-md-6">
              <label class="crf-field-label">Hari/Tanggal<span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" value="<?= h($tanggalDisplay) ?>" readonly>
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis oleh sistem</div>
            </div>
            <div class="col-md-6">
              <label class="crf-field-label">Nomor Register<span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" value="<?= h($previewRequestNo) ?>" readonly>
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Nomor dibuat sistem setelah CRF disubmit</div>
            </div>
            <div class="col-md-6">
              <label class="crf-field-label">Kepada<span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" value="<?= h($toDepartment . ' (' . $toDivision . ')') ?>" readonly>
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Tujuan pengajuan CRF pada prototype ini tetap</div>
            </div>
            <div class="col-md-6">
              <label class="crf-field-label">Dari<span class="text-danger">*</span>
              </label>

              <div class="row g-2">
                <div class="col-12">
                  <label for="from_department" class="form-label">Departemen<span class="text-danger">*</span>
                  </label>
                  <input
                    type="text"
                    class="form-control"
                    id="from_department"
                    name="from_department"
                    placeholder="Masukkan nama departemen"
                    value="<?= h($old['from_department'] ?? '') ?>"
                    readonly
                    required
                  >
                  <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
                </div>

                <div class="col-12">
                  <label for="from_division" class="form-label">Divisi<span class="text-danger">*</span>
                  </label>
                  <input
                    type="text"
                    class="form-control"
                    id="from_division"
                    name="from_division"
                    placeholder="Masukkan nama divisi"
                    value="<?= h($old['from_division'] ?? '') ?>"
                    readonly
                    required
                  >
                  <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Diisi otomatis dari akun Anda</div>
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

          <div class="mb-3">
            <label for="change_description" class="crf-field-label">Rincian Permohonan Perubahan<span class="text-danger">*</span></label>
            <p class="crf-hint">Silakan tulis penjelasan yang lengkap, jelas, dan rinci mengenai permohonan perubahan yang disampaikan.</p>
            <textarea class="form-control" id="change_description" name="change_description" required><?= h($old['change_description'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label for="benefit" class="crf-field-label">Benefit dari Perubahan yang Diharapkan<span class="text-danger">*</span></label>
            <p class="crf-hint">Silakan tulis benefit yang akan diperoleh setelah dilakukan perubahan.</p>
            <textarea class="form-control" id="benefit" name="benefit" required><?= h($old['benefit'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label for="impact_category" class="crf-field-label">Dampak Jika Tidak Dilakukan Perubahan<span class="text-danger">*</span></label>
            <select class="form-select" id="impact_category" name="impact_category" required>
              <option value="">Pilih dampak</option>
              <?php foreach (crfImpactOptions() as $impactKey => $impactLabel): ?>
                <option
                  value="<?= h($impactKey) ?>"
                  <?= ($old['impact_category'] ?? '') === $impactKey ? 'selected' : '' ?>
                ><?= h($impactLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <label for="impact" class="form-label mt-3">Penjelasan Dampak yang Dipilih<span class="text-danger">*</span></label>
            <textarea
              class="form-control"
              id="impact"
              name="impact"
              rows="4"
              placeholder="Jelaskan kondisi dan dampak yang dialami sesuai pilihan di atas..."
              required
            ><?= h($old['impact'] ?? '') ?></textarea>
          </div>

          <div class="mb-0">
            <label for="reason" class="crf-field-label">Alasan Permohonan Perubahan<span class="text-danger">*</span></label>
            <p class="crf-hint">Silakan tulis alasan perubahan yang Saudara sampaikan.</p>
            <textarea class="form-control" id="reason" name="reason" required><?= h($old['reason'] ?? '') ?></textarea>
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
                 accept=".pdf,.jpg,.jpeg,.png">
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
          <h2>Biaya / Anggaran</h2>
        </div>
        <div class="crf-section-body">
          <p class="crf-hint">Silakan sampaikan apakah untuk perubahan ini sudah dianggarkan atau perlu diusulkan.<span class="text-danger">*</span></p>

          <div id="budgetOptions" class="crf-budget-options" role="radiogroup" aria-label="Pilihan biaya atau anggaran">
            <div class="form-check mb-2 crf-budget-option">
              <input class="form-check-input" type="radio" name="budget_type" id="budget_rkap" value="rkap"<?= ($old['budget_type'] ?? '') === 'rkap' ? 'checked' : '' ?>>
              <label class="form-check-label" for="budget_rkap">RKAP tahun berjalan</label>
            </div>
            <div class="form-check mb-2 crf-budget-option">
              <input class="form-check-input" type="radio" name="budget_type" id="budget_boq" value="boq_pks"<?= ($old['budget_type'] ?? '') === 'boq_pks' ? 'checked' : '' ?>>
              <label class="form-check-label" for="budget_boq">Tercantum dalam BoQ PKS</label>
            </div>
            <div class="form-check mb-3 crf-budget-option">
              <input class="form-check-input" type="radio" name="budget_type" id="budget_baru" value="anggaran_baru" <?= ($old['budget_type'] ?? '') === 'anggaran_baru' ? 'checked' : '' ?>>
              <label class="form-check-label" for="budget_baru">Akan diajukan anggaran baru</label>
            </div>
          </div>

          <label for="budget_amount" class="crf-field-label">Nominal</label>
          <div class="input-group crf-budget-amount">
            <span class="input-group-text">Rp</span>
            <input type="number" min="0" step="1000" class="form-control"
                   id="budget_amount" name="budget_amount" placeholder="0" value="<?= h($old['budget_amount'] ?? '') ?>" disabled>
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
          <label for="change_category" class="crf-field-label">Kategori<span class="text-danger">*</span></label>
          <select class="form-select mb-3" id="change_category" name="change_category" required style="max-width: 320px;">
            <option value="" disabled <?= empty($old['change_category']) ? 'selected' : '' ?>>
              Pilih kategori...
            </option>

            <option value="Aplikasi" <?= ($old['change_category'] ?? '') === 'Aplikasi' ? 'selected' : '' ?>>
              Aplikasi
            </option>

            <option value="Infrastruktur" <?= ($old['change_category'] ?? '') === 'Infrastruktur' ? 'selected' : '' ?>>
              Infrastruktur
            </option>

            <option value="Proses" <?= ($old['change_category'] ?? '') === 'Proses' ? 'selected' : '' ?>>
              Proses
            </option>

            <option value="Security" <?= ($old['change_category'] ?? '') === 'Security' ? 'selected' : '' ?>>
              Security
            </option>

            <option value="Lainnya" <?= ($old['change_category'] ?? '') === 'Lainnya' ? 'selected' : '' ?>>
              Lainnya
            </option>
          </select>

          <div id="category-detail-wrap" class="d-none">
            <label for="change_category_detail" class="crf-field-label" id="category-detail-label">Detail Kategori<span class="text-danger">*</span></label>
            <input
                type="text"
                class="form-control"
                id="change_category_detail"
                name="change_category_detail"
                value="<?= h($old['change_category_detail'] ?? '') ?>"
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
          <label for="alternative_suggestion" class="crf-field-label">Saran Alternatif<span class="text-danger">*</span></label>
          <p class="crf-hint">Saran alternatif yang akan dilakukan atas perubahan yang telah disampaikan.</p>
          <textarea class="form-control" id="alternative_suggestion" name="alternative_suggestion" required><?= h($old['alternative_suggestion'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- ============================================================ -->
      <!-- TOMBOL FORM (sticky)                                          -->
      <!-- ============================================================ -->
      <div class="crf-sticky-actions">
        <div class="d-flex justify-content-end gap-2">

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

    </form>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
