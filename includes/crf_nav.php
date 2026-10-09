<?php
/**
 * includes/crf_nav.php
 * ---------------------------------------------------------------
 * Menu CRF sebagai DATA (tanpa HTML), supaya bisa dipakai oleh
 * sidebar CRF sendiri (mode "standalone") maupun menu SIAP
 * (mode "module").
 *
 * Mode tampilan diatur lewat config/siap.php:
 *   CRF_LAYOUT_MODE      'standalone' | 'module'
 *   CRF_BASE_URL         awalan URL modul di dalam SIAP (mis. '/modules/crf')
 *   CRF_LOAD_BOOTSTRAP   muat Bootstrap sendiri pada mode module
 *   CRF_LOAD_ICONS       muat Bootstrap Icons sendiri pada mode module
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/forum.php';

/** True bila CRF ditampilkan di dalam layout SIAP (tanpa header/sidebar sendiri). */
function crfLayoutIsModule(): bool
{
    return (defined('CRF_LAYOUT_MODE') ? CRF_LAYOUT_MODE : '') === 'module' || getenv('CRF_LAYOUT_MODE') === 'module';
}

/**
 * Jumlah CRF yang menunggu tindakan peran antrean (angka pada menu).
 * Aturannya sama dengan halaman antrean masing-masing:
 *   cmo     : verifikasi (CMO_FILTER, termasuk yang menunggu hasil Forum) + UAT + finalisasi (CMO_FINAL)
 *   kadep   : menunggu persetujuan (kadep_operasional)
 *   otomasi : antrean implementasi pada kategori yang ditangani, dan yang melewati SLA
 * Hanya peran yang menunya tampil yang dihitung (satu query ringan per peran).
 *
 * @return array{cmo:int,cmo_verifikasi:int,cmo_uat:int,cmo_final:int,kadep:int,pic:int,pic_overdue:int}
 */
