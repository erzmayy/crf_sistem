<?php

require_once __DIR__ . '/session.php';

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

    return $cache = 'pemohon';
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
            case 'pak_joko':
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
            return 'Otomasi';
        case 'pak_joko':
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
 * - Role admin/cmo/otomasi/pak_joko: boleh, kecuali CRF berstatus Draft.
 */
function canAccessCrf(PDO $pdo, int $crfId): bool
{
    if ($crfId <= 0 || !isset($_SESSION['user_id'])) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT user_id, status
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

    return in_array(
        getCrfRole(),
        ['admin', 'cmo', 'otomasi', 'pak_joko'],
        true
    );
}