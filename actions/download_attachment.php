<?php
/**
 * actions/download_attachment.php
 * Download lampiran CRF. Untuk user yang boleh mengakses CRF (lihat
 * canAccessCrf) dan peran Forum selama CRF masih dibahas di Forum.
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
    'SELECT change_request_id, original_name, file_path, file_type, file_size
     FROM attachments
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
    !canAccessCrf($pdo, (int) $file['change_request_id'])
    && !canViewForumCrf($pdo, (int) $file['change_request_id'])
) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke file ini.');
}

$fileUrl = $file['file_path'];

// Ambil file dari Wasabi
$ch = curl_init($fileUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$fileContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

if ($fileContent === false || $httpCode !== 200) {
    error_log('download_attachment error: ' . $curlError . ' (HTTP ' . $httpCode . ')');
    http_response_code(500);
    exit('Gagal mengambil file. Silakan coba lagi atau hubungi administrator.');
}

// Nama file untuk hasil download (buang karakter yang bisa merusak header)
$downloadName = str_replace(
    ['"', '\\', "\r", "\n"],
    '',
    basename($file['original_name'])
);

header('Content-Type: ' . ($file['file_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . strlen($fileContent));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

echo $fileContent;
exit;