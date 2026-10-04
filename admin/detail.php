<?php
/**
 * admin/detail.php
 * ---------------------------------------------------------------
 * Menampilkan seluruh isi satu pengajuan CRF (lihat brief butir 20).
 * Halaman ini read-only; perubahan Level Urgensi / Status / PIR /
 * Implementasi dilakukan di admin/edit.php.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT cr.*
     FROM change_requests cr
     WHERE cr.id = :id
       AND cr.status <> \'Draft\'
     LIMIT 1'
);

$stmt->execute(['id' => $id]);
$crf = $stmt->fetch();

if (!$crf) {
    http_response_code(404);
    $pageTitle = 'CRF Tidak Ditemukan';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="crf-page"><div class="container">';
    echo '<div class="alert alert-danger">Pengajuan CRF dengan ID tersebut tidak ditemukan.</div>';
    echo '<a href="dashboard.php" class="btn btn-crf-outline"><i class="bi bi-arrow-left"></i> Kembali ke Dashboard</a>';
    echo '</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$attStmt = $pdo->prepare('SELECT * FROM attachments WHERE change_request_id = :id ORDER BY uploaded_at ASC');
$attStmt->execute(['id' => $id]);
$attachments = $attStmt->fetchAll();

/*
 * Timeline proses pengajuan
 */
