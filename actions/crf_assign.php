<?php
/**
 * actions/crf_assign.php
 * Handler mengambil CRF pada tahap OTOMASI (op=take), atau Admin
 * menugaskan CRF ke handler kategori (op=assign).
 */
require_once __DIR__ . '/../includes/helpdesk.php';

// Handler mengambil CRF; Admin (atau demo) menugaskan ulang handler.
requireCrfRole(['otomasi', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../otomasi/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$actor = crfActorName($user);
$id = (int) ($_POST['id'] ?? 0);
$op = $_POST['op'] ?? 'take';
$redirect = ($_POST['return'] ?? '') === 'admin'
    ? '../admin/detail.php?id=' . $id
    : '../otomasi/detail.php?id=' . $id;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM change_requests WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $crf = $stmt->fetch();

    if (!$crf || $crf['workflow_stage'] !== 'OTOMASI' || (!isAdmin() && !canHandleCrf($pdo, $crf))) {
        throw new DomainException('CRF tidak tersedia untuk ditangani.');
    }

    if ($op === 'assign') {
        if (!isAdmin()) {
            throw new DomainException('Hanya Admin yang dapat menugaskan handler.');
        }
        if (isOwnHandledCrf($crf)) {
            throw new DomainException('Anda sedang memegang CRF ini. Penugasan ulang harus dilakukan Admin lain.');
        }
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if (!in_array($targetId, crfCategoryHandlerIds($pdo, (int) $crf['crf_category_id']), true)) {
            throw new DomainException('User bukan handler kategori CRF ini.');
        }
        $target = findCrfUserById($pdo, $targetId);
        if (!$target) {
            throw new DomainException('User tidak ditemukan.');
        }
        $activity = 'Handler Ditugaskan';
        $description = 'Admin menugaskan CRF kepada ' . crfActorName($target) . '.';
    } else {
        if (getCrfRole() === 'admin') {
            throw new DomainException('Admin tidak mengambil CRF. Gunakan "Tugaskan" untuk menunjuk handler.');
        }
        if (!empty($crf['assigned_handler_id'])) {
            throw new DomainException('CRF sudah diambil oleh ' . ($crf['assigned_handler_name'] ?? 'handler lain') . '.');
        }
        $target = $user;
        $activity = 'CRF Diambil Handler';
        $description = 'CRF diterima dan akan diproses oleh ' . $actor . '.';
    }

    $pdo->prepare('
        UPDATE change_requests
        SET assigned_handler_id = :handler_id,
            assigned_handler_name = :handler_name,
            assigned_at = NOW()
        WHERE id = :id
    ')->execute([
        'handler_id' => (int) $target['id'],
        'handler_name' => crfActorName($target),
        'id' => $id,
    ]);

    logCrfActivity($pdo, $id, $activity, $description, $actor);

    notifyUsers(
        $pdo,
        array_merge([(int) $crf['user_id']], $op === 'assign' ? [(int) $target['id']] : []),
        'CRF ' . $crf['request_number'] . ' ditangani ' . crfActorName($target),
        'CRF Anda sedang ditangani oleh ' . crfActorName($target) . '.',
        'crf/open.php?id=' . $id,
        $id,
        null,
        (int) $user['id']
    );

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $description];
} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('crf_assign error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat memproses penanganan CRF.'];
}

header('Location: ' . $redirect);
exit;
