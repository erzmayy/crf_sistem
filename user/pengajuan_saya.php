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
$categoryFilter = (int) ($_GET['category'] ?? 0);

$allowedStatuses = [
    'Draft',
    'Belum Ditindak Lanjuti',
    'Perlu Revisi',
    'Dalam Proses',
    'Solve',
    'Cancel'
];

// Kategori CRF dinamis (master crf_categories): [id => nama].
$allowedCategories = array_column(crfCategories(getConnection(), false), 'name', 'id');

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
    $categoryFilter !== 0
    && !isset($allowedCategories[$categoryFilter])
) {
    $categoryFilter = 0;
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
if ($categoryFilter !== 0) {

    $where[] = 'crf_category_id = :category';

    $params['category'] = $categoryFilter;
}


/* =========================================================
 * Pagination
 * ========================================================= */

$perPage = getCrfPageSize($_GET['per_page'] ?? 6);

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
        from_department,
        submission_date,
        change_category,
        crf_category_id,
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

<div class="crf-page crf-helpdesk-page pt-4">
    <div class="container">
        <?php if ($flash): ?>
        <div
            class="alert alert-<?= h($flash['type']) ?> crf-alert"
            role="alert"
        >
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

        <!-- HEADER HALAMAN -->
        <div class="pengajuan-page-banner">

            <div class="crf-page-header">
                <span class="pengajuan-page-eyebrow">PORTAL CRF</span>
                <h1>Pengajuan Saya</h1>

                <p class="text-muted mb-0">
                    Pantau status, urgensi, dan tindak lanjut pengajuan perubahan Anda.
                </p>
            </div>

            <a
                href="form_crf.php"
                class="btn btn-light pengajuan-create-button"
            >
                <i class="bi bi-plus-lg"></i>
                Buat Pengajuan
            </a>

        </div>

        <!-- =========================================================
            RINGKASAN PENGAJUAN SAYA
            ========================================================= -->

        <div class="mb-3">

            <div class="crf-stat-grid crf-user-stat-grid">

                <div class="crf-stat-card pengajuan-stat-card pengajuan-stat-total">
                    <span><i class="bi bi-inboxes-fill"></i> Total Pengajuan</span>
                    <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
                </div>

                <div class="crf-stat-card pengajuan-stat-card pengajuan-stat-pending">
                    <span><i class="bi bi-hourglass-split"></i> Belum Ditindak Lanjuti</span>
                    <strong><?= (int) ($summary['pending'] ?? 0) ?></strong>
                </div>

                <div class="crf-stat-card pengajuan-stat-card pengajuan-stat-revision">
                    <span><i class="bi bi-pencil-square"></i> Perlu Revisi</span>
                    <strong><?= (int) ($summary['revision'] ?? 0) ?></strong>
                </div>

                <div class="crf-stat-card pengajuan-stat-card pengajuan-stat-processing">
                    <span><i class="bi bi-arrow-repeat"></i> Dalam Proses</span>
                    <strong><?= (int) ($summary['processing'] ?? 0) ?></strong>
                </div>

                <div class="crf-stat-card pengajuan-stat-card pengajuan-stat-solved">
                    <span><i class="bi bi-check-circle-fill"></i> Selesai</span>
                    <strong><?= (int) ($summary['solved'] ?? 0) ?></strong>
                </div>

                <div class="crf-stat-card pengajuan-stat-card pengajuan-stat-cancelled">
                    <span><i class="bi bi-slash-circle-fill"></i> Dibatalkan</span>
                    <strong><?= (int) ($summary['cancelled'] ?? 0) ?></strong>
                </div>

            </div>

        </div>


        <!-- TABEL PENGAJUAN -->
        <div class="card pengajuan-list-card">

            <div class="card-body">

                <?php if (
                    empty($pengajuan)
                    && $search === ''
                    && $statusFilter === ''
                    && $categoryFilter === 0
                ): ?>

                    <div class="crf-empty-state">
                        <div class="crf-empty-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>

                        <h3>Belum ada pengajuan CRF</h3>
                        <p>Ajukan perubahan baru untuk memulai proses CRF.</p>

                        <a
                            href="form_crf.php"
                            class="btn btn-primary"
                        >
                            Buat Pengajuan
                        </a>

                    </div>

                <?php else: ?>

                    <div class="pengajuan-list-heading">
                        <div>
                            <h2>Daftar Pengajuan CRF</h2>
                            <p>Pantau perkembangan setiap permintaan perubahan.</p>
                        </div>
                        <span class="pengajuan-result-pill">
                            <i class="bi bi-list-check"></i>
                            <?= number_format($totalRows, 0, ',', '.') ?> pengajuan
                        </span>
                    </div>

                    <form method="GET" class="mb-4 pengajuan-filters">
                        <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">

                        <div class="row g-2">

                            <div class="col-md-5">
                                <label class="pengajuan-filter-label" for="pengajuan-search">Cari pengajuan</label>
                                <input
                                    type="text"
                                    id="pengajuan-search"
                                    name="search"
                                    class="form-control"
                                    placeholder="Nomor register atau keterangan..."
                                    value="<?= h($search) ?>"
                                >
                            </div>

                            <div class="col-md-3">
                                <label class="pengajuan-filter-label" for="pengajuan-status">Status</label>
                                <select id="pengajuan-status" name="status" class="form-select">
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
                                <label class="pengajuan-filter-label" for="pengajuan-category">Kategori</label>
                                <select id="pengajuan-category" name="category" class="form-select">
                                    <option value="">Semua Kategori</option>

                                    <?php foreach ($allowedCategories as $categoryOptionId => $categoryOption): ?>
                                        <option
                                            value="<?= (int) $categoryOptionId ?>"
                                            <?= $categoryFilter === (int) $categoryOptionId ? 'selected' : '' ?>
                                        >
                                            <?= h($categoryOption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <button
                                    type="submit"
                                    class="btn btn-primary w-100 pengajuan-filter-submit"
                                    title="Cari"
                                    aria-label="Terapkan filter"
                                >
                                    <i class="bi bi-search"></i>
                                    <span>Lihat</span>
                                </button>
                            </div>

                        </div>

                        <?php if (
                            $search !== ''
                            || $statusFilter !== ''
                            || $categoryFilter !== 0
                        ): ?>

                            <div class="pengajuan-filter-reset">
                                <a
                                    href="pengajuan_saya.php"
                                    class="small text-decoration-none"
                                >
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset filter aktif
                                </a>
                            </div>

                        <?php endif; ?>

                    </form>

                    <div class="table-responsive crf-table-responsive-cards pengajuan-table-wrap crf-helpdesk-table-wrap">

                    <table class="table crf-table pengajuan-table crf-helpdesk-table crf-helpdesk-table--user align-middle">
                            <thead>

                                <tr>
                                    <th>No</th>
                                    <th>Keterangan Pengajuan</th>
                                    <th>Isi Pengajuan</th>
                                    <th>Level Urgensi</th>
                                    <th>Status</th>
                                    <th>Tahap</th>
                                    <th>Aksi</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php if (empty($pengajuan)): ?>
                                <tr>
                                    <td data-label="Pengajuan" colspan="7" class="crf-empty-cell">
                                        <div class="crf-empty-state crf-empty-state-compact">
                                            <div class="crf-empty-icon">
                                                <i class="bi bi-search"></i>
                                            </div>
                                            <h3>CRF tidak ditemukan</h3>
                                            <p>Belum ada pengajuan yang sesuai dengan pencarian atau filter.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                            <?php foreach ($pengajuan as $index => $row): ?>

                                <tr>

                                    <!-- NO -->
                                    <td data-label="No">
                                        <?= $offset + $index + 1 ?>
                                    </td>


                                    <!-- KETERANGAN PENGAJUAN -->
                                    <td data-label="Keterangan Pengajuan">
                                        <div class="crf-request-meta">
                                            <strong><?= h($row['full_name'] ?? '-') ?></strong>
                                            <span class="crf-request-caption"><?= h($row['from_department'] ?? '-') ?></span>
                                            <span class="crf-request-caption">Nomor Register</span>
                                            <span class="crf-request-register"><?= h($row['request_number'] ?? '-') ?></span>
                                            <div class="crf-request-date-card">
                                                <span class="crf-request-caption">Tanggal Pengajuan</span>
                                                <span><?= !empty($row['submission_date'])
                                                    ? h(date('d-m-Y', strtotime($row['submission_date'])))
                                                    : '-' ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- ISI PENGAJUAN -->
                                    <td data-label="Isi Pengajuan">
                                        <div class="crf-request-content">
                                            <span class="crf-request-category-chip"><?= h(crfCategoryName($row, 'Kategori belum dipilih')) ?></span>
                                            <div class="crf-request-description"><?= h($row['change_description'] ?? '-') ?></div>
                                        </div>
                                    </td>

                                    <!-- LEVEL -->
                                    <td data-label="Level Urgensi">
                                        <span
                                            class="crf-badge <?= levelBadgeClass($row['level'] ?? null) ?>"
                                            title="<?= h(($row['level'] ?? null) === 'Normal' ? 'Sedang' : ($row['level'] ?? '-')) ?>"
                                        >
                                            <?= h(($row['level'] ?? null) === 'Normal' ? 'Sedang' : ($row['level'] ?? '-')) ?>
                                        </span>
                                    </td>

                                    <!-- STATUS -->
                                    <td data-label="Status">
                                        <span
                                            class="crf-badge <?= statusBadgeClass($row['status']) ?>"
                                            title="<?= h(statusLabel($row['status'])) ?>"
                                        >
                                            <?= h(statusLabel($row['status'])) ?>
                                        </span>
                                    </td>

                                    <!-- TAHAP -->
                                    <td data-label="Tahap">
                                        <span
                                            class="crf-badge <?= workflowStageBadgeClass($row['workflow_stage'] ?? 'PEMOHON') ?>"
                                            title="<?= h(workflowStageLabel($row['workflow_stage'] ?? 'PEMOHON')) ?>"
                                        >
                                            <?= h(workflowStageLabel($row['workflow_stage'] ?? 'PEMOHON')) ?>
                                        </span>
                                    </td>

                                   <!-- AKSI -->
                                <td data-label="Aksi">

                                    <div class="d-flex gap-2 pengajuan-actions">

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


                                        <!-- ISI POST IMPLEMENTATION REVIEW -->
                                        <?php if (($row['workflow_stage'] ?? '') === 'PEMOHON_PIR'): ?>

                                            <a
                                                href="detail.php?id=<?= (int) $row['id'] ?>#implementation-review"
                                                class="btn btn-sm btn-success"
                                            >
                                                <i class="bi bi-pencil-square"></i>
                                                Isi PIR
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
                                                    ? 'Perbaiki'
                                                    : 'Lanjutkan'
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
                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php
                    $paginationLabel = 'Navigasi halaman pengajuan saya';
                    require __DIR__ . '/../includes/partials/crf_list_pagination.php';
                    unset($paginationLabel);
                    ?>

                <?php endif; ?>

            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>