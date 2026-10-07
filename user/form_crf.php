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

/*
 * Kategori CRF dinamis + ticket Helpdesk asal (jika CRF dibuat dari Helpdesk).
 */
$crfCategoryOptions = crfCategories($pdo);
$sourceTicket = null;
if (!empty($draftData['helpdesk_ticket_id'])) {
    $ticketStmt = $pdo->prepare('
        SELECT t.id, t.created_at, c.name AS category_name
        FROM helpdesk_tickets t
        JOIN helpdesk_categories c ON c.id = t.helpdesk_category_id
        WHERE t.id = :id
    ');
    $ticketStmt->execute(['id' => $draftData['helpdesk_ticket_id']]);
    $sourceTicket = $ticketStmt->fetch() ?: null;
}

// Kategori lama yang sudah dinonaktifkan tetap tampil agar draft tidak kehilangan pilihan.
$selectedCategoryId = (int) ($_SESSION['old_crf']['crf_category_id'] ?? ($draftData['crf_category_id'] ?? 0));
if ($selectedCategoryId > 0 && !in_array($selectedCategoryId, array_map('intval', array_column($crfCategoryOptions, 'id')), true)) {
    $selectedCategory = findCrfCategory($pdo, $selectedCategoryId);
    if ($selectedCategory) {
        $selectedCategory['name'] .= ' (nonaktif)';
        $crfCategoryOptions[] = $selectedCategory;
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

/*
 * Status penyimpanan form (ditampilkan di samping tombol & dipakai untuk
 * konfirmasi browser saat meninggalkan halaman yang belum disimpan).
 */
if (($draftData['status'] ?? '') === 'Perlu Revisi') {
    $saveState = 'revision';
    $saveStateText = 'Perlu revisi · belum dikirim ulang';
} elseif ($draftData) {
    $saveState = 'saved';
    $saveStateText = 'Tersimpan sebagai Draft · ' . date('d-m-Y H:i', strtotime($draftData['updated_at'] ?? $draftData['created_at'] ?? 'now'));
} elseif (trim((string) ($old['change_description'] ?? '')) !== '') {
    // Isian dibawa dari Formulir Helpdesk / gagal validasi: belum pernah tersimpan.
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

    <?php if ($sourceTicket): ?>
      <div class="crf-ticket-link-card">
        <span class="crf-ticket-link-icon"><i class="bi bi-ticket-detailed"></i></span>
        <div>
          <span class="crf-request-caption">CRF ini berasal dari ticket Helpdesk</span>
          <a href="../helpdesk/detail.php?id=<?= (int) $sourceTicket['id'] ?>" class="crf-link">
            <strong><?= h(helpdeskTicketLabel($sourceTicket)) ?></strong>
          </a>
          <div class="crf-readonly-note mb-0">Data pelapor &amp; isi permintaan dari Helpdesk sudah terisi otomatis. Lengkapi sisanya lalu ajukan CRF.</div>
        </div>
      </div>
    <?php endif; ?>

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
              <div class="crf-readonly-note"><i class="bi bi-lock-fill"></i>Tujuan pengajuan CRF ditetapkan sistem</div>
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

          <div class="mb-3" style="max-width: 360px;">
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
          <h2>Biaya / Anggaran <small class="text-muted fw-normal">(opsional)</small></h2>
        </div>
        <div class="crf-section-body">
          <?php $budgetChoice = $old['budget_type'] ?? ''; ?>
          <p class="crf-hint">Bagian ini opsional. Kosongkan bila perubahan ini tidak membutuhkan anggaran. Jika ada biaya, pilih sumber anggarannya lalu isi nominal.</p>

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
                   id="budget_amount" name="budget_amount" placeholder="0" value="<?= h($old['budget_amount'] ?? '') ?>" <?= $budgetChoice === '' ? 'disabled' : '' ?>>
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
          <label for="change_category" class="crf-field-label">Kategori CRF<span class="text-danger">*</span></label>
          <select class="form-select mb-1" id="change_category" name="crf_category_id" required style="max-width: 360px;">
            <option value="" disabled <?= $selectedCategoryId === 0 ? 'selected' : '' ?>>
              Pilih kategori...
            </option>
            <?php foreach ($crfCategoryOptions as $categoryOption): ?>
              <option
                value="<?= (int) $categoryOption['id'] ?>"
                data-legacy="<?= h($categoryOption['legacy_change_category']) ?>"
                <?= $selectedCategoryId === (int) $categoryOption['id'] ? 'selected' : '' ?>
              ><?= h($categoryOption['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$crfCategoryOptions): ?>
            <div class="text-danger small mb-3">Belum ada Kategori CRF aktif. Hubungi Admin.</div>
          <?php else: ?>
            <div class="crf-readonly-note mb-3">Kategori menentukan PIC CRF yang akan memproses CRF Anda.</div>
          <?php endif; ?>

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
          <label for="alternative_suggestion" class="crf-field-label">Saran Alternatif <small class="text-muted fw-normal">(opsional)</small></label>
          <p class="crf-hint">Isi bila ada saran alternatif atas perubahan yang disampaikan.</p>
          <textarea class="form-control" id="alternative_suggestion" name="alternative_suggestion"><?= h($old['alternative_suggestion'] ?? '') ?></textarea>
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
