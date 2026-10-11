<?php
/**
 * actions/download_attachment.php
 * Download / tampilkan lampiran CRF. Untuk user yang boleh mengakses CRF
 * (lihat canAccessCrf) dan peran Forum selama CRF masih dibahas di Forum.
 *
 * Objek di Wasabi bersifat private; satu-satunya jalur membuka file
 * adalah endpoint ini. ?inline=1 menampilkan PDF/gambar di browser.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forum.php';

requireLogin();

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('ID attachment tidak valid.');
}

$stmt = $pdo->prepare(
    'SELECT pengajuan_id, nama_asli, nama_tersimpan, path_file, tipe_file, ukuran_file
     FROM crf_lampiran
     WHERE id = :id
     LIMIT 1'
);

$stmt->execute(['id' => $id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

if (
    !canAccessCrf($pdo, (int) $file['pengajuan_id'])
    && !canViewForumCrf($pdo, (int) $file['pengajuan_id'])
) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke file ini.');
}

$storedName = (string) ($file['nama_tersimpan'] ?? '');
if ($storedName === '') {
    // Baris lama tanpa nama_tersimpan: ambil nama file dari URL yang tersimpan.
    $storedName = basename((string) parse_url((string) $file['path_file'], PHP_URL_PATH));
}

if ($storedName === '') {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

// Ambil file dari Wasabi memakai kredensial server (bukan URL publik).
try {
    $object = crfWasabiClient()->getObject([
        'Bucket' => crfWasabiConfig()['bucket'],
        'Key' => crfAttachmentKey($storedName),
    ]);
    $fileContent = (string) $object['Body'];
} catch (Throwable $e) {
    error_log('download_attachment error: ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengambil file. Silakan coba lagi atau hubungi administrator.');
}

// Nama file untuk hasil download (buang karakter yang bisa merusak header)
$downloadName = str_replace(
    ['"', '\\', "\r", "\n"],
    '',
    basename($file['nama_asli'])
);

// Hanya tipe yang lolos validasi upload yang boleh tampil inline.
$mimeType = in_array($file['tipe_file'], CRF_ALLOWED_MIME_TYPES, true)
    ? $file['tipe_file']
    : 'application/octet-stream';
$inline = isset($_GET['inline']) && $mimeType !== 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"');
header('Content-Length: ' . strlen($fileContent));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, must-revalidate');
header('Pragma: no-cache');

echo $fileContent;
exit;
