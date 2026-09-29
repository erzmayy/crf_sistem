<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

$user = getCurrentUser();
$pdo = getConnection();

/*
 * =========================================================
 * Ringkasan Pengajuan Saya
 * Draft tidak dihitung sebagai pengajuan resmi.
 * =========================================================
 */
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Belum Ditindak Lanjuti') AS pending,
        SUM(status = 'Perlu Revisi') AS revision,
        SUM(status = 'Dalam Proses') AS processing,
        SUM(status = 'Solve') AS solved,
        SUM(status = 'Cancel') AS cancelled
    FROM change_requests
    WHERE user_id = :user_id
      AND status <> 'Draft'
");

$summaryStmt->execute([
    'user_id' => $user['id']
]);

$summary = $summaryStmt->fetch();

/* =========================================================
 * Pencarian dan Filter
 * ========================================================= */

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');

$allowedStatuses = [
    'Draft',
    'Belum Ditindak Lanjuti',
    'Perlu Revisi',
    'Dalam Proses',
    'Solve',
    'Cancel'
];

$allowedCategories = [
    'Aplikasi',
    'Infrastruktur',
    'Proses',
    'Security',
    'Lainnya'
];

/*
 * Kalau filter tidak valid, kosongkan.
 */
if (
    $statusFilter !== ''
    && !in_array($statusFilter, $allowedStatuses, true)
) {
    $statusFilter = '';
}

if (
    $categoryFilter !== ''
    && !in_array($categoryFilter, $allowedCategories, true)
) {
    $categoryFilter = '';
}


/* =========================================================
 * Query
 * ========================================================= */

$where = [
    'user_id = :user_id'
];

$params = [
    'user_id' => $user['id']
];


/*
 * Pencarian:
 * Nomor Register atau isi perubahan yang diminta.
 */
if ($search !== '') {

    $where[] = '(
        request_number LIKE :search_request
        OR full_name LIKE :search_name
        OR change_description LIKE :search_description
    )';

    $searchValue = '%' . $search . '%';

    $params['search_request'] = $searchValue;
    $params['search_name'] = $searchValue;
    $params['search_description'] = $searchValue;
}

/*
 * Filter Status
 */
if ($statusFilter !== '') {

    $where[] = 'status = :status';

    $params['status'] = $statusFilter;
}


/*
 * Filter Kategori
 */
if ($categoryFilter !== '') {

    $where[] = 'change_category = :category';

    $params['category'] = $categoryFilter;
}


/* =========================================================
 * Pagination
 * ========================================================= */

$perPage = 10;

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);


/* =========================================================
 * Hitung total data
 * ========================================================= */

$countSql = "
    SELECT COUNT(*)
    FROM change_requests
    WHERE " . implode(' AND ', $where);

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalRows = (int) $countStmt->fetchColumn();

$totalPages = max(
    1,
    (int) ceil($totalRows / $perPage)
);


/*
 * Kalau page yang diminta melebihi halaman terakhir,
 * arahkan ke halaman terakhir.
 */
if ($page > $totalPages) {
    $page = $totalPages;
}


$offset = ($page - 1) * $perPage;


/* =========================================================
 * Ambil data sesuai halaman
 * ========================================================= */

$sql = "
    SELECT
        id,
        request_number,
        full_name,
        submission_date,
        level,
        status,
        workflow_stage,
        change_description,
        tanggapan_tindak_lanjut
    FROM change_requests
    WHERE " . implode(' AND ', $where) . "
    ORDER BY id DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$pengajuan = $stmt->fetchAll();

/*
 * Ambil pesan flash dari proses Submit / Draft / aksi lainnya.
 */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/*
 * Judul halaman untuk browser dan breadcrumb.
 */
$pageTitle = 'Pengajuan Saya';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page pt-4">
    <div class="container">
