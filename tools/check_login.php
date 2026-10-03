<?php
/**
 * tools/check_login.php  (khusus CLI, untuk diagnosa lokal)
 * Mengecek skema hash password user SIAP tanpa menampilkan password/hash.
 *
 * Pakai (PowerShell):
 *   $env:CRF_TEST_PW = Read-Host "Password"; php tools/check_login.php N76559; Remove-Item Env:CRF_TEST_PW
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/siap_user_provider.php';

$userid = $argv[1] ?? '';
$plain = (string) getenv('CRF_TEST_PW');

if ($userid === '' || $plain === '') {
    fwrite(STDERR, "Isi userid sebagai argumen dan password lewat env CRF_TEST_PW.\n");
    exit(1);
}

$user = findCrfUserByUserid(getConnection(), $userid);
if (!$user) {
    echo "User {$userid} tidak ditemukan di " . crfUserTable() . ".\n";
    exit(1);
}

echo "User ditemukan: {$user['nama']} (id {$user['id']})\n";
echo 'Login CRF saat ini: ' . (verifyCrfUserPassword($plain, $user) ? 'COCOK' : 'TIDAK COCOK') . "\n\n";

$schemes = [
    'md5'              => md5($plain),
    'md5 (huruf besar)' => strtoupper(md5($plain)),
    'md5(md5)'         => md5(md5($plain)),
    'sha1'             => sha1($plain),
    'md5(sha1)'        => md5(sha1($plain)),
    'sha1(md5)'        => sha1(md5($plain)),
    'md5 + trim'       => md5(trim($plain)),
];

foreach (['password', 'password_new'] as $column) {
    $hash = (string) ($user[$column] ?? '');
    if ($hash === '') {
        echo "- {$column}: kosong\n";
        continue;
    }
    $matched = [];
    if (preg_match('/^\$2[ayb]\$/', $hash) && password_verify($plain, $hash)) {
        $matched[] = 'bcrypt';
    }
    foreach ($schemes as $name => $value) {
        if (hash_equals($hash, $value)) {
            $matched[] = $name;
        }
    }
    echo "- {$column}: " . ($matched ? 'cocok dengan ' . implode(', ', $matched) : 'tidak cocok dengan skema yang dicoba') . "\n";
}
