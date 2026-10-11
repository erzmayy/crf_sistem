<?php
/**
 * tools/wasabi_make_private.php  (khusus CLI, dijalankan sekali)
 * Mengubah ACL semua lampiran CRF yang sudah terlanjur diunggah sebagai
 * public-read menjadi private. Lampiran baru sudah private sejak
 * uploadAttachmentsToStorage() memakai ACL 'private'.
 *
 * Pakai:
 *   php tools/wasabi_make_private.php           (simulasi, tidak mengubah apa pun)
 *   php tools/wasabi_make_private.php --apply   (benar-benar mengubah ACL)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$apply = in_array('--apply', $argv, true);
$pdo = getConnection();
$client = crfWasabiClient();
$bucket = crfWasabiConfig()['bucket'];

$rows = $pdo->query('SELECT id, nama_tersimpan, path_file FROM crf_lampiran ORDER BY id')->fetchAll();

$done = 0;
$failed = 0;

foreach ($rows as $row) {
    $storedName = (string) ($row['nama_tersimpan'] ?? '');
    if ($storedName === '') {
        $storedName = basename((string) parse_url((string) $row['path_file'], PHP_URL_PATH));
    }
    if ($storedName === '') {
        echo "#{$row['id']}: nama file kosong, dilewati\n";
        $failed++;
        continue;
    }

    $key = crfAttachmentKey($storedName);

    if (!$apply) {
        echo "#{$row['id']}: akan di-private-kan -> {$key}\n";
        $done++;
        continue;
    }

    try {
        $client->putObjectAcl(['Bucket' => $bucket, 'Key' => $key, 'ACL' => 'private']);
        echo "#{$row['id']}: private -> {$key}\n";
        $done++;
    } catch (Throwable $e) {
        echo "#{$row['id']}: GAGAL {$key} (" . $e->getMessage() . ")\n";
        $failed++;
    }
}

echo "\n" . ($apply ? 'Selesai' : 'Simulasi') . ": {$done} berhasil/diproses, {$failed} gagal/dilewati dari " . count($rows) . " lampiran.\n";
if (!$apply) {
    echo "Jalankan lagi dengan --apply untuk mengubah ACL.\n";
}
