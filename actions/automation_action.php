<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';

requireCrfRole(['otomasi']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../otomasi/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$id = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'save';
$submittedLevel = $_POST['level'] ?? '';
$slaValue = trim($_POST['sla_value'] ?? '');
$slaUnit = $_POST['sla_unit'] ?? '';
$slaReason = trim($_POST['sla_reason'] ?? '');
$implementation = trim($_POST['implementation'] ?? '');
$implementationDate = trim($_POST['implementation_date'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF tidak valid.'];
    header('Location: ../otomasi/index.php');
    exit;
}

if (!in_array($action, ['save', 'complete'], true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Aksi Otomasi tidak dikenal.'];
    header('Location: ../otomasi/index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            request_number,
            crf_category_id,
            assigned_handler_id,
            assigned_handler_name,
            workflow_stage,
            automation_started_at,
            kadep_operasional_approved_at,
            level,
            impact_category,
            final_urgency_level,
            sla_value,
            sla_unit
        FROM change_requests
        WHERE id = :id
        FOR UPDATE
    ");
    $stmt->execute(['id' => $id]);
    $crf = $stmt->fetch();

    if (!$crf || $crf['workflow_stage'] !== 'OTOMASI') {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'CRF tidak ditemukan pada tahap Otomasi.'
        ];
        header('Location: ../otomasi/index.php');
        exit;
    }

    /*
     * Hanya handler kategori CRF ini. Jika CRF belum diambil, handler
     * yang memproses pertama kali otomatis menjadi pemegang CRF.
     */
    if (!canHandleCrf($pdo, $crf)) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CRF ini bukan kategori yang Anda tangani.'];
        header('Location: ../otomasi/index.php');
        exit;
    }

    if (!empty($crf['assigned_handler_id']) && !isAssignedCrfHandler($crf)) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'CRF ini sedang ditangani oleh ' . ($crf['assigned_handler_name'] ?? 'handler lain') . '.',
        ];
        header('Location: ../otomasi/detail.php?id=' . $id);
        exit;
    }

    if (empty($crf['assigned_handler_id'])) {
        $pdo->prepare('
            UPDATE change_requests
            SET assigned_handler_id = :handler_id, assigned_handler_name = :handler_name, assigned_at = NOW()
            WHERE id = :id
        ')->execute([
            'handler_id' => (int) $user['id'],
            'handler_name' => crfActorName($user),
            'id' => $id,
        ]);
        logCrfActivity($pdo, $id, 'CRF Diambil Handler', 'CRF diterima dan diproses oleh ' . crfActorName($user) . '.', crfActorName($user));
    }

    $level = $crf['level']
        ?: crfUrgencyForImpact($crf['impact_category'] ?? null)
        ?: $submittedLevel;
    $isExecutionStage = !empty($crf['kadep_operasional_approved_at']);
    $isSlaLocked = $isExecutionStage || !empty($crf['final_urgency_level']);
    if ($isSlaLocked) {
        $slaValue = (string) ($crf['sla_value'] ?? '');
        $slaUnit = $crf['sla_unit'] ?? '';
    }

    /*
     * SLA standar dari matriks Kategori x Urgensi. Handler boleh
     * menyimpang dari standar asalkan menuliskan alasannya.
     */
    $standardSla = $isSlaLocked ? null : crfStandardSla($pdo, (int) ($crf['crf_category_id'] ?? 0), $level);
    $slaDeviates = $standardSla !== null && !crfSlaEquals($standardSla, $slaValue, $slaUnit);

    if ($slaDeviates && ($slaReason === '' || mb_strlen($slaReason) > 500)) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'SLA berbeda dari standar kategori (' . slaLabel($standardSla['value'], $standardSla['unit'])
                . '). Alasan perubahan SLA wajib diisi (maks. 500 karakter).',
        ];
        header('Location: ../otomasi/detail.php?id=' . $id);
        exit;
    }

    $slaChanged = !crfSlaEquals(
        $crf['sla_value'] !== null ? ['value' => (float) $crf['sla_value'], 'unit' => (string) $crf['sla_unit']] : null,
        $slaValue,
        $slaUnit
    );
    if ($slaDeviates && ($slaChanged || $action === 'complete')) {
        logCrfActivity(
            $pdo,
            $id,
            'SLA Disesuaikan Handler',
            'SLA ' . slaLabel($slaValue, $slaUnit) . ' (standar kategori ' . slaLabel($standardSla['value'], $standardSla['unit'])
                . '). Alasan: ' . $slaReason,
            crfActorName($user)
        );
    }

    if (
        !in_array($level, ['Tinggi', 'Normal', 'Rendah'], true)
        || $slaValue === ''
        || !is_numeric($slaValue)
        || (float) $slaValue <= 0
        || !in_array($slaUnit, ['Menit', 'Jam', 'Hari'], true)
    ) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Level Urgensi dan SLA wajib diisi dengan benar.'
        ];
        header('Location: ../otomasi/detail.php?id=' . $id);
        exit;
    }

    $parsedImplementationDate = DateTime::createFromFormat('!Y-m-d', $implementationDate);
    $implementationDateErrors = DateTime::getLastErrors();
    $isValidImplementationDate = $parsedImplementationDate !== false
        && $parsedImplementationDate->format('Y-m-d') === $implementationDate
        && (
            $implementationDateErrors === false
            || (
                $implementationDateErrors['warning_count'] === 0
                && $implementationDateErrors['error_count'] === 0
            )
        );
    // PIR tidak lagi diisi Otomasi; Pemohon mengisinya setelah implementasi.
    $hasMissingExecutionDetails = !$isValidImplementationDate
        || $implementation === '';

    if (
        $action === 'complete'
        && $isExecutionStage
        && $hasMissingExecutionDetails
    ) {
        $pdo->rollBack();
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Tanggal Implementasi dan Implementasi / Hasil Perubahan wajib diisi dengan benar sebelum eksekusi diselesaikan.'
        ];
        header('Location: ../otomasi/detail.php?id=' . $id);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $actor = !empty($user['nama']) ? $user['nama'] : $user['userid'];

    if ($action === 'save') {
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                level = :level,
                sla_value = :sla_value,
                sla_unit = :sla_unit,
                sla_started_at = NULL,
                sla_due_at = NULL
            WHERE id = :id
              AND workflow_stage = 'OTOMASI'
        ");
        $stmt->execute([
            'level' => $level,
            'sla_value' => (float) $slaValue,
            'sla_unit' => $slaUnit,
            'id' => $id,
        ]);

        logCrfActivity(
            $pdo,
            $id,
            'Proses Otomasi Diperbarui',
            'Level Urgensi dan SLA diperbarui oleh Otomasi.',
            $actor
        );
        $message = 'Data Level Urgensi dan SLA berhasil disimpan.';
        $redirect = '../otomasi/detail.php?id=' . $id;
    } elseif (!$isExecutionStage) {
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                level = :level,
                sla_value = :sla_value,
                sla_unit = :sla_unit,
                sla_started_at = NULL,
                sla_due_at = NULL,
                automation_started_at = NULL,
                workflow_stage = 'kadep_operasional',
                status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'OTOMASI'
              AND kadep_operasional_approved_at IS NULL
        ");
        $stmt->execute([
            'level' => $level,
            'sla_value' => (float) $slaValue,
            'sla_unit' => $slaUnit,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk approval.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Otomasi - SLA Ditentukan',
            'Otomasi menentukan Level Urgensi dan SLA. CRF diteruskan ke Kepala Departemen Operasional untuk approval.',
            $actor,
            'Diproses',
            'Menunggu Approval'
        );
        notifyUsers(
            $pdo,
            crfUserIdsForRole($pdo, 'kadep_operasional'),
            'Approval CRF: ' . $crf['request_number'],
            'CRF ' . $crf['request_number'] . ' menunggu approval Anda (SLA ' . slaLabel($slaValue, $slaUnit) . ').',
            'crf/open.php?id=' . $id,
            $id,
            null,
            (int) $user['id']
        );
        $message = 'Level Urgensi dan SLA berhasil ditentukan. CRF menunggu approval Kepala Departemen Operasional.';
        $redirect = isDemoUser() ? '../pak_joko/index.php' : '../otomasi/index.php';
    } else {
        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                automation_completed_at = :automation_completed_at,
                implementation_date = :implementation_date,
                implementation = :implementation,
                workflow_stage = 'PEMOHON_PIR',
                status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'OTOMASI'
              AND kadep_operasional_approved_at IS NOT NULL
        ");
        $stmt->execute([
            'automation_completed_at' => $now,
            'implementation_date' => $parsedImplementationDate->format('Y-m-d'),
            'implementation' => $implementation,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk diselesaikan.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Otomasi Selesai',
            'Otomasi menyelesaikan eksekusi dan mencatat Tanggal Implementasi serta Implementasi / Hasil Perubahan. CRF diteruskan ke Pemohon untuk Post Implementation Review.',
            $actor,
            'Disetujui · Eksekusi',
            'Menunggu PIR Pemohon'
        );
        finalizeCrfSla($pdo, $id);
        notifyUsers(
            $pdo,
            [(int) $crf['user_id']],
            'Isi PIR CRF: ' . $crf['request_number'],
            'Implementasi CRF ' . $crf['request_number'] . ' sudah selesai. Silakan isi Post Implementation Review.',
            'crf/open.php?id=' . $id,
            $id,
            null,
            (int) $user['id']
        );
        $message = 'Hasil implementasi berhasil disimpan. CRF diteruskan ke Pemohon untuk mengisi Post Implementation Review.';
        $redirect = '../otomasi/index.php?queue=history';
    }

    syncHelpdeskTicketFromCrf($pdo, $id, $actor);

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('automation_action error: ' . $e->getMessage());
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat memproses Otomasi.'
    ];
    header('Location: ../otomasi/detail.php?id=' . $id);
    exit;
}
