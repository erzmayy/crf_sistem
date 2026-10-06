<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/categories.php';

/**
 * Memastikan user sudah login.
 * Semua user yang sudah login boleh membuka Form CRF dan Pengajuan Saya.
 */
function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Mengambil role workflow CRF dari tabel crf_user_roles.
 * Ini satu-satunya sumber kebenaran untuk role user.
 * Jika user belum punya baris role aktif, dianggap 'pemohon'.
 */
function getCrfRole(): string
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    requireLogin();

    if (CRF_ROLE_SOURCE === 'resolver') {
        $user = getCurrentUser();
        return $cache = promoteCategoryHandlerRole(resolveCrfRoleFromUser($user));
    }

    try {
        $stmt = getConnection()->prepare(
            'SELECT role
             FROM crf_user_roles
             WHERE user_id = :user_id
               AND is_active = 1
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $_SESSION['user_id']]);
        $role = $stmt->fetchColumn();

        if (is_string($role) && $role !== '') {
            return $cache = $role;
        }
    } catch (Throwable $e) {
        error_log('getCrfRole fallback: ' . $e->getMessage());
    }

    return $cache = promoteCategoryHandlerRole('pemohon');
}

/**
 * User biasa yang didaftarkan Admin di Handling Kategori otomatis
 * mendapat role 'otomasi' (Handler), sehingga halaman otomasi/* bisa
 * dipakai sebagai halaman Handler. Role lain tidak diubah.
 */
function promoteCategoryHandlerRole(string $role): string
{
    if (!in_array($role, ['pemohon', 'admin'], true)) {
        return $role;
    }

    try {
        if (isCrfCategoryHandler(getConnection(), (int) $_SESSION['user_id'])) {
            return 'otomasi';
        }
    } catch (Throwable $e) {
        error_log('promoteCategoryHandlerRole: ' . $e->getMessage());
    }

    return $role;
}

/**
 * User Otomasi "lama" (daftar userid di config/siap.php). Mereka tetap
 * menangani CRF tanpa kategori dan kategori yang belum punya handler.
 */
function isLegacyOtomasiUser(): bool
{
    return CRF_ROLE_SOURCE === 'resolver'
        ? crfUserIdIn(getCurrentUser(), CRF_OTOMASI_USERIDS)
        : getCrfRole() === 'otomasi';
}

/**
 * Lingkup kategori CRF yang boleh ditangani user aktif.
 *
 * @return array{all:bool,category_ids:int[],uncategorized:bool}
 */
