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
 * Grup menu CRF untuk user yang sedang login.
 *
 * Setiap item: label, url (relatif terhadap akar modul CRF, diawali '/'),
 * show (boleh tampil), active (halaman ini), badge (angka belum dibaca).
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
    $isPicUser = $isAdminUser || isHelpdeskPic($pdo, $userId);

    return [
        [
            'label' => 'Dashboard',
            'icon' => 'bi-grid',
            'items' => [
                // Satu halaman master Kategori & Handling (tab Helpdesk / CRF).
                ['label' => 'Kategori & Handling', 'url' => '/admin/master_data.php', 'show' => $isAdminUser, 'active' => $isNav('admin', 'master_data.php')],
                // Satu dashboard kategori untuk Helpdesk & CRF (tab per jenis).
                ['label' => 'Dashboard Handling Kategori', 'url' => '/helpdesk/handling.php', 'show' => $isPicUser || in_array($crfRole, ['cmo', 'otomasi', 'kadep_operasional', 'demo'], true), 'active' => $isNav('helpdesk', 'handling.php') || $isNav('helpdesk', 'kategori.php')],
            ],
        ],
        [
            'label' => 'Help Desk',
            'icon' => 'bi-headset',
            'items' => [
                ['label' => 'Dashboard Help Desk', 'url' => '/helpdesk/dashboard.php', 'show' => $isPicUser, 'active' => $isNav('helpdesk', 'dashboard.php')],
                ['label' => 'Formulir Help Desk', 'url' => '/helpdesk/form.php', 'show' => true, 'active' => $isNav('helpdesk', 'form.php')],
                ['label' => 'Tiket Saya', 'url' => '/helpdesk/saya.php', 'show' => true, 'active' => $isNav('helpdesk', 'saya.php') || $isNav('helpdesk', 'detail.php')],
            ],
        ],
        [
            'label' => 'Change Request (CRF)',
            'icon' => 'bi-file-earmark-diff',
            'items' => [
                ['label' => 'Dashboard CRF', 'url' => '/admin/dashboard.php', 'show' => $isAdminUser, 'active' => $isNav('admin', 'dashboard.php') || $isNav('admin', 'detail.php') || $isNav('admin', 'edit.php')],
                ['label' => 'Verifikasi CMO', 'url' => '/cmo/index.php', 'show' => $canRole('cmo'), 'active' => $currentFolder === 'cmo'],
                ['label' => 'Tindak Lanjut Otomasi', 'url' => '/otomasi/index.php', 'show' => $canRole('otomasi'), 'active' => $currentFolder === 'otomasi'],
                ['label' => 'Persetujuan Kepala Departemen Operasional', 'url' => '/pak_joko/index.php', 'show' => $canRole('kadep_operasional'), 'active' => $currentFolder === 'pak_joko'],
                ['label' => 'Form CRF', 'url' => '/user/form_crf.php', 'show' => true, 'active' => $isNav('user', 'form_crf.php')],
                ['label' => 'Pengajuan CRF Saya', 'url' => '/user/pengajuan_saya.php', 'show' => in_array($crfRole, ['pemohon', 'demo'], true), 'active' => $isNav('user', 'pengajuan_saya.php') || $isNav('user', 'detail.php')],
                ['label' => 'Forum', 'url' => '/forum/index.php', 'show' => in_array($crfRole, forumRoles(), true), 'active' => $isForum, 'badge' => $forumUnreadTotal],
            ],
        ],
    ];
}
