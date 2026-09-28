<?php
/**
 * includes/session.php
 * ---------------------------------------------------------------
 * Menangani session user yang sudah login.
 * Data user bisa diambil dari tabel users lokal atau siap.tbl_user,
 * tergantung konfigurasi CRF_USER_SOURCE di config/siap.php.
 * ---------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/siap_user_provider.php';

/**
 * Mengambil data user aktif dari database.
 * Menyimpan hasilnya ke $_SESSION supaya tidak query berulang-ulang.
 */
function getCurrentUser(): array
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit;
    }

    if (!isset($_SESSION['active_user'])) {
        $pdo = getConnection();
        $user = findCrfUserById($pdo, (int) $_SESSION['user_id']);

        if (!$user) {
            $_SESSION = [];
            session_destroy();

            header('Location: ../login.php');
            exit;
        }

        $_SESSION['active_user'] = $user;
    }

    return $_SESSION['active_user'];
}

/**
 * Helper kecil untuk escape output (mencegah XSS).
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/csrf.php';