function crfHandlerScope(PDO $pdo): array
{
    static $scope = null;

    if ($scope !== null) {
        return $scope;
    }

    // Hanya akun demo yang boleh memproses semua kategori; Admin tidak memproses CRF.
    if (isDemoUser()) {
        return $scope = ['all' => true, 'category_ids' => [], 'uncategorized' => true];
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $categoryIds = handledCrfCategoryIds($pdo, $userId);
    $uncategorized = false;

    if (getCrfRole() === 'otomasi' && isLegacyOtomasiUser()) {
        $categoryIds = array_merge($categoryIds, unhandledCrfCategoryIds($pdo));
        $uncategorized = true;
    }

    return $scope = [
        'all' => false,
        'category_ids' => array_values(array_unique($categoryIds)),
        'uncategorized' => $uncategorized,
    ];
}

/**
 * Kondisi SQL lingkup handler untuk query list/summary (alias tabel change_requests).
 */
function crfHandlerScopeSql(PDO $pdo, string $alias = 'cr'): string
{
    $scope = crfHandlerScope($pdo);

    if ($scope['all']) {
        return '1 = 1';
    }

    $parts = [];
    if ($scope['category_ids']) {
        $parts[] = $alias . '.crf_category_id IN (' . implode(',', array_map('intval', $scope['category_ids'])) . ')';
    }
    if ($scope['uncategorized']) {
        $parts[] = $alias . '.crf_category_id IS NULL';
    }

    return $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0';
}

/**
 * Apakah user aktif boleh memproses CRF ini sebagai Handler?
 */
function canHandleCrf(PDO $pdo, array $crf): bool
{
    $scope = crfHandlerScope($pdo);

    if ($scope['all']) {
        return true;
    }

    if (getCrfRole() !== 'otomasi') {
        return false;
    }

    $categoryId = (int) ($crf['crf_category_id'] ?? 0);

    if ($categoryId === 0) {
        return $scope['uncategorized'];
    }

    return in_array($categoryId, $scope['category_ids'], true);
}

/**
 * PIC CRF yang memegang CRF (akun demo selalu boleh). Pemegang
 * tercatat saat PIC CRF menyelesaikan eksekusi.
 */
function isAssignedCrfHandler(array $crf): bool
{
    if (isDemoUser()) {
        return true;
    }

    return !empty($crf['assigned_handler_id'])
        && (int) $crf['assigned_handler_id'] === (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Punya hak akses Admin (pengelola & pemantau)?
 * Berlaku untuk role 'admin', akun 'demo', dan user di CRF_ADMIN_USERIDS
 * yang juga punya peran kerja lain (hak Admin sebagai tambahan).
 */
function isAdmin(): bool
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if (in_array(getCrfRole(), ['admin', 'demo'], true)) {
        return $cache = true;
    }

    return $cache = CRF_ROLE_SOURCE === 'resolver'
        && crfUserIdIn(getCurrentUser(), CRF_ADMIN_USERIDS);
}

/**
 * Hak Admin tidak boleh dipakai pada CRF yang sedang dipegang user itu
 * sendiri sebagai Handler (pemisahan tugas). Akun demo dikecualikan.
 */
function isOwnHandledCrf(array $crf): bool
{
    return !isDemoUser()
        && !empty($crf['assigned_handler_id'])
        && (int) $crf['assigned_handler_id'] === (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Akun demo presentasi: boleh menjalankan semua peran (lihat config/siap.php).
 */
function isDemoUser(): bool
{
    return getCrfRole() === 'demo';
}

function isCrfRole(string $role): bool
{
    return getCrfRole() === $role;
}

/**
 * Membatasi halaman untuk role tertentu.
 * - Akun 'demo' selalu diizinkan (satu akun untuk presentasi semua peran).
 * - Role 'admin' hanya diizinkan bila 'admin' disebut di $roles: Admin
 *   mengelola & memantau, tidak menjalankan aksi CMO/Handler/Kadep.
 */
function requireCrfRole($roles): void
{
    requireLogin();

    $roles = is_array($roles) ? $roles : [$roles];
    $currentRole = getCrfRole();

    if ($currentRole === 'demo') {
        return;
    }

    if (!in_array($currentRole, $roles, true)) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Anda tidak memiliki akses ke halaman tersebut.'
        ];

        switch ($currentRole) {
            case 'admin':
                header('Location: ../admin/dashboard.php');
                break;
            case 'cmo':
                header('Location: ../cmo/dashboard.php');
                break;
            case 'otomasi':
                header('Location: ../otomasi/dashboard.php');
                break;
            case 'kadep_operasional':
                header('Location: ../pak_joko/dashboard.php');
                break;
            default:
                header('Location: ../user/pengajuan_saya.php');
                break;
        }
        exit;
    }
}

function crfRoleLabel(string $role): string
{
    switch ($role) {
        case 'cmo':
            return 'CMO';
        case 'otomasi':
            return 'PIC CRF';
        case 'kadep_operasional':
            return 'Kepala Departemen Operasional';
        case 'admin':
            return 'Admin';
        case 'demo':
            return 'Demo (Semua Peran)';
        case 'sistem':
            return 'Sistem';
        default:
            return 'Pemohon';
    }
}

/**
 * Memastikan user sudah login dan berperan sebagai admin.
 */
function requireAdmin(): void
{
    requireLogin();

    if (isAdmin()) {
        return;
    }

    requireCrfRole(['admin']); // menolak & mengarahkan sesuai peran
}

/**
 * Apakah user yang login boleh mengakses CRF (dan lampirannya)?
 * - Pemilik CRF: boleh (termasuk saat masih Draft).
 * - Role admin/cmo/otomasi/kadep_operasional: boleh, kecuali CRF berstatus Draft.
 */
function canAccessCrf(PDO $pdo, int $crfId): bool
{
    if ($crfId <= 0 || !isset($_SESSION['user_id'])) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT user_id, status, crf_category_id
         FROM change_requests
         WHERE id = :id
         LIMIT 1'
    );

    $stmt->execute(['id' => $crfId]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    if ((int) $row['user_id'] === (int) $_SESSION['user_id']) {
        return true;
    }

    if ($row['status'] === 'Draft') {
        return false;
    }

    $role = getCrfRole();

    // Hak Admin: boleh melihat semua CRF (non-draft).
    if (isAdmin()) {
        return true;
    }

    // Handler hanya boleh melihat CRF pada kategori yang ditanganinya.
    if ($role === 'otomasi') {
        return canHandleCrf($pdo, $row);
    }

    return in_array($role, ['admin', 'demo', 'cmo', 'kadep_operasional'], true);
}

/**
 * Halaman khusus Handler: role otomasi + CRF berada pada kategorinya.
 * Mengembalikan baris CRF jika lolos.
 */
function requireCrfHandlerAccess(PDO $pdo, int $crfId, string $redirect): array
{
    requireCrfRole(['otomasi']);

    $stmt = $pdo->prepare('SELECT * FROM change_requests WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $crfId]);
    $crf = $stmt->fetch();

    if (!$crf || !canHandleCrf($pdo, $crf)) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'CRF tidak ditemukan atau bukan kategori yang Anda tangani.',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    return $crf;
}

/**
 * Halaman Helpdesk untuk PIC (atau admin).
 */
function requireHelpdeskPic(): void
{
    requireLogin();

    if (isAdmin() || isHelpdeskPic(getConnection(), (int) $_SESSION['user_id'])) {
        return;
    }

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Halaman ini khusus PIC kategori Helpdesk.',
    ];
    header('Location: ../helpdesk/saya.php');
    exit;
}