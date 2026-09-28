<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../cmo/index.php');
    exit;
}
verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$tanggapan = trim($_POST['tanggapan'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak valid.'];
    header('Location: ../cmo/index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, status, workflow_stage FROM change_requests WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$crf = $stmt->fetch();

if (!$crf) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak ditemukan.'];
    header('Location: ../cmo/index.php');
    exit;
}

$allowed = [
    'to_automation' => 'CMO_FILTER',
    'revision' => 'CMO_FILTER',
    'cancel' => ['CMO_FILTER','CMO_FINAL'],
    'complete' => 'CMO_FINAL',
];

$expected = $allowed[$action] ?? null;
if ($expected === null || (is_array($expected) ? !in_array($crf['workflow_stage'], $expected, true) : $crf['workflow_stage'] !== $expected)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Aksi tidak tersedia pada tahap CRF saat ini.'];
    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

if (in_array($action, ['revision','cancel'], true) && $tanggapan === '') {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Tanggapan / Tindak Lanjut wajib diisi untuk aksi ini.'];
    header('Location: ../cmo/detail.php?id=' . $id);
    exit;
}

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');
    $actor = !empty($user['nama']) ? $user['nama'] : $user['userid'];

    if ($action === 'to_automation') {
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                status = 'Dalam Proses',
                workflow_stage = 'OTOMASI',
                automation_started_at = NULL,
                sla_started_at = NULL,
                sla_due_at = NULL
            WHERE id = :id
            AND workflow_stage = 'CMO_FILTER'
        ");

        $stmt->execute([
            'id' => $id
        ]);

        logCrfActivity(
            $pdo,
            $id,
            'Lolos Filter CMO',
            'CRF lolos filter CMO dan diteruskan ke Otomasi.',
            $actor
        );

        $message = 'CRF berhasil diteruskan ke Otomasi.';
    } elseif ($action === 'revision') {
        $stmt = $pdo->prepare("UPDATE change_requests SET status = 'Perlu Revisi', workflow_stage = 'PEMOHON', tanggapan_tindak_lanjut = :tanggapan WHERE id = :id AND workflow_stage = 'CMO_FILTER'");
        $stmt->execute(['tanggapan' => $tanggapan, 'id' => $id]);
        logCrfActivity($pdo, $id, 'Perlu Revisi', $tanggapan, $actor);
        $message = 'CRF dikembalikan ke Pemohon untuk revisi.';
    } elseif ($action === 'cancel') {
        $stmt = $pdo->prepare("UPDATE change_requests SET status = 'Cancel', workflow_stage = 'SELESAI', tanggapan_tindak_lanjut = :tanggapan, cancelled_at = :now, solved_at = NULL WHERE id = :id");
        $stmt->execute(['tanggapan' => $tanggapan, 'now' => $now, 'id' => $id]);
        logCrfActivity($pdo, $id, 'Cancel', $tanggapan, $actor);
        $message = 'CRF berhasil dibatalkan.';
    } else {
        $stmt = $pdo->prepare("UPDATE change_requests SET status = 'Solve', workflow_stage = 'SELESAI', solved_at = :now, cancelled_at = NULL WHERE id = :id AND workflow_stage = 'CMO_FINAL'");
        $stmt->execute(['now' => $now, 'id' => $id]);
        logCrfActivity($pdo, $id, 'Solve', 'CMO menyelesaikan dan menutup CRF setelah approval Kepala Departemen Operasional.', $actor);
        $message = 'CRF berhasil ditandai selesai.';
    }

    $pdo->commit();
    $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('cmo_action error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat memproses CRF.'];
}

if ($action === 'to_automation') {
    header('Location: ../otomasi/index.php');
} else {
    header('Location: ../cmo/index.php');
}
exit;