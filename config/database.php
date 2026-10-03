<?php
/**
 * Set timezone Indonesia supaya tanggal/waktu (Hari/Tanggal, solved_at,
 * cancelled_at, dsb.) selalu konsisten walau php.ini XAMPP default
 * belum diatur ke Asia/Jakarta.
 */
date_default_timezone_set('Asia/Jakarta');

/**
 * config/database.php
 * ---------------------------------------------------------------
 * Koneksi database menggunakan PDO + prepared statements.
 * Ubah DB_USER / DB_PASS di sini jika konfigurasi XAMPP kamu
 * berbeda dari default (root, tanpa password).
 * ---------------------------------------------------------------
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'crf_sistem');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+07:00'"); // <-- tambahan ini
        return $pdo;
    } catch (PDOException $e) {
        // Jangan tampilkan error SQL mentah ke user (lihat butir 31 - Keamanan).
        error_log('Database connection error: ' . $e->getMessage());
        die('Koneksi ke database gagal. Silakan hubungi administrator sistem.');
    }
}