$timelineStmt = $pdo->prepare("
    SELECT
        activity,
        description,
        actor,
        old_status,
        new_status,
        created_at
    FROM crf_activity_logs
    WHERE change_request_id = :id
    ORDER BY created_at ASC, id ASC
");

$timelineStmt->execute([
    'id' => $id
]);

$timeline = $timelineStmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Admin: penugasan ulang handler (tahap Handler) & pembatalan administratif.
$categoryHandlers = !empty($crf['crf_category_id'])
    ? (categoryMembers($pdo, 'crf_category_handlers', 'crf_category_id')[(int) $crf['crf_category_id']] ?? [])
    : [];
$canAdminCancel = !in_array($crf['status'], ['Solve', 'Cancel'], true) && !isOwnHandledCrf($crf);

$pageTitle = 'Detail CRF - ' . $crf['request_number'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-detail-page">
  <div class="container">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 crf-page-header">
      <div>
        <h1>Detail CRF</h1>
        <p>Nomor Register: <strong><?= h($crf['request_number']) ?></strong></p>
      </div>
      <div class="d-flex gap-2">
        <a
          href="../actions/export_crf.php?id=<?= (int) $crf['id'] ?>"
          class="btn btn-crf-primary"
        >
          <i class="bi bi-file-earmark-pdf"></i> Export PDF
        </a>

        <a
          href="dashboard.php"
          class="btn btn-crf-outline"
        >
          <i class="bi bi-arrow-left"></i> Dashboard
        </a>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> crf-alert" role="alert">
        <?= h($flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="crf-status-strip mb-4">
      <span class="crf-badge <?= levelBadgeClass($crf['level']) ?>">
        Level Urgensi: <?= h($crf['level'] ?? 'Belum ditentukan') ?>
      </span>
      <span class="crf-badge <?= statusBadgeClass($crf['status']) ?>">
        Status: <?= h(statusLabel($crf['status'])) ?>
      </span>
    </div>

    <?php if ($crf['workflow_stage'] === 'OTOMASI'): ?>
      <?php $assignmentReturn = 'admin'; require __DIR__ . '/../includes/partials/handler_assignment.php'; ?>
    <?php endif; ?>

    <div class="crf-detail-layout">
      <main class="crf-detail-main">

    <?php if ($crf['status'] === 'Solve' && $crf['solved_at']): ?>
      <div class="crf-readonly-note mb-3">
        <i class="bi bi-check-circle"></i>
        Diselesaikan pada:
        <?= h(date('d-m-Y H:i', strtotime($crf['solved_at']))) ?>
      </div>
    <?php endif; ?>

    <?php if ($crf['status'] === 'Cancel' && $crf['cancelled_at']): ?>
      <div class="crf-readonly-note mb-3">
        <i class="bi bi-x-circle"></i>
        Dibatalkan pada:
        <?= h(date('d-m-Y H:i', strtotime($crf['cancelled_at']))) ?>
      </div>
    <?php endif; ?>

    <!-- Tanggapan / Tindak Lanjut -->
    <div class="crf-section mb-4">
      <div class="crf-section-header">
        <span class="crf-section-number">
          <i class="bi bi-chat-left-text"></i>
        </span>
        <h2>Tanggapan / Tindak Lanjut</h2>
      </div>

      <div class="crf-section-body">
        <div class="crf-detail-value mb-0">
          <?= !empty($crf['tanggapan_tindak_lanjut'])
              ? nl2br(h($crf['tanggapan_tindak_lanjut']))
              : '<span class="text-muted">Belum ada tanggapan atau tindak lanjut.</span>' ?>
        </div>
      </div>
    </div>

    <!-- Informasi Pengajuan -->
    <?php
    require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
    ?>

    <!-- Detail Pengajuan -->
    <?php
    require __DIR__ . '/../includes/partials/detail_permintaan.php';
    ?>

    <?php require __DIR__ . '/../includes/partials/informasi_sla.php'; ?>

    <div class="crf-section crf-detail-card mb-4">
      <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-clipboard-check"></i></span>
        <h2>Implementasi &amp; Post Implementation Review</h2>
      </div>
      <div class="crf-section-body">
        <div class="crf-info-rows">
          <div class="crf-info-row">
            <span class="crf-info-label">Tanggal Implementasi</span>
            <div class="crf-info-value">
              <?= !empty($crf['implementation_date'])
                  ? h(date('d-m-Y', strtotime($crf['implementation_date'])))
                  : '-' ?>
            </div>
          </div>
          <div class="crf-info-row crf-request-row-long">
            <span class="crf-info-label">Implementasi / Hasil Perubahan</span>
            <div class="crf-info-value"><?= nl2br(h($crf['implementation'] ?? '-')) ?></div>
          </div>
          <div class="crf-info-row">
            <span class="crf-info-label">Tanggal PIR</span>
            <div class="crf-info-value">
              <?= !empty($crf['pir_date'])
                  ? h(date('d-m-Y', strtotime($crf['pir_date'])))
                  : '-' ?>
            </div>
          </div>
          <div class="crf-info-row crf-request-row-long">
            <span class="crf-info-label">Post Implementation Review</span>
            <div class="crf-info-value"><?= nl2br(h($crf['post_implementation_review'] ?? '-')) ?></div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($canAdminCancel): ?>
      <div class="crf-section crf-detail-card mb-4">
        <div class="crf-section-header">
          <span class="crf-section-number"><i class="bi bi-shield-exclamation"></i></span>
          <h2>Tindakan Admin</h2>
        </div>
        <div class="crf-section-body">
          <p class="crf-readonly-note mb-3">
            Admin memantau alur dan tidak memproses CRF atas nama CMO, Petugas Otomasi, atau Kepala Departemen Operasional.
            Gunakan pembatalan administratif hanya bila CRF tidak bisa dilanjutkan lewat alur normal
            (duplikat, salah input, pemohon tidak lagi bekerja, dsb). Alasan dicatat di timeline
            dan dikirim ke Pemohon serta Petugas Otomasi.
          </p>
          <form method="POST" action="../actions/admin_cancel_crf.php" data-confirm="Batalkan CRF <?= h($crf['request_number']) ?> secara administratif? Tindakan ini tidak bisa diurungkan.">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">
            <label for="admin_cancel_reason" class="form-label fw-semibold">Alasan Pembatalan <span class="text-danger">*</span></label>
            <textarea id="admin_cancel_reason" name="reason" class="form-control mb-3" rows="3" maxlength="1000" required placeholder="Contoh: Duplikat dengan CRF PPU-02.4.0063.10.26."></textarea>
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-octagon"></i> Batalkan CRF</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

      </main>
      <aside class="crf-detail-sidebar">
        <?php require __DIR__ . '/../includes/partials/timeline.php'; ?>

    </aside>
  </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>