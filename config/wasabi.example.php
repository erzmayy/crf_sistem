<?php
/**
 * Contoh konfigurasi penyimpanan lampiran (Wasabi / S3-compatible).
 * Salin menjadi config/wasabi.php lalu isi kredensial. File config/wasabi.php
 * di-gitignore; jangan di-commit dan jangan dikirim bersama source code.
 *
 * Lampiran diunggah sebagai objek PRIVATE dan hanya dibuka lewat
 * actions/download_attachment.php (setelah cek hak akses). Pastikan bucket
 * tidak memiliki bucket policy yang membuka baca untuk publik pada
 * folder 'upload_path'.
 */
return [
    'version' => 'latest',

    'region' => 'ap-southeast-1',

    'endpoint' => 'https://s3.ap-southeast-1.wasabisys.com',

    'credentials' => [
        'key' => '',
        'secret' => '',
    ],

    'use_path_style_endpoint' => true,

    // Nama bucket dan awalan folder di dalam bucket.
    'bucket' => 'nama-bucket',

    'upload_path' => 'crf/uploads/',
];
