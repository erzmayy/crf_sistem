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
    if ($role !== 'pemohon') {
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

    if (getCrfRole() === 'admin') {
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
 * Handler yang memegang CRF (atau admin). CRF yang belum di-assign
 * harus "diambil" terlebih dahulu sebelum diproses.
 */
function isAssignedCrfHandler(array $crf): bool
{
    if (getCrfRole() === 'admin') {
        return true;
    }

    return !empty($crf['assigned_handler_id'])
        && (int) $crf['assigned_handler_id'] === (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Apakah user yang sedang login berperan sebagai admin (superuser)?
 * Mengacu ke crf_user_roles, sama seperti seluruh pengecekan akses
 * lainnya. Aturan lama berbasis dept/divisi (config/crf.php) tidak
 * dipakai lagi.
 */
function isAdmin(): bool
{
    return getCrfRole() === 'admin';
}

function isCrfRole(string $role): bool
{
    return getCrfRole() === $role;
}

/**
 * Membatasi halaman untuk role tertentu.
 * Role 'admin' adalah superuser: selalu diizinkan, sehingga satu
 * akun admin bisa dipakai mendemokan seluruh alur (CMO, Otomasi,
 * Kepala Departemen Operasional).
 */
function requireCrfRole($roles): void
{
    requireLogin();

    $roles = is_array($roles) ? $roles : [$roles];
    $currentRole = getCrfRole();

    if ($currentRole === 'admin') {
        return;
    }

    if (!in_array($currentRole, $roles, true)) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Anda tidak memiliki akses ke halaman tersebut.'
        ];

        switch ($currentRole) {
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
            return 'Handler (Otomasi)';
        case 'kadep_operasional':
            return 'Kepala Departemen Operasional';
        case 'admin':
            return 'Admin';
        default:
            return 'Pemohon';
    }
}

/**
 * Memastikan user sudah login dan berperan sebagai admin.
 */
function requireAdmin(): void
{
    requireCrfRole(['admin']);
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

    // Handler hanya boleh melihat CRF pada kategori yang ditanganinya.
    if ($role === 'otomasi') {
        return canHandleCrf($pdo, $row);
    }

    return in_array($role, ['admin', 'cmo', 'kadep_operasional'], true);
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

    if (getCrfRole() === 'admin' || isHelpdeskPic(getConnection(), (int) $_SESSION['user_id'])) {
        return;
    }

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Halaman ini khusus PIC kategori Helpdesk.',
    ];
    header('Location: ../helpdesk/saya.php');
    exit;
}