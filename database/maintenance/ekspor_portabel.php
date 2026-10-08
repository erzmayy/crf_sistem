<?php
/**
 * database/maintenance/ekspor_portabel.php
 * ---------------------------------------------------------------
 * Ekspor database CRF ke berkas .sql yang AMAN diimpor di komputer lain
 * (MySQL 5.7 / 8 maupun MariaDB), tanpa galat DEFINER, collation
 * utf8mb4_0900_ai_ci, atau "charset utf8mb4".
 *
 * Ekspor langsung dari database aktif:
 *   php database/maintenance/ekspor_portabel.php
 *   php database/maintenance/ekspor_portabel.php keluaran.sql
 *
 * Bersihkan berkas ekspor yang sudah ada (mis. dari phpMyAdmin):
 *   php database/maintenance/ekspor_portabel.php bersihkan masukan.sql [keluaran.sql]
 *
 * Impor di komputer tujuan:
 *   mysql -uroot -e "CREATE DATABASE IF NOT EXISTS crf_sistem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *   mysql -uroot crf_sistem < berkas.sql
 * ---------------------------------------------------------------
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari terminal.');
}

require_once __DIR__ . '/../../config/database.php';

/** Perbaiki satu baris dump agar portabel. */
function ekspor_bersihkan_baris(string $line): string
{
    // DEFINER akun lokal (akun itu belum tentu ada di komputer tujuan).
    $line = preg_replace('/DEFINER\s*=\s*`[^`]+`@`[^`]*`\s?/i', '', $line);
    $line = preg_replace("/DEFINER\s*=\s*'[^']+'@'[^']*'\s?/i", '', $line);
    // Collation khusus MySQL 8.
    $line = preg_replace('/utf8mb4_0900_[a-z0-9_]+/i', 'utf8mb4_unicode_ci', $line);
    // MySQL menuliskan CAST(... CHARACTER SET x) sebagai "charset x"; MariaDB tidak mengenalnya.
    $line = preg_replace('/\bcharset (utf8mb4|utf8|latin1)\)/i', 'character set $1)', $line);

    return $line;
}

function ekspor_cari_mysqldump(): ?string
{
    $env = getenv('MYSQLDUMP');
    if ($env && is_file($env)) {
        return $env;
    }
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $probe = $isWindows ? 'where mysqldump 2>NUL' : 'command -v mysqldump 2>/dev/null';
    $found = trim((string) strtok((string) shell_exec($probe), "\r\n"));
    if ($found !== '' && is_file($found)) {
        return $found;
    }
    foreach (array_merge(
        glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [],
        glob('C:/xampp/mysql/bin/mysqldump.exe') ?: [],
        glob('C:/wamp64/bin/mysql/*/bin/mysqldump.exe') ?: [],
        ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/homebrew/bin/mysqldump']
    ) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

$args = array_slice($argv, 1);
$mode = 'ekspor';
if (($args[0] ?? '') === 'bersihkan') {
    $mode = 'bersihkan';
    array_shift($args);
}

if ($mode === 'bersihkan') {
    $in = $args[0] ?? '';
    if ($in === '' || !is_file($in)) {
        fwrite(STDERR, "Pakai: php ekspor_portabel.php bersihkan masukan.sql [keluaran.sql]\n");
        exit(1);
    }
    $out = $args[1] ?? preg_replace('/\.sql$/i', '', $in) . '_portabel.sql';
    $reader = fopen($in, 'rb');
    $writer = fopen($out, 'wb');
    $changed = 0;
    while (($line = fgets($reader)) !== false) {
        $clean = ekspor_bersihkan_baris($line);
        $changed += $clean !== $line ? 1 : 0;
        fwrite($writer, $clean);
    }
    fclose($reader);
    fclose($writer);
    echo "Selesai: {$changed} baris diperbaiki.\nBerkas portabel: {$out}\n";
    exit(0);
}

$dump = ekspor_cari_mysqldump();
if ($dump === null) {
    fwrite(STDERR, "mysqldump tidak ditemukan. Set variabel lingkungan MYSQLDUMP ke lokasi mysqldump,\n"
        . "atau ekspor dari phpMyAdmin lalu jalankan: php ekspor_portabel.php bersihkan berkas.sql\n");
    exit(1);
}

$out = $args[0] ?? __DIR__ . '/../../backups/crf_sistem_portabel_' . date('Y-m-d_His') . '.sql';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}

$command = [
    $dump,
    '--host=' . DB_HOST,
    '--user=' . DB_USER,
    '--single-transaction',
    '--default-character-set=utf8mb4',
    '--no-tablespaces',
    '--skip-triggers',
    DB_NAME,
];
$env = array_merge(getenv(), ['MYSQL_PWD' => (string) DB_PASS]);
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
if (!is_resource($process)) {
    fwrite(STDERR, "Gagal menjalankan mysqldump.\n");
    exit(1);
}

$writer = fopen($out, 'wb');
$changed = 0;
$lines = 0;
while (($line = fgets($pipes[1])) !== false) {
    $clean = ekspor_bersihkan_baris($line);
    $changed += $clean !== $line ? 1 : 0;
    $lines++;
    fwrite($writer, $clean);
}
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
fclose($writer);
$exit = proc_close($process);

if ($exit !== 0 || $lines === 0) {
    @unlink($out);
    fwrite(STDERR, "mysqldump gagal (kode {$exit}):\n" . trim($errors) . "\n");
    exit(1);
}

echo "Selesai: {$lines} baris, {$changed} baris diperbaiki.\nBerkas portabel: " . realpath($out) . "\n";
echo "Impor di komputer tujuan:\n"
    . "  mysql -uroot -e \"CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"\n"
    . "  mysql -uroot " . DB_NAME . " < " . basename($out) . "\n";
