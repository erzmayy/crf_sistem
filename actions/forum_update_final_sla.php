<?php
require_once __DIR__ . '/../includes/forum.php';
require_once __DIR__ . '/../includes/functions.php';
requireForumAccess();

// requireCrfRole() selalu meloloskan akun demo, jadi hak Admin dicek eksplisit di sini.
if (!canManageForumFinalSla()) {
    $crfId = filter_input(INPUT_POST, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Hanya Admin yang dapat mengubah Level Urgensi dan SLA final di Forum.',
    ];
    header('Location: ../forum/index.php' . ($crfId > 0 ? '?crf_id=' . $crfId : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrf();

$crfId = filter_input(INPUT_POST, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$finalUrgency = $_POST['final_urgency_level'] ?? '';
$slaValueInput = $_POST['sla_value'] ?? '';
$slaUnit = $_POST['sla_unit'] ?? '';
$redirect = '../forum/index.php?crf_id=' . max(0, $crfId);

if (
    $crfId <= 0
    || !is_string($finalUrgency)
    || !in_array($finalUrgency, ['Tinggi', 'Normal', 'Rendah'], true)
    || !is_scalar($slaValueInput)
    || !is_numeric($slaValueInput)
    || !is_finite((float) $slaValueInput)
    || (float) $slaValueInput < 0.01
    || (float) $slaValueInput > 99999999.99
    || !is_string($slaUnit)
    || !in_array($slaUnit, ['Menit', 'Jam', 'Hari'], true)
) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Level urgensi dan nilai SLA final wajib diisi dengan benar.',
    ];
    header('Location: ' . $redirect);
    exit;
}

$pdo = getConnection();
$user = getCurrentUser();
$actor = trim((string) ($user['nama'] ?? '')) ?: (string) ($user['userid'] ?? 'User');
$slaValue = round((float) $slaValueInput, 2);

try {
    $pdo->beginTransaction();

    $crfStmt = $pdo->prepare(
        'SELECT id, request_number, level, impact_category,
                final_urgency_level, sla_value, sla_unit,
                sla_started_at, status, workflow_stage,
                kadep_operasional_approved_at, assigned_handler_id
         FROM change_requests cr
         WHERE cr.id = :id
           AND ' . forumActiveCrfCondition('cr') . '
         FOR UPDATE'
    );
    $crfStmt->execute(['id' => $crfId]);
    $crf = $crfStmt->fetch();

    if (!$crf) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'CRF tidak ditemukan atau sudah tidak dalam proses.',
        ];
        header('Location: ../forum/index.php');
        exit;
    }

    if (isForumFinalSlaLocked($crf)) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Urgensi dan SLA final tidak dapat diubah karena CRF sudah disetujui Kepala Departemen Operasional dan masuk tahap eksekusi.',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    $systemUrgency = crfUrgencyForImpact($crf['impact_category'] ?? null)
        ?: ($crf['level'] ?? null)
        ?: 'Belum ditentukan';
    $oldFinalUrgency = $crf['final_urgency_level'] ?: $systemUrgency;
    $oldSla = $crf['sla_value'] !== null
        ? rtrim(rtrim(number_format((float) $crf['sla_value'], 2, '.', ''), '0'), '.')
            . ' ' . ($crf['sla_unit'] ?? '')
        : 'Belum ditentukan';
    $newSla = rtrim(rtrim(number_format($slaValue, 2, '.', ''), '0'), '.') . ' ' . $slaUnit;
    $slaDueAt = !empty($crf['sla_started_at'])
        ? slaDueAt($crf['sla_started_at'], $slaValue, $slaUnit)
        : null;

    if (!empty($crf['sla_started_at']) && $slaDueAt === null) {
        throw new RuntimeException('Tenggat SLA tidak dapat dihitung dari data yang tersimpan.');
    }

    $updateStmt = $pdo->prepare(
        'UPDATE change_requests
         SET final_urgency_level = :final_urgency_level,
             sla_value = :sla_value,
             sla_unit = :sla_unit,
             sla_due_at = :sla_due_at
         WHERE id = :id'
    );
    $updateStmt->execute([
        'final_urgency_level' => $finalUrgency,
        'sla_value' => $slaValue,
        'sla_unit' => $slaUnit,
        'sla_due_at' => $slaDueAt,
        'id' => $crfId,
    ]);

    $description = sprintf(
        forumSystemCommentPrefix() . '%s. Urgensi final: %s → %s. SLA final: %s → %s.',
        crfRoleLabel(getCrfRole()),
        $oldFinalUrgency,
        $finalUrgency,
        $oldSla,
        $newSla
    );
    logCrfActivity($pdo, $crfId, 'Kesepakatan Urgensi dan SLA Forum', $description, $actor);

    $commentStmt = $pdo->prepare(
        'INSERT INTO forum_comments
            (change_request_id, user_id, user_name, user_role, comment)
         VALUES
            (:change_request_id, :user_id, :user_name, :user_role, :comment)'
    );
    $commentStmt->execute([
        'change_request_id' => $crfId,
        'user_id' => (int) $user['id'],
        'user_name' => $actor,
        'user_role' => getCrfRole(),
        'comment' => $description,
    ]);
    $commentId = (int) $pdo->lastInsertId();
    $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;

    notifyForumUsers(
        $pdo,
        forumParticipantIds($pdo, $crfId, (int) $crf['assigned_handler_id'] ?: null),
        'Kesepakatan SLA Forum: ' . $crfNumber,
        'Urgensi final ' . $finalUrgency . ' dan SLA final ' . $newSla . ' ditetapkan untuk ' . $crfNumber . ' oleh ' . $actor . '.',
        $crfId,
        $commentId,
        (int) $user['id']
    );

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $redirect .= '#comment-' . $commentId;
    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => 'Urgensi dan SLA final hasil kesepakatan Forum berhasil disimpan.',
    ];
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Forum final SLA update failed: ' . $exception->getMessage());
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Kesepakatan urgensi dan SLA gagal disimpan. Silakan coba lagi.',
    ];
}

header('Location: ' . $redirect);
exit;