function crfQueueCounts(PDO $pdo, bool $cmo, bool $kadep, bool $pic): array
{
    $counts = ['cmo' => 0, 'cmo_verifikasi' => 0, 'cmo_uat' => 0, 'cmo_final' => 0, 'kadep' => 0, 'pic' => 0, 'pic_overdue' => 0];
    $open = "cr.status NOT IN ('Draft','Solve','Cancel')";

    if ($cmo) {
        $row = $pdo->query("
            SELECT SUM(cr.workflow_stage = 'CMO_FILTER') AS verifikasi,
                   SUM(cr.workflow_stage = 'UAT')        AS uat,
                   SUM(cr.workflow_stage = 'CMO_FINAL')  AS finalisasi
            FROM change_requests cr
            WHERE {$open} AND cr.workflow_stage IN ('CMO_FILTER','UAT','CMO_FINAL')
        ")->fetch() ?: [];
        $counts['cmo_verifikasi'] = (int) ($row['verifikasi'] ?? 0);
        $counts['cmo_uat'] = (int) ($row['uat'] ?? 0);
        $counts['cmo_final'] = (int) ($row['finalisasi'] ?? 0);
        $counts['cmo'] = $counts['cmo_verifikasi'] + $counts['cmo_uat'] + $counts['cmo_final'];
    }

    if ($kadep) {
        $counts['kadep'] = (int) $pdo->query("
            SELECT COUNT(*) FROM change_requests cr
            WHERE {$open} AND cr.workflow_stage = 'kadep_operasional'
        ")->fetchColumn();
    }

    if ($pic) {
        $row = $pdo->query("
            SELECT COUNT(*) AS total,
                   SUM(cr.sla_due_at IS NOT NULL AND cr.sla_due_at < NOW()) AS terlambat
            FROM change_requests cr
            WHERE {$open} AND " . crfHandlerScopeSql($pdo, 'cr') . "
              AND cr.workflow_stage = 'OTOMASI' AND cr.kadep_operasional_approved_at IS NOT NULL
        ")->fetch() ?: [];
        $counts['pic'] = (int) ($row['total'] ?? 0);
        $counts['pic_overdue'] = (int) ($row['terlambat'] ?? 0);
    }

    return $counts;
}

/**
 * Grup menu CRF untuk user yang sedang login.
 *
 * Setiap item: label, url (relatif terhadap akar modul CRF, diawali '/'),
 * show (boleh tampil), active (halaman ini), badge (angka), badge_label (teks tooltip).
 *
 * @return array<int,array{label:string,icon:string,items:array<int,array<string,mixed>>}>
 */
function crfNavGroups(): array
{
    $pdo = getConnection();
    $currentUser = getCurrentUser();
    $userId = (int) ($currentUser['id'] ?? 0);

    $crfRole = getCrfRole();
    $isDemoUser = $crfRole === 'demo';
    $isAdminUser = isAdmin();
    $canRole = static fn(string $role): bool => $isDemoUser || $crfRole === $role;

    $currentPath = basename($_SERVER['PHP_SELF'] ?? '');
    $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $currentFolder = basename(dirname($scriptPath));
    $isNav = static fn(string $folder, ?string $file = null): bool =>
        $currentFolder === $folder && ($file === null || $currentPath === $file);

    $isForum = $currentFolder === 'forum';
    $forumUnreadTotal = in_array($crfRole, forumRoles(), true)
        ? forumUnreadTotal($pdo, $userId)
        : 0;
    $queue = crfQueueCounts($pdo, $canRole('cmo'), $canRole('kadep_operasional'), $canRole('otomasi'));

    return [
        [
            'label' => 'Change Request (CRF)',
            'icon' => 'bi-file-earmark-diff',
            'items' => [
                ['label' => 'Dashboard CRF', 'url' => '/admin/dashboard.php', 'show' => $isAdminUser, 'active' => $isNav('admin', 'dashboard.php') || $isNav('admin', 'detail.php') || $isNav('admin', 'edit.php')],
                ['label' => 'Kategori CRF', 'url' => '/admin/master_data.php', 'show' => $isAdminUser, 'active' => $isNav('admin', 'master_data.php')],
                ['label' => 'Verifikasi CMO', 'url' => '/cmo/index.php', 'show' => $canRole('cmo'), 'active' => $currentFolder === 'cmo', 'badge' => $queue['cmo'], 'badge_label' => $queue['cmo'] . ' CRF menunggu tindakan CMO (verifikasi ' . $queue['cmo_verifikasi'] . ', UAT ' . $queue['cmo_uat'] . ', finalisasi ' . $queue['cmo_final'] . ')'],
                ['label' => 'Tindak Lanjut Otomasi', 'url' => '/otomasi/index.php', 'show' => $canRole('otomasi'), 'active' => $currentFolder === 'otomasi', 'badge' => $queue['pic'], 'badge_label' => $queue['pic'] . ' CRF menunggu implementasi' . ($queue['pic_overdue'] > 0 ? ', ' . $queue['pic_overdue'] . ' melewati SLA' : '')],
                ['label' => 'Persetujuan Kepala Departemen Operasional', 'url' => '/pak_joko/index.php', 'show' => $canRole('kadep_operasional'), 'active' => $currentFolder === 'pak_joko', 'badge' => $queue['kadep'], 'badge_label' => $queue['kadep'] . ' CRF menunggu persetujuan'],
                ['label' => 'Handling CRF', 'url' => '/crf/handling.php', 'show' => $isAdminUser || in_array($crfRole, ['cmo', 'otomasi', 'kadep_operasional', 'demo'], true), 'active' => $isNav('crf', 'handling.php')],
                ['label' => 'Form CRF', 'url' => '/user/form_crf.php', 'show' => true, 'active' => $isNav('user', 'form_crf.php')],
                ['label' => 'Pengajuan CRF Saya', 'url' => '/user/pengajuan_saya.php', 'show' => in_array($crfRole, ['pemohon', 'demo'], true), 'active' => $isNav('user', 'pengajuan_saya.php') || $isNav('user', 'detail.php')],
                ['label' => 'Forum', 'url' => '/forum/index.php', 'show' => in_array($crfRole, forumRoles(), true), 'active' => $isForum, 'badge' => $forumUnreadTotal],
            ],
        ],
    ];
}