<!-- <style>
    /* =========================================================
       PENGAJUAN SAYA - LEBAR KOLOM TETAP
       ========================================================= */
    .pengajuan-table-wrap {
        overflow-x: auto;
        width: 100%;
    }

    .pengajuan-table {
        width: 100%;
        min-width: 1360px;
        table-layout: fixed;
    }

    .pengajuan-table th:nth-child(1),  .pengajuan-table td:nth-child(1)  { width: 40px; }
    .pengajuan-table th:nth-child(2),  .pengajuan-table td:nth-child(2)  { width: 150px; }
    .pengajuan-table th:nth-child(3),  .pengajuan-table td:nth-child(3)  { width: 150px; }
    .pengajuan-table th:nth-child(4),  .pengajuan-table td:nth-child(4)  { width: 110px; }
    .pengajuan-table th:nth-child(5),  .pengajuan-table td:nth-child(5)  { width: 90px; }
    .pengajuan-table th:nth-child(6),  .pengajuan-table td:nth-child(6)  { width: 110px; }
    .pengajuan-table th:nth-child(7),  .pengajuan-table td:nth-child(7)  { width: 130px; }
    .pengajuan-table th:nth-child(8),  .pengajuan-table td:nth-child(8)  { width: 220px; overflow-wrap: break-word; }
    .pengajuan-table th:nth-child(9),  .pengajuan-table td:nth-child(9)  { width: 220px; overflow-wrap: break-word; }
    .pengajuan-table th:nth-child(10), .pengajuan-table td:nth-child(10) { width: 260px; }

    /* Potong teks jadi 1 baris + "..." untuk kolom yang isinya identitas/nama */
    .pengajuan-cell-truncate {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
</style> -->
        <?php if ($flash): ?>
        <div
            class="alert alert-<?= h($flash['type']) ?> crf-alert"
            role="alert"
        >
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

        <!-- HEADER HALAMAN -->
        <div class="d-flex justify-content-between align-items-center mb-4">

            <div class="crf-page-header">
                <h1>Pengajuan Saya</h1>

                <p class="text-muted mb-0">
                    Ringkasan Pengajuan Saya.
                </p>
            </div>

            <a
                href="form_crf.php"
                class="btn btn-primary"
            >
                + Buat Pengajuan
            </a>

        </div>

        <!-- =========================================================
            RINGKASAN PENGAJUAN SAYA
            ========================================================= -->

        <div class="mb-4">

            <div class="crf-stat-grid crf-user-stat-grid">

                <div class="crf-stat-card">
                    <span>Total Pengajuan</span>
                    <strong>
                        <?= (int) ($summary['total'] ?? 0) ?>
                    </strong>
                </div>

                <div class="crf-stat-card">
                    <span>Belum Ditindak Lanjuti</span>
                    <strong>
                        <?= (int) ($summary['pending'] ?? 0) ?>
                    </strong>
                </div>

                <div class="crf-stat-card">
                    <span>Perlu Revisi</span>
                    <strong>
                        <?= (int) ($summary['revision'] ?? 0) ?>
                    </strong>
                </div>

                <div class="crf-stat-card">
                    <span>Dalam Proses</span>
                    <strong>
                        <?= (int) ($summary['processing'] ?? 0) ?>
                    </strong>
                </div>

                <div class="crf-stat-card">
                    <span>Selesai</span>
                    <strong>
                        <?= (int) ($summary['solved'] ?? 0) ?>
                    </strong>
                </div>

                <div class="crf-stat-card">
                    <span>Dibatalkan</span>
                    <strong>
                        <?= (int) ($summary['cancelled'] ?? 0) ?>
                    </strong>
                </div>

            </div>

        </div>


        <!-- TABEL PENGAJUAN -->
        <div class="card">

            <div class="card-body">

                <?php if (empty($pengajuan)): ?>

                    <div class="text-center py-5">

                        <p class="text-muted mb-3">
                            Belum ada pengajuan CRF.
                        </p>

                        <a
                            href="form_crf.php"
                            class="btn btn-primary"
                        >
                            Buat Pengajuan
                        </a>

                    </div>

                <?php else: ?>

                    <form method="GET" class="mb-4">

                        <div class="row g-2">

                            <div class="col-md-5">
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Cari Nomor Register atau Nama Pengaju..."
                                    value="<?= h($search) ?>"
                                >
                            </div>

                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">Semua Status</option>

                                    <?php foreach ($allowedStatuses as $statusOption): ?>
                                        <option
                                            value="<?= h($statusOption) ?>"
                                            <?= $statusFilter === $statusOption ? 'selected' : '' ?>
                                        >
                                            <?= h(statusLabel($statusOption)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <select name="category" class="form-select">
                                    <option value="">Semua Kategori</option>

                                    <?php foreach ($allowedCategories as $categoryOption): ?>
                                        <option
                                            value="<?= h($categoryOption) ?>"
                                            <?= $categoryFilter === $categoryOption ? 'selected' : '' ?>
                                        >
                                            <?= h($categoryOption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <button
                                    type="submit"
                                    class="btn btn-primary w-100"
                                    title="Cari"
                                >
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>

                        </div>

                        <?php if (
                            $search !== ''
                            || $statusFilter !== ''
                            || $categoryFilter !== ''
                        ): ?>

                            <div class="mt-2">
                                <a
                                    href="pengajuan_saya.php"
                                    class="small text-decoration-none"
                                >
                                    Reset pencarian & filter
                                </a>
                            </div>

                        <?php endif; ?>

                    </form>

                    <div class="table-responsive crf-table-responsive-cards pengajuan-table-wrap">

                    <table class="table crf-table pengajuan-table align-middle">
                            <thead>

                                <tr>
                                    <th>No</th>
                                    <th>Nomor Register</th>
                                    <th>Pengaju</th>
                                    <th>Tanggal Pengajuan</th>
                                    <th>Level Urgensi</th>
                                    <th>Status</th>
                                    <th>Tahap</th>
                                    <th>Perubahan yang Diminta</th>
                                    <th>Tanggapan / Tindak Lanjut</th>
                                    <th>Aksi</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($pengajuan as $index => $row): ?>

                                <tr>

                                    <!-- NO -->
                                    <td data-label="No">
                                        <?= $offset + $index + 1 ?>
                                    </td>


                                                                        <!-- NOMOR REGISTER -->
                                    <td data-label="Nomor Register">
                                        <?php if (!empty($row['request_number'])): ?>
                                            <?= h($row['request_number']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>


                                    <!-- NAMA PENGAJU -->
                                    <td data-label="Pengaju">
                                        <span class="pengajuan-cell-truncate" title="<?= h($row['full_name']) ?>">
                                            <?= h($row['full_name']) ?>
                                        </span>
                                    </td>


                                    <!-- TANGGAL -->
                                    <td data-label="Tanggal">
                                        <?php if (!empty($row['submission_date'])): ?>
                                            <?= date(
                                                'd-m-Y',
                                                strtotime($row['submission_date'])
                                            ) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>


                                    <!-- LEVEL -->
                                    <td data-label="Level">
                                        <?= h($row['level'] ?? '-') ?>
                                    </td>


                                    <!-- STATUS -->
                                    <td data-label="Status">

                                        <span class="crf-badge <?= statusBadgeClass($row['status']) ?>">
                                            <?= h(statusLabel($row['status'])) ?>
                                        </span>

                                    </td>

                                    <td data-label="Tahap">
                                        <span class="crf-badge <?= workflowStageBadgeClass($row['workflow_stage'] ?? 'PEMOHON') ?>">
                                            <?= h(workflowStageLabel($row['workflow_stage'] ?? 'PEMOHON')) ?>
                                        </span>
                                    </td>


                                    <!-- PERUBAHAN -->
                                    <td data-label="Perubahan" title="<?= h($row['change_description'] ?? '-') ?>">
                                        <?= h(mb_strimwidth($row['change_description'] ?? '-', 0, 60, '...')) ?>
                                    </td>


                                    <!-- TANGGAPAN -->
                                    <td data-label="Tanggapan" title="<?= h($row['tanggapan_tindak_lanjut'] ?? '-') ?>">

                                        <?php if (!empty($row['tanggapan_tindak_lanjut'])): ?>

                                            <?= nl2br(
                                                h($row['tanggapan_tindak_lanjut'])
                                            ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                Belum ada tanggapan.
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                   <!-- AKSI -->
                                <td data-label="Aksi">

                                    <div class="d-flex gap-2">

                                        <!-- DETAIL -->
                                        <a
                                            href="detail.php?id=<?= (int) $row['id'] ?>"
                                            class="btn btn-sm btn-primary"
                                        >
                                            Detail
                                        </a>


                                        <!-- CETAK / EXPORT PDF -->
                                        <?php if (
                                            $row['status'] !== 'Draft'
                                            && $row['status'] !== 'Perlu Revisi'
                                        ): ?>

                                            <a
                                                href="../actions/export_crf.php?id=<?= (int) $row['id'] ?>"
                                                class="btn btn-sm btn-outline-secondary"
                                                target="_blank"
                                                title="Cetak PDF"
                                            >
                                                <i class="bi bi-printer"></i>
                                                Cetak
                                            </a>

                                        <?php endif; ?>


                                        <!-- EDIT / KIRIM ULANG -->
                                        <?php if (
                                            $row['status'] === 'Draft'
                                            || $row['status'] === 'Perlu Revisi'
                                        ): ?>

                                            <a
                                                href="form_crf.php?id=<?= (int) $row['id'] ?>"
                                                class="btn btn-sm btn-warning"
                                            >
                                                <?= $row['status'] === 'Perlu Revisi'
                                                    ? 'Edit'
                                                    : 'Edit'
                                                ?>
                                            </a>

                                        <?php endif; ?>

                                                                                <!-- HAPUS DRAFT -->
                                        <?php if ($row['status'] === 'Draft'): ?>

                                            <form
                                                action="../actions/delete_draft.php"
                                                method="POST"
                                                onsubmit="return confirm('Hapus draft ini? Tindakan ini tidak bisa dibatalkan.');"
                                                class="d-inline"
                                            >
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <?= csrfField() ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    Hapus
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php if ($totalPages > 1): ?>

                        <nav aria-label="Pagination pengajuan" class="mt-3">

                            <ul class="pagination justify-content-end mb-0">

                                <?php
                                $prevParams = $_GET;
                                $prevParams['page'] = max(1, $page - 1);

                                $nextParams = $_GET;
                                $nextParams['page'] = min($totalPages, $page + 1);
                                ?>

                                <!-- Previous -->
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="?<?= h(http_build_query($prevParams)) ?>"
                                        aria-label="Previous"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                </li>


                                <!-- Nomor halaman -->
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>

                                    <?php
                                    $pageParams = $_GET;
                                    $pageParams['page'] = $p;
                                    ?>

                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">

                                        <a
                                            class="page-link"
                                            href="?<?= h(http_build_query($pageParams)) ?>"
                                        >
                                            <?= $p ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>


                                <!-- Next -->
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="?<?= h(http_build_query($nextParams)) ?>"
                                        aria-label="Next"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </ul>

                        </nav>

                    <?php endif; ?> 

                <?php endif; ?>

            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>