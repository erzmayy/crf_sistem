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
    $sectionNumber = 1;
    require __DIR__ . '/../includes/partials/informasi_pengajuan.php';
    ?>

    <!-- Detail Pengajuan -->
    <div class="crf-section crf-detail-card">
      <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-card-checklist"></i></span>
        <h2>Detail Pengajuan</h2>
      </div>
      <div class="crf-section-body">

        <div class="crf-detail-grid">
          <div class="crf-detail-item crf-detail-item-wide">
            <div class="crf-detail-label">Rincian Permohonan Perubahan</div>
            <div class="crf-detail-value"><?= h($crf['change_description'] ?? '-') ?></div>
          </div>

          <div class="crf-detail-item">
            <div class="crf-detail-label">Benefit dari Perubahan yang Diharapkan</div>
            <div class="crf-detail-value"><?= h($crf['benefit'] ?? '-') ?></div>
          </div>

          <div class="crf-detail-item">
            <div class="crf-detail-label">Dampak Jika Tidak Dilakukan Perubahan</div>
            <div class="crf-detail-value"><?= h($crf['impact'] ?? '-') ?></div>
          </div>

          <div class="crf-detail-item crf-detail-item-wide">
            <div class="crf-detail-label">Alasan Permohonan Perubahan</div>
            <div class="crf-detail-value"><?= h($crf['reason'] ?? '-') ?></div>
          </div>
        </div>

        <div class="crf-detail-item crf-detail-item-wide crf-detail-item-attachments">
          <div class="crf-detail-label">Bukti dan Informasi Pendukung</div>
          <div class="crf-detail-value">
          <?php if (!$attachments): ?>

            <span class="text-muted">
              Tidak ada file yang diupload.
            </span>

          <?php else: ?>

            <div class="list-group">
              <?php foreach ($attachments as $file): ?>

                <div class="list-group-item d-flex justify-content-between align-items-center gap-3 py-2 px-3">

                  <div class="d-flex align-items-center gap-2 flex-grow-1 min-width-0">
                    <i class="bi bi-paperclip text-primary"></i>

                    <div class="text-truncate">
                      <div class="fw-medium text-truncate">
                        <?= h($file['original_name']) ?>
                      </div>

                      <small class="text-muted">
                        <?= round($file['file_size'] / 1024) ?> KB
                      </small>
                    </div>
                  </div>

                  <div class="d-flex gap-1 flex-shrink-0">
                    <a
                      href="<?= h($file['file_path']) ?>"
                      target="_blank"
                      rel="noopener"
                      class="btn btn-sm btn-crf-outline py-1 px-2"
                    >
                      <i class="bi bi-eye"></i>
                      Lihat
                    </a>

                    <a
                      href="../actions/download_attachment.php?id=<?= (int) $file['id'] ?>"
                      class="btn btn-sm btn-crf-primary py-1 px-2"
                    >
                      <i class="bi bi-download"></i>
                      Download
                    </a>
                  </div>

                </div>

              <?php endforeach; ?>
            </div>

          <?php endif; ?>
          </div>
        </div>

        <div class="crf-detail-grid crf-detail-grid-secondary">
          <div class="crf-detail-item">
            <div class="crf-detail-label">Biaya / Anggaran</div>
            <div class="crf-detail-value">
              <?= h(budgetTypeLabel($crf['budget_type'])) ?>
              <?php if ($crf['budget_amount'] !== null): ?>
                &mdash; <?= h(formatRupiah($crf['budget_amount'])) ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="crf-detail-item">
            <div class="crf-detail-label">Kategori Perubahan</div>
            <div class="crf-detail-value">
              <?= h($crf['change_category'] ?? '-') ?>
              <?php if (!empty($crf['change_category_detail'])): ?>
                &mdash; <?= h($crf['change_category_detail']) ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="crf-detail-item crf-detail-item-wide">
            <div class="crf-detail-label">Saran Alternatif</div>
            <div class="crf-detail-value"><?= h($crf['alternative_suggestion'] ?? '-') ?></div>
          </div>

          <div class="crf-detail-item">
            <div class="crf-detail-label">Post Implementation Review</div>
            <div class="crf-detail-value"><?= h($crf['post_implementation_review'] ?? '-') ?></div>
          </div>

          <div class="crf-detail-item">
            <div class="crf-detail-label">Implementasi</div>
            <div class="crf-detail-value"><?= h($crf['implementation'] ?? '-') ?></div>
          </div>
        </div>

      </div>
    </div>

    <!-- TIMELINE PROSES PENGAJUAN -->
    <div class="crf-section mt-4">

        <div class="crf-section-header">

            <span class="crf-section-number">
                <i class="bi bi-clock-history"></i>
            </span>

            <h2>Timeline Proses Pengajuan</h2>

        </div>

        <div class="crf-section-body">

            <?php if (!$timeline): ?>

                <div class="text-muted">
                    Belum ada riwayat proses pengajuan.
                </div>

            <?php else: ?>

                <div class="crf-timeline">

                    <?php foreach ($timeline as $item): ?>

                        <div class="crf-timeline-item">

                            <div class="crf-timeline-dot"></div>

                            <div class="crf-timeline-content">

                                <div class="crf-timeline-top">

                                    <strong>
                                        <?= h(
                                            $item['activity'] === 'Solve'
                                                ? 'Selesai'
                                                : (
                                                    $item['activity'] === 'Cancel'
                                                        ? 'Dibatalkan'
                                                        : $item['activity']
                                                )
                                        ) ?>
                                    </strong>

                                    <span class="crf-timeline-date">
                                        <?= h(
                                            date(
                                                'd-m-Y H:i',
                                                strtotime($item['created_at'])
                                            )
                                        ) ?>
                                    </span>

                                </div>

                                <?php if (!empty($item['description'])): ?>

                                    <div class="crf-timeline-description">
                                        <?= nl2br(h($item['description'])) ?>
                                    </div>

                                <?php endif; ?>

                                <?php if (!empty($item['actor'])): ?>

                                    <div class="crf-timeline-actor">
                                        Oleh: <?= h($item['actor']) ?>
                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>