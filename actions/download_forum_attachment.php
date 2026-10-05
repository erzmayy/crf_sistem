<?php
/**
 * actions/download_forum_attachment.php
 * Download lampiran komentar Forum (hanya peran yang memiliki akses Forum;
 * lampiran dari komentar yang sudah dihapus tidak dapat diunduh).
 */
require_once __DIR__ . '/../includes/forum.php';

requireForumAccess();

$pdo = getConnection();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT attachment.original_name, attachment.file_path, attachment.file_type
     FROM forum_attachments attachment
     INNER JOIN forum_comments comments
        ON comments.id = attachment.forum_comment_id
     WHERE attachment.id = :id
       AND comments.deleted_at IS NULL
     LIMIT 1'
);
$stmt->execute(['id' => $id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

$ch = curl_init($file['file_path']);
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
    error_log('download_forum_attachment error: ' . $curlError . ' (HTTP ' . $httpCode . ')');
    http_response_code(500);
    exit('Gagal mengambil file. Silakan coba lagi atau hubungi administrator.');
}

$downloadName = str_replace(['"', '\\', "\r", "\n"], '', basename($file['original_name']));

header('Content-Type: ' . ($file['file_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . strlen($fileContent));
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $fileContent;
exit;
