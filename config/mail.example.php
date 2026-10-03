<?php
/**
 * Contoh konfigurasi email notifikasi.
 * Salin menjadi config/mail.php lalu isi kredensial SMTP perusahaan.
 * Jika config/mail.php tidak ada atau 'enabled' = false, notifikasi
 * email dilewati (status 'skipped') dan notifikasi in-app tetap jalan.
 */
return [
    'enabled'    => false,
    'host'       => 'smtp.example.com',
    'port'       => 587,
    'encryption' => 'tls', // tls | ssl | ''
    'username'   => '',
    'password'   => '',
    'from_email' => 'no-reply@example.com',
    'from_name'  => 'Helpdesk & CRF PPU',
    // URL dasar aplikasi untuk link di email, contoh: https://siap.ptppu.co.id/crf_sistem
    'app_url'    => 'http://localhost/crf_sistem',
];
