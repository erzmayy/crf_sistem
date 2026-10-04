<?php
/**
 * helpdesk/form.php
 * Formulir Helpdesk = pintu masuk seluruh permintaan.
 * Permintaan Baru pada kategori "Butuh CRF" (mis. Aplikasi SIAP)
 * otomatis diteruskan ke Form CRF dengan data yang sudah terisi.
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

$pdo = getConnection();
$user = getCurrentUser(true);
$categories = helpdeskCategories($pdo);

$openTicketsStmt = $pdo->prepare("
    SELECT t.id, t.status, t.message, c.name AS category_name, t.created_at
    FROM helpdesk_tickets t
    JOIN helpdesk_categories c ON c.id = t.helpdesk_category_id
    WHERE t.user_id = :user_id
      AND t.status NOT IN ('Selesai', 'Dibatalkan')
    ORDER BY t.id DESC
    LIMIT 50
");
$openTicketsStmt->execute(['user_id' => $user['id']]);
$openTickets = $openTicketsStmt->fetchAll();

$old = $_SESSION['old_helpdesk'] ?? [];
unset($_SESSION['old_helpdesk']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$requestMode = ($old['request_mode'] ?? 'new') === 'existing' ? 'existing' : 'new';
$selectedCategory = (int) ($old['helpdesk_category_id'] ?? ($_GET['kategori'] ?? 0));
$selectedKind = $old['request_kind'] ?? '';
$phone = trim((string) ($user['no_wa'] ?? ''));

$pageTitle = 'Formulir Helpdesk';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-form-page">
  <div class="container">

    <div class="crf-page-header crf-page-banner">
      <span class="crf-page-eyebrow">HELPDESK PPU</span>
      <div>
        <h1>Formulir Helpdesk</h1>
        <p>Sampaikan kendala, permintaan, atau perubahan sistem Anda di sini.</p>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>" style="white-space: pre-line;"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <form action="../actions/helpdesk_submit.php" method="POST" enctype="multipart/form-data" id="helpdeskForm" data-loading-form>
      <?= csrfField() ?>

      <!-- 1. DATA PELAPOR -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">1</span>
          <h2>Data Pelapor</h2>
          <span class="crf-auto-pill ms-auto"><i class="bi bi-lock-fill"></i> Terisi otomatis</span>
        </div>
        <div class="crf-section-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="crf-field-label">Nama Lengkap</label>
              <input type="text" class="form-control" value="<?= h($user['nama'] ?? '') ?>" readonly>
            </div>
            <div class="col-md-4">
              <label class="crf-field-label" for="hdPhone">No Handphone / WA<?= $phone === '' ? '<span class="text-danger">*</span>' : '' ?></label>
              <input
                type="text"
                class="form-control"
                id="hdPhone"
                name="phone"
                maxlength="30"
                value="<?= h($phone !== '' ? $phone : ($old['phone'] ?? '')) ?>"
                <?= $phone !== '' ? 'readonly' : 'required' ?>
              >
            </div>
            <div class="col-md-4">
              <label class="crf-field-label">Email</label>
              <input type="text" class="form-control" value="<?= h($user['email'] ?? '') ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="crf-field-label">Departemen</label>
              <input type="text" class="form-control" value="<?= h($user['dept'] ?? '-') ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="crf-field-label">Divisi</label>
              <input type="text" class="form-control" value="<?= h($user['divisi'] ?? '-') ?>" readonly>
            </div>
          </div>
        </div>
      </div>

      <!-- 2. JENIS PERMINTAAN -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">2</span>
          <h2>Detail Permintaan</h2>
        </div>
        <div class="crf-section-body">
          <div class="crf-choice-row mb-3" role="radiogroup" aria-label="Jenis permintaan">
            <label class="crf-choice-card">
              <input type="radio" name="request_mode" value="new" <?= $requestMode === 'new' ? 'checked' : '' ?>>
              <span class="crf-choice-icon"><i class="bi bi-plus-circle"></i></span>
              <span><strong>Permintaan Baru</strong><small>Ajukan permintaan / laporan baru</small></span>
            </label>
            <label class="crf-choice-card">
              <input type="radio" name="request_mode" value="existing" <?= $requestMode === 'existing' ? 'checked' : '' ?>>
              <span class="crf-choice-icon"><i class="bi bi-arrow-repeat"></i></span>
              <span><strong>Permintaan yang Sudah Ada</strong><small>Tambahkan keterangan pada ticket Anda</small></span>
            </label>
          </div>

          <div id="helpdeskNewRequest">
            <div class="row g-3">
              <div class="col-md-8">
                <label for="helpdesk_category_id" class="crf-field-label">Kategori Helpdesk<span class="text-danger">*</span></label>
                <select class="form-select" id="helpdesk_category_id" name="helpdesk_category_id" data-new-only required>
                  <option value="">-- Pilih Kategori Helpdesk --</option>
                  <?php foreach ($categories as $category): ?>
                    <option
                      value="<?= (int) $category['id'] ?>"
                      data-requires-crf="<?= (int) $category['requires_crf'] ?>"
                      <?= $selectedCategory === (int) $category['id'] ? 'selected' : '' ?>
                    ><?= h($category['name']) ?><?= $category['requires_crf'] ? ' (Request via CRF)' : '' ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!$categories): ?>
                  <div class="text-danger small mt-1">Belum ada kategori Helpdesk aktif. Hubungi Admin.</div>
                <?php endif; ?>
              </div>
              <div class="col-md-4" data-hide-for-crf>
                <label for="report_time" class="crf-field-label">Jam Mulai Laporan<span class="text-danger">*</span></label>
                <input type="time" class="form-control" id="report_time" name="report_time" data-new-only required value="<?= h($old['report_time'] ?? date('H:i')) ?>">
              </div>
            </div>

            <div id="helpdeskCrfNotice" class="crf-crf-notice d-none" role="status">
              <i class="bi bi-info-circle-fill"></i>
              <div>
                <strong>Request pada kategori ini diajukan melalui Change Request Form (CRF).</strong>
                Setelah klik <em>Lanjut ke Form CRF</em>, Anda diarahkan ke Form CRF dengan data pelapor
                dan isi pesan yang sudah terisi. Permintaan ini dicatat sebagai CRF, bukan ticket Helpdesk.
              </div>
            </div>

            <div>
            <label class="crf-field-label mt-3">Kategori / Dampak<span class="text-danger">*</span></label>
            <div class="crf-choice-row crf-choice-row--3" role="radiogroup" aria-label="Kategori dampak">
              <?php foreach (helpdeskRequestKinds() as $kindKey => $kind): ?>
                <label class="crf-choice-card">
                  <input type="radio" name="request_kind" value="<?= h($kindKey) ?>" data-new-only required <?= $selectedKind === $kindKey ? 'checked' : '' ?>>
                  <span class="crf-choice-icon"><i class="bi <?= h($kind['icon']) ?>"></i></span>
                  <span><strong><?= h($kind['label']) ?></strong><small><?= h($kind['hint']) ?></small></span>
                </label>
              <?php endforeach; ?>
            </div>
            </div>
          </div>

          <div id="helpdeskExisting" class="d-none">
            <?php if (!$openTickets): ?>
              <div class="crf-empty-state crf-empty-state--compact">
                <i class="bi bi-inbox"></i>
                <p>Anda belum memiliki ticket yang masih berjalan.</p>
              </div>
            <?php else: ?>
              <label for="existing_ticket_id" class="crf-field-label">Pilih Ticket<span class="text-danger">*</span></label>
              <select class="form-select" id="existing_ticket_id" name="existing_ticket_id">
                <option value="">-- Pilih ticket yang masih berjalan --</option>
                <?php foreach ($openTickets as $ticket): ?>
                  <option value="<?= (int) $ticket['id'] ?>" <?= (int) ($old['existing_ticket_id'] ?? 0) === (int) $ticket['id'] ? 'selected' : '' ?>>
                    <?= h(helpdeskTicketLabel($ticket) . ' · ' . $ticket['status'] . ' — ' . mb_strimwidth((string) $ticket['message'], 0, 60, '…')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- 3. PESAN & LAMPIRAN -->
      <div class="crf-section">
        <div class="crf-section-header">
          <span class="crf-section-number">3</span>
          <h2>Pesan &amp; Lampiran</h2>
        </div>
        <div class="crf-section-body">
          <label for="message" class="crf-field-label">Isi Pesan<span class="text-danger">*</span></label>
          <textarea class="form-control" id="message" name="message" rows="5" maxlength="5000" required placeholder="Jelaskan permintaan atau kendala Anda secara lengkap."><?= h($old['message'] ?? '') ?></textarea>

          <div id="helpdeskAttachmentSection" class="mt-3">
            <label for="hdAttachments" class="crf-field-label">Lampiran (opsional)</label>
            <input type="file" class="form-control" id="hdAttachments" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png">
            <div class="crf-readonly-note mt-2">Format: PDF, JPG, JPEG, PNG. Maks. 5 MB per file.</div>
          </div>
        </div>
      </div>

      <div class="crf-sticky-actions">
        <div class="d-flex justify-content-end gap-2">
          <a href="saya.php" class="btn btn-crf-outline px-4">Batal</a>
          <button type="submit" class="btn btn-crf-primary px-4">
            <i class="bi bi-send"></i> <span id="helpdeskSubmitLabel">Kirim Permintaan</span>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
