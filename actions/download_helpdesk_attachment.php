<?php
/**
 * actions/download_helpdesk_attachment.php
 * Download lampiran ticket Helpdesk (pemilik, PIC kategori, admin,
 * atau pihak CRF terkait).
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

$pdo = getConnection();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM helpdesk_attachments WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

$ticket = findHelpdeskTicket($pdo, (int) $file['helpdesk_ticket_id']);
if (!$ticket || !canAccessTicket($pdo, $ticket)) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke file ini.');
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
    error_log('download_helpdesk_attachment error: ' . $curlError . ' (HTTP ' . $httpCode . ')');
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
