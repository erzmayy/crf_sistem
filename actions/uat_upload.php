<?php
/**
 * actions/uat_upload.php
 * Unggah dokumen hasil UAT sebagai bukti pengujian.
 *   - CMO     : saat CRF berada di tahap UAT (CMO yang menguji).
 *   - Admin   : kapan pun setelah UAT dimulai (menyimpan bukti/dokumentasi).
 * Dokumen disimpan di tabel attachments dengan category = 'uat'.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$pdo = getConnection();
$user = getCurrentUser();
$role = getCrfRole();
$isAdminRole = in_array($role, ['admin', 'demo'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!$isAdminRole && $role !== 'cmo')) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Anda tidak memiliki akses untuk mengunggah dokumen UAT.'];
    header('Location: ../index.php');
    exit;
}

verifyCsrf();

$id = (int) ($_POST['id'] ?? 0);
$backPage = $role === 'cmo' || $role === 'demo' ? '../cmo/detail.php?id=' : '../admin/detail.php?id=';
// Admin kembali ke halaman Admin; akun demo mengikuti halaman asal bila dikirim.
if ($role === 'demo' && ($_POST['back'] ?? '') === 'admin') {
    $backPage = '../admin/detail.php?id=';
}
$back = $backPage . $id;

$stmt = $pdo->prepare('SELECT id, status, workflow_stage, automation_completed_at, request_number FROM change_requests WHERE id = :id');
$stmt->execute(['id' => $id]);
$crf = $stmt->fetch();

if (!$crf || in_array($crf['status'], ['Draft', 'Cancel'], true) || empty($crf['automation_completed_at'])) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dokumen UAT hanya dapat diunggah setelah Otomasi menyelesaikan eksekusi.'];
    header('Location: ' . $back);
    exit;
}

if ($role === 'cmo' && $crf['workflow_stage'] !== 'UAT') {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CMO mengunggah dokumen UAT saat CRF berada pada tahap UAT.'];
    header('Location: ' . $back);
    exit;
}

$files = $_FILES['uat_files'] ?? null;
if (!$files || empty($files['name']) || empty($files['name'][0])) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Pilih minimal satu dokumen hasil UAT.'];
    header('Location: ' . $back);
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM attachments WHERE change_request_id = :id AND category = 'uat'");
$countStmt->execute(['id' => $id]);
$before = (int) $countStmt->fetchColumn();

try {
    $errors = handleUatDocumentUploads($pdo, $id, $files);
} catch (Throwable $e) {
    error_log('uat_upload error: ' . $e->getMessage());
    $errors = ['Terjadi kesalahan saat menyimpan dokumen UAT.'];
}

$countStmt->execute(['id' => $id]);
$uploaded = (int) $countStmt->fetchColumn() - $before;

if ($uploaded > 0) {
    $actor = crfActorName($user);
    logCrfActivity(
        $pdo,
        $id,
        'Dokumen UAT Diunggah',
        $actor . ' mengunggah ' . $uploaded . ' dokumen hasil UAT.',
        $actor
    );
}

if ($errors) {
    $_SESSION['flash'] = [
        'type' => $uploaded > 0 ? 'warning' : 'danger',
        'message' => ($uploaded > 0 ? $uploaded . ' dokumen tersimpan, tetapi: ' : '') . implode(' ', $errors),
    ];
} else {
    $_SESSION['flash'] = ['type' => 'success', 'message' => $uploaded . ' dokumen hasil UAT berhasil diunggah.'];
}

header('Location: ' . $back);
exit;
