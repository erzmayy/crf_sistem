<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireCrfRole(['otomasi']);

$pdo = getConnection();

$queue = $_GET['queue'] ?? 'all';
$search = $_GET['q'] ?? '';

if (!in_array($queue, ['all', 'history'], true)) {
    $queue = 'all';
}

// Handler hanya melihat CRF pada kategori yang ditanganinya (Admin: semua).
$handlerScopeSql = crfHandlerScopeSql($pdo, 'cr');
$where = ["cr.status <> 'Draft'", $handlerScopeSql];
$params = [];

/* =========================================================
 * FILTER ANTREAN
 * ========================================================= */

if ($queue === 'history') {
    $where[] = "EXISTS (
        SELECT 1
        FROM crf_log_aktivitas activity_log
        WHERE activity_log.pengajuan_id = cr.id
          AND activity_log.aktivitas IN (
              'Eksekusi Selesai',
              'Otomasi Selesai'
          )
    )";
} else {
    // Hanya CRF yang sudah disetujui Kepala Departemen Operasional.
    $where[] = "cr.tahap_alur = 'OTOMASI'";
    $where[] = 'cr.tgl_disetujui_kadep IS NOT NULL';
}

$listFilters = applyCrfRequestFilters($pdo, $where, $params, [
    'search' => $search,
    'status' => $_GET['status'] ?? '',
    'department' => $_GET['department'] ?? '',
    'level' => $_GET['level'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
]);
$search = $listFilters['search'];

// Filter kategori hanya menampilkan kategori dalam lingkup handler.
$handlerScope = crfHandlerScope($pdo);
if (!$handlerScope['all']) {
    $listFilters['categories'] = array_values(array_filter(
        $listFilters['categories'],
        static fn($category) => in_array((int) $category['id'], $handlerScope['category_ids'], true)
    ));
}

/* =========================================================
 * PAGINATION
 * ========================================================= */

$perPage = getCrfPageSize($_GET['per_page'] ?? 6);
$page = max(1, (int) ($_GET['page'] ?? 1));

$countSql = "
    SELECT COUNT(*)
    FROM crf_pengajuan cr
    WHERE " . implode(' AND ', $where);

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/* =========================================================
 * AMBIL DATA
 * ========================================================= */

$sql = "
    SELECT cr.*, cc.nama AS crf_category_name
    FROM crf_pengajuan cr
    LEFT JOIN crf_kategori cc ON cc.id = cr.kategori_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY " . ($queue === 'history'
        ? 'cr.tgl_diubah DESC'
        // Tenggat terdekat dulu (CRF yang belum diambil memakai batas respon bila lebih dekat),
        // lalu urgensi (Tinggi lebih dulu) dan waktu persetujuan.
        : "CASE
            WHEN cr.pic_id IS NULL AND cr.sla_respon_at IS NULL AND cr.sla_respon_tenggat IS NOT NULL
                THEN LEAST(cr.sla_respon_tenggat, cr.sla_tenggat)
            ELSE cr.sla_tenggat
        END ASC,
        FIELD(COALESCE(cr.level_urgensi_final, cr.level), 'Tinggi', 'Normal', 'Rendah') = 0,
        FIELD(COALESCE(cr.level_urgensi_final, cr.level), 'Tinggi', 'Normal', 'Rendah'),
        cr.tgl_disetujui_kadep ASC,
        cr.id ASC") . "
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

/* =========================================================
 * SUMMARY CARD
 * ========================================================= */

$summaryStmt = $pdo->query("
    SELECT
        COUNT(*) AS in_execution,
        SUM(CASE WHEN sla_tenggat IS NOT NULL AND sla_tenggat < NOW() THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN pic_id IS NULL AND sla_respon_at IS NULL
                  AND sla_respon_tenggat IS NOT NULL AND sla_respon_tenggat < NOW() THEN 1 ELSE 0 END) AS respon_overdue
    FROM crf_pengajuan cr
    WHERE cr.tahap_alur = 'OTOMASI'
      AND cr.tgl_disetujui_kadep IS NOT NULL
      AND cr.status <> 'Draft'
      AND {$handlerScopeSql}
");
$summary = $summaryStmt->fetch() ?: [];

$inExecution = (int) ($summary['in_execution'] ?? 0);
$overdueCount = (int) ($summary['overdue'] ?? 0);
$responOverdueCount = (int) ($summary['respon_overdue'] ?? 0);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Otomasi';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .queue-filter-row {
        align-items: center;
    }

    .queue-filter-row .form-control,
    .queue-filter-row .btn {
        min-height: 38px;
    }

    .queue-filter-buttons {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .queue-filter-buttons .btn {
        white-space: nowrap;
    }
</style>

<div class="crf-page crf-portal-page">
    <div class="container">

        <div class="crf-portal-banner">
            <div>
                <span class="crf-portal-eyebrow">PORTAL CRF · TINDAK LANJUT OTOMASI</span>
                <h1>Tindak Lanjut Permohonan Perubahan</h1>
                <p>CRF yang sudah disetujui Kepala Departemen Operasional pada kategori yang Anda tangani. SLA mulai dihitung sejak persetujuan; isi hasil implementasi untuk menyelesaikan CRF.</p>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- =====================================================
             SUMMARY
             ===================================================== -->
        <div class="crf-stat-grid crf-portal-summary mb-4">
            <div class="crf-stat-card">
                <span><i class="bi bi-gear-wide-connected"></i> Menunggu Eksekusi</span>
                <strong><?= $inExecution ?></strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-exclamation-triangle"></i> SLA Terlewati</span>
                <strong><?= $overdueCount ?></strong>
            </div>

            <div class="crf-stat-card">
                <span><i class="bi bi-hourglass-split"></i> Response Time Terlewati</span>
                <strong><?= $responOverdueCount ?></strong>
            </div>
        </div>

        <div class="crf-table-card crf-list-table-card">

            <div class="crf-table-heading">
                <h2><?= $queue === 'history' ? 'Riwayat Tindak Lanjut' : 'Eksekusi CRF' ?></h2>

                <!-- FILTER ANTREAN -->
                <div class="btn-group">
                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'all'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'all' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Semua
                    </a>

                    <a
                        href="?<?= h(http_build_query(array_filter([
                            'q' => $search,
                            'queue' => 'history'
                        ], static fn($value) => $value !== ''))) ?>"
                        class="btn btn-sm <?= $queue === 'history' ? 'btn-crf-primary' : 'btn-crf-outline' ?>"
                    >
                        Riwayat
                    </a>
                </div>
            </div>

            <!-- SEARCH -->
            <?php
            $listContextName = 'queue';
            $listContextValue = $queue;
            $listResetUrl = 'index.php';
            require __DIR__ . '/../includes/partials/crf_list_filters.php';
            unset($listContextName, $listContextValue, $listResetUrl);
            ?>

            <div class="table-responsive crf-table-responsive-cards crf-portal-table-wrap">
                <table class="table crf-table crf-portal-table crf-portal-table--automation align-middle">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Pengajuan</th>
                            <th>Isi Pengajuan</th>
                            <th>Urgensi</th>
                            <th>Status</th>
                            <th>SLA</th>
                            <?php if ($queue !== 'history'): ?>
                                <th>Status SLA</th>
                            <?php endif; ?>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td data-label="Pengajuan" colspan="<?= $queue === 'history' ? 7 : 8 ?>" class="text-center text-muted py-4">
                                <?= $queue === 'history'
                                    ? 'Belum ada CRF yang pernah diproses oleh Otomasi.'
                                    : 'Tidak ada CRF pada antrean Otomasi sesuai filter yang dipilih.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $i => $row): ?>
                            <tr>
                                <td data-label="No"><?= $offset + $i + 1 ?></td>

                                <?php $rowShowDepartment = false; $rowShowHandler = true; require __DIR__ . '/../includes/partials/crf_row_request.php'; ?>
                                <?php require __DIR__ . '/../includes/partials/crf_row_status.php'; ?>

                                <td data-label="SLA">
                                    <?= $row['sla_nilai'] !== null && $row['sla_satuan']
                                        ? h(rtrim(rtrim(number_format((float) $row['sla_nilai'], 2, '.', ''), '0'), '.')) . ' ' . h($row['sla_satuan'])
                                        : '-' ?>
                                </td>

                                <?php if ($queue !== 'history'): ?>
                                    <?php $rowSlaStatus = getCrfSlaStatus($row); ?>
                                    <?php
                                    // Belum diambil PIC: tampilkan status respon; setelah diambil, hanya status SLA.
                                    $rowResponStatus = empty($row['pic_id']) ? getCrfResponStatus($row) : null;
                                    $rowResponLive = $rowResponStatus !== null && !empty($rowResponStatus['live']) && !empty($row['sla_respon_tenggat']);
                                    ?>
                                    <?php
                                    // Satu fokus per baris: belum diambil = Response Time (Resolution Time jadi baris abu-abu),
                                    // sudah diambil = Resolution Time. Durasi dipersingkat agar muat di kolom sempit.
                                    $rowSlaShort = $rowSlaStatus['remaining'] !== null
                                        ? 'sisa ' . formatSlaDurationShort((int) $rowSlaStatus['remaining'])
                                        : ($rowSlaStatus['overdue'] !== null ? 'terlambat ' . formatSlaDurationShort((int) $rowSlaStatus['overdue']) : '');
                                    $rowSlaTimes = !empty($row['sla_tenggat'])
                                        ? 'data-sla-started-at="' . (int) strtotime((string) ($row['sla_mulai'] ?? '')) . '" data-sla-due-at="' . (int) strtotime($row['sla_tenggat']) . '"'
                                        : '';
                                    ?>
                                    <td data-label="Status SLA">
                                        <div class="automation-sla-cell">
                                            <?php if ($rowResponLive): ?>
                                                <div
                                                    class="automation-sla-main"
                                                    data-sla-respon-live="true" data-sla-short="true"
                                                    data-sla-started-at="<?= (int) strtotime((string) ($row['sla_mulai'] ?? '')) ?>"
                                                    data-sla-due-at="<?= (int) strtotime($row['sla_respon_tenggat']) ?>"
                                                >
                                                    <span class="automation-sla-kicker">Response Time</span>
                                                    <span class="badge text-bg-<?= h($rowResponStatus['class']) ?>" data-sla-respon-label><?= h($rowResponStatus['label']) ?></span>
                                                    <small class="automation-sla-status-detail" data-sla-respon-detail><?= $rowResponStatus['remaining'] !== null
                                                        ? 'sisa ' . h(formatSlaDurationShort((int) $rowResponStatus['remaining']))
                                                        : 'terlambat ' . h(formatSlaDurationShort((int) ($rowResponStatus['overdue'] ?? 0))) ?></small>
                                                </div>
                                                <div class="automation-sla-sub">
                                                    Resolution Time<?= $rowSlaShort !== '' ? ' · ' . h($rowSlaShort) : '' ?>
                                                </div>
                                            <?php else: ?>
                                                <div
                                                    class="automation-sla-main"
                                                    <?= $rowSlaStatus['live'] && !empty($row['sla_tenggat'])
                                                        ? 'data-sla-countdown="true" data-sla-short="true" ' . $rowSlaTimes
                                                        : '' ?>
                                                >
                                                    <span class="automation-sla-kicker">Resolution Time</span>
                                                    <span class="badge text-bg-<?= h($rowSlaStatus['class']) ?>" data-sla-status-label>
                                                        <?= $rowSlaStatus['label'] === 'Melewati SLA'
                                                            ? 'SLA Terlewati'
                                                            : h($rowSlaStatus['label']) ?>
                                                    </span>
                                                    <small class="automation-sla-status-detail" data-sla-status-detail><?= $rowSlaShort !== '' ? h($rowSlaShort) : h($rowSlaStatus['detail']) ?></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>

                                <td data-label="Aksi">
                                    <?php
                                    // Satu tombol per baris: Buka (belum diambil), Lanjutkan (milik saya),
                                    // Lihat (dipegang PIC lain, hanya baca), Detail (riwayat).
                                    if ($queue === 'history' || (!empty($row['pic_id']) && !isAssignedCrfHandler($row))) {
                                        $rowActionUrl = 'view_detail.php?id=' . (int) $row['id'];
                                        $rowActionLabel = $queue === 'history' ? 'Detail' : 'Lihat';
                                        $rowActionClass = 'btn-crf-outline';
                                        $rowActionIcon = 'bi-eye';
                                    } else {
                                        $rowActionUrl = 'detail.php?id=' . (int) $row['id'];
                                        $rowActionLabel = empty($row['pic_id']) ? 'Buka' : 'Lanjutkan';
                                        $rowActionClass = 'btn-crf-primary';
                                        $rowActionIcon = 'bi-arrow-right-circle';
                                    }
                                    ?>
                                    <a href="<?= h($rowActionUrl) ?>" class="btn btn-sm <?= h($rowActionClass) ?>">
                                        <i class="bi <?= h($rowActionIcon) ?>"></i> <?= h($rowActionLabel) ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- =================================================
                 PAGINATION
                 ================================================= -->
            <?php
            $paginationLabel = 'Navigasi halaman Otomasi';
            require __DIR__ . '/../includes/partials/crf_list_pagination.php';
            unset($paginationLabel);
            ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
