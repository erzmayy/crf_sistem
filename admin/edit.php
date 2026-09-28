<?php
/**
 * admin/edit.php
 * ---------------------------------------------------------------
 * Halaman bagi admin untuk mengelola CRF:
 *   - Menentukan Level Urgensi
 *   - Mengubah Status
 *   - Mengisi Tanggapan / Tindak Lanjut
 *
 * Post Implementation Review dan Implementasi
 * diisi oleh user melalui halaman detail pengajuan miliknya.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pdo = getConnection();
$id  = (int) ($_GET['id'] ?? 0);
$userTable = crfUserTable();

$stmt = $pdo->prepare(
    "SELECT cr.*, u.nama AS submitter_name
    FROM change_requests cr
    JOIN {$userTable} u ON u.id = cr.user_id
    WHERE cr.id = :id
      AND cr.status <> 'Draft'
    LIMIT 1"
);

$stmt->execute([
    'id' => $id
]);

$crf = $stmt->fetch();

if (!$crf) {
    http_response_code(404);

    $pageTitle = 'CRF Tidak Ditemukan';

    require_once __DIR__ . '/../includes/header.php';

    echo '<div class="crf-page"><div class="container">';
    echo '<div class="alert alert-danger">';
    echo 'Pengajuan CRF dengan ID tersebut tidak ditemukan.';
    echo '</div>';

    echo '<a href="dashboard.php" class="btn btn-crf-outline">';
    echo '<i class="bi bi-arrow-left"></i> Kembali ke Dashboard';
    echo '</a>';

    echo '</div></div>';

    require_once __DIR__ . '/../includes/footer.php';

    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Kelola CRF - ' . $crf['request_number'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page">

  <div class="container">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 crf-page-header">

      <div>

        <h1>Kelola CRF</h1>

        <p>
          Nomor Register:
          <strong><?= h($crf['request_number']) ?></strong>

          &middot;

          Diajukan oleh
          <?= h($crf['submitter_name']) ?>
        </p>

      </div>

      <div class="d-flex gap-2">

        <a
          href="detail.php?id=<?= (int) $crf['id'] ?>"
          class="btn btn-crf-outline"
        >
          <i class="bi bi-file-text"></i>
          Lihat Rincian Lengkap
        </a>

        <a
          href="dashboard.php"
          class="btn btn-crf-outline"
        >
          <i class="bi bi-arrow-left"></i>
          Dashboard
        </a>

      </div>

    </div>


    <!-- FLASH MESSAGE -->
    <?php if ($flash): ?>

      <div
        class="alert alert-<?= h($flash['type']) ?> crf-alert"
        role="alert"
      >
        <?= h($flash['message']) ?>
      </div>

    <?php endif; ?>


    <!-- RINGKASAN CRF -->
    <div class="row g-3 mb-4">

      <div class="col-md-6">

        <div class="crf-detail-label">
          Rincian Permohonan Perubahan
        </div>

        <div class="crf-detail-value">
          <?= h($crf['change_description'] ?? 'Belum diisi (draft)') ?>
        </div>

      </div>


      <div class="col-md-6">

        <div class="crf-detail-label">
          Kategori Perubahan
        </div>

        <div class="crf-detail-value">

          <?= h($crf['change_category'] ?? '-') ?>

          <?php if (!empty($crf['change_category_detail'])): ?>

            &mdash;
            <?= h($crf['change_category_detail']) ?>

          <?php endif; ?>

        </div>

      </div>

    </div>


    <!-- FORM ADMIN -->
    <form
      action="../actions/update_crf.php"
      method="POST"
    >

      <input
        type="hidden"
        name="id"
        value="<?= (int) $crf['id'] ?>"
      >

            <?= csrfField() ?>


      <!-- LEVEL URGENT -->
      <div class="crf-section">

        <div class="crf-section-header">

          <span class="crf-section-number">
            <i class="bi bi-flag"></i>
          </span>

          <h2>Level Urgensi</h2>

        </div>

        <div class="crf-section-body">

          <!-- <p class="crf-hint">
            Catatan: Level Urgensi di prototype ini berbeda dengan Level Urgensi pada dokumen CRF perusahaan.
          </p> -->

          <select
            name="level"
            class="form-select"
            style="max-width: 260px;"
          >

            <option
              value=""
              <?= $crf['level'] === null ? 'selected' : '' ?>
            >
              Belum ditentukan
            </option>

            <option
              value="Tinggi"
              <?= $crf['level'] === 'Tinggi' ? 'selected' : '' ?>
            >
              Tinggi
            </option>

            <option
              value="Normal"
              <?= $crf['level'] === 'Normal' ? 'selected' : '' ?>
            >
              Normal
            </option>

            <option
              value="Rendah"
              <?= $crf['level'] === 'Rendah' ? 'selected' : '' ?>
            >
              Rendah
            </option>

          </select>

        </div>

      </div>


      <!-- STATUS -->
      <div class="crf-section">

        <div class="crf-section-header">

          <span class="crf-section-number">
            <i class="bi bi-toggles"></i>
          </span>

          <h2>Status</h2>

        </div>

        <div class="crf-section-body">

                    <?php
          $statusOptions = array_merge(
              [$crf['status']],
              statusTransitions()[$crf['status']] ?? []
          );
          ?>

          <select
            name="status"
            id="status"
            class="form-select"
            style="max-width: 260px;"
          >
            <?php foreach ($statusOptions as $option): ?>
              <option
                value="<?= h($option) ?>"
                <?= $crf['status'] === $option ? 'selected' : '' ?>
              >
                <?= h(statusLabel($option)) ?>
              </option>
            <?php endforeach; ?>
          </select>


          <?php if ($crf['status'] === 'Solve' && $crf['solved_at']): ?>

            <div class="crf-readonly-note mt-2">

              Ditandai Solve pada:
              <?= h(date('d-m-Y H:i', strtotime($crf['solved_at']))) ?>

            </div>

          <?php endif; ?>


          <?php if ($crf['status'] === 'Cancel' && $crf['cancelled_at']): ?>

            <div class="crf-readonly-note mt-2">

              Dibatalkan pada:
              <?= h(date('d-m-Y H:i', strtotime($crf['cancelled_at']))) ?>

            </div>

          <?php endif; ?>

        </div>

      </div>


      <!-- TANGGAPAN / TINDAK LANJUT -->
      <div class="crf-section">

        <div class="crf-section-header">

          <span class="crf-section-number">

            <i class="bi bi-chat-left-text"></i>

          </span>

          <h2>Tanggapan / Tindak Lanjut</h2>

        </div>


        <div class="crf-section-body">

          <p class="crf-hint">
            Tanggapan atau tindak lanjut yang diberikan oleh admin terkait pengajuan CRF.
          </p>

          <textarea
            name="tanggapan_tindak_lanjut"
            id="tanggapan_tindak_lanjut"
            class="form-control"
            rows="4"
          ><?= h($crf['tanggapan_tindak_lanjut'] ?? '') ?></textarea>

        </div>

      </div>


      <!-- TOMBOL -->
      <div class="d-flex justify-content-end gap-2 mb-4">

        <a
          href="detail.php?id=<?= (int) $crf['id'] ?>"
          class="btn btn-crf-outline px-4"
        >
          Batal
        </a>

        <button
          type="submit"
          class="btn btn-crf-primary px-4"
        >
          <i class="bi bi-check2-circle"></i>
          Simpan Perubahan
        </button>

      </div>

    </form>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>