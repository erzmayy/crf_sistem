<?php
/**
 * Password demo KHUSUS LOKAL untuk mencoba akun user SIAP tertentu
 * tanpa mengubah password asli di tabel siap.tbl_user.
 *
 * Salin menjadi config/dev_login.php (file itu di-gitignore, jadi tidak
 * ikut ter-commit / ter-deploy). Jika config/dev_login.php tidak ada atau
 * 'enabled' = false, login berjalan normal memakai password SIAP.
 *
 * Pengaman tambahan: password demo hanya diterima jika request berasal
 * dari komputer lokal (127.0.0.1 / ::1), walaupun file ini terbawa ke server.
 */
return [
    'enabled' => false,
    'password' => 'ganti-password-demo',

    // userid SIAP yang boleh login memakai password demo.
    'userids' => [
        // '3736',
    ],

    // Atau semua user pada departemen tertentu (kolom dept).
    'depts' => [
        // 'CMO',
    ],
];
