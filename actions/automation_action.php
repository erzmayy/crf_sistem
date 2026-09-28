<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
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

$level = $_POST['level'] ?? '';
$slaValue = trim($_POST['sla_value'] ?? '');
$slaUnit = $_POST['sla_unit'] ?? '';
$implementation = trim($_POST['implementation'] ?? '');
$pir = trim($_POST['post_implementation_review'] ?? '');

if (!in_array($level, ['Tinggi','Normal','Rendah'], true)) {
    $level = '';
}
if (!in_array($slaUnit, ['Menit','Jam','Hari'], true)) {
    $slaUnit = '';
}

if ($id <= 0) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'CRF tidak valid.'];
    header('Location: ../otomasi/detail.php?id=' . $id);
    exit;
}

$stmt = $pdo->prepare("SELECT id, workflow_stage, automation_started_at, kadep_operasional_approved_at, level, sla_value, sla_unit FROM change_requests WHERE id = :id LIMIT 1");
$stmt->execute(['id'=>$id]);
$crf = $stmt->fetch();

if (!$crf || $crf['workflow_stage'] !== 'OTOMASI') {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak ditemukan pada tahap Otomasi.'
    ];

    header('Location: ../otomasi/index.php');
    exit;
}

$isExecutionStage = !empty($crf['kadep_operasional_approved_at']);
if ($isExecutionStage) {
    $level = $crf['level'] ?? '';
    $slaValue = (string) ($crf['sla_value'] ?? '');
    $slaUnit = $crf['sla_unit'] ?? '';
}

if (
    !in_array($level, ['Tinggi','Normal','Rendah'], true)
    || $slaValue === ''
    || !is_numeric($slaValue)
    || (float) $slaValue <= 0
    || !in_array($slaUnit, ['Menit','Jam','Hari'], true)
) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'Level Urgensi dan SLA wajib diisi dengan benar.'];
    header('Location: ../otomasi/detail.php?id=' . $id);
    exit;
}

if ($action === 'complete' && $isExecutionStage && ($implementation === '' || $pir === '')) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Implementasi dan Post Implementation Review wajib diisi sebelum eksekusi diselesaikan.'
    ];
    header('Location: ../otomasi/detail.php?id=' . $id);
    exit;
}

try {

    $pdo->beginTransaction();

    $now = date('Y-m-d H:i:s');

    $actor = !empty($user['nama'])
        ? $user['nama']
        : $user['userid'];


    /* =====================================================
     * MODE 1 — TENTUKAN SLA
     * ===================================================== */

    if (!$isExecutionStage) {

        $startedAt = $crf['automation_started_at'] ?: $now;

        $dueAt = slaDueAt(
            $startedAt,
            $slaValue,
            $slaUnit
        );


        if ($action === 'complete') {

            $stmt = $pdo->prepare("
                UPDATE change_requests
                SET
                    level = :level,
                    sla_value = :sla_value,
                    sla_unit = :sla_unit,
                    sla_started_at = :sla_started_at,
                    sla_due_at = :sla_due_at,
                    automation_started_at = :automation_started_at,
                    workflow_stage = 'PAK_JOKO',
                    status = 'Dalam Proses'
                WHERE id = :id
                  AND workflow_stage = 'OTOMASI'
                  AND pak_joko_approved_at IS NULL
            ");

            $stmt->execute([
                'level' => $level,
                'sla_value' => (float) $slaValue,
                'sla_unit' => $slaUnit,
                'sla_started_at' => $startedAt,
                'sla_due_at' => $dueAt,
                'automation_started_at' => $startedAt,
                'id' => $id,
            ]);


            logCrfActivity(
                $pdo,
                $id,
                'Otomasi - SLA Ditentukan',
                'Otomasi menentukan Level Urgensi dan SLA. CRF diteruskan ke Kepala Departemen Operasional untuk approval.',
                $actor
            );


            $message = 'Level Urgensi dan SLA berhasil ditentukan. CRF menunggu approval Kepala Departemen Operasional.';

        } else {

            $stmt = $pdo->prepare("
                UPDATE change_requests
                SET
                    level = :level,
                    sla_value = :sla_value,
                    sla_unit = :sla_unit,
                    sla_started_at = :sla_started_at,
                    sla_due_at = :sla_due_at,
                    automation_started_at = :automation_started_at
                WHERE id = :id
                  AND workflow_stage = 'OTOMASI'
                  AND pak_joko_approved_at IS NULL
            ");

            $stmt->execute([
                'level' => $level,
                'sla_value' => (float) $slaValue,
                'sla_unit' => $slaUnit,
                'sla_started_at' => $startedAt,
                'sla_due_at' => $dueAt,
                'automation_started_at' => $startedAt,
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
        }


        $pdo->commit();

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => $message
        ];


        if ($action === 'complete') {
            header('Location: ../pak_joko/index.php');
        } else {
            header('Location: ../otomasi/detail.php?id=' . $id);
        }

        exit;
    }


    /* =====================================================
     * MODE 2 — EKSEKUSI SETELAH APPROVAL
     * ===================================================== */

    if ($action === 'complete') {
        if (empty($crf['kadep_operasional_approved_at'])) {
            $stmt = $pdo->prepare("UPDATE change_requests SET level=:level, sla_value=:sla_value, sla_unit=:sla_unit, sla_started_at=:sla_started_at, sla_due_at=:sla_due_at, automation_started_at=:automation_started_at, workflow_stage='kadep_operasional', status='Dalam Proses' WHERE id=:id AND workflow_stage='OTOMASI' AND kadep_operasional_approved_at IS NULL");
            $stmt->execute([
                'level'=>$level,
                'sla_value'=>(float)$slaValue,
                'sla_unit'=>$slaUnit,
                'sla_started_at'=>$startedAt,
                'sla_due_at'=>$dueAt,
                'automation_started_at'=>$startedAt,
                'id'=>$id,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('CRF sudah tidak tersedia untuk approval.');
            }

            logCrfActivity($pdo, $id, 'SLA Ditetapkan', 'Otomasi menetapkan Level Urgensi dan SLA. CRF diteruskan ke Kepala Departemen Operasional untuk approval sebelum eksekusi.', $actor);
            $message = 'Level dan SLA berhasil dikirim ke Kepala Departemen Operasional untuk approval.';
        } else {
            $stmt = $pdo->prepare("UPDATE change_requests SET level=:level, sla_value=:sla_value, sla_unit=:sla_unit, sla_started_at=:sla_started_at, sla_due_at=:sla_due_at, automation_started_at=:automation_started_at, automation_completed_at=:automation_completed_at, implementation=:implementation, post_implementation_review=:pir, workflow_stage='CMO_FINAL', status='Dalam Proses' WHERE id=:id AND workflow_stage='OTOMASI' AND kadep_operasional_approved_at IS NOT NULL");
            $stmt->execute([
                'level'=>$level,
                'sla_value'=>(float)$slaValue,
                'sla_unit'=>$slaUnit,
                'sla_started_at'=>$startedAt,
                'sla_due_at'=>$dueAt,
                'automation_started_at'=>$startedAt,
                'automation_completed_at'=>$now,
                'implementation'=>$implementation,
                'pir'=>$pir,
                'id'=>$id,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('CRF sudah tidak tersedia untuk diselesaikan.');
            }

            logCrfActivity($pdo, $id, 'Otomasi Selesai', 'Otomasi menyelesaikan eksekusi dan mengisi Implementasi / Hasil Perubahan serta Post Implementation Review. CRF diteruskan ke CMO untuk finalisasi.', $actor);
            $message = 'Eksekusi, Implementasi, dan Post Implementation Review berhasil disimpan. CRF diteruskan ke CMO untuk finalisasi.';
        }
    } else {
        $stmt = $pdo->prepare("UPDATE change_requests SET level=:level, sla_value=:sla_value, sla_unit=:sla_unit, sla_started_at=:sla_started_at, sla_due_at=:sla_due_at, automation_started_at=:automation_started_at WHERE id=:id AND workflow_stage='OTOMASI'");
        $stmt->execute([
            'implementation' => $implementation,
            'post_implementation_review' => $pir,
            'automation_completed_at' => $now,
            'id' => $id,
        ]);

        logCrfActivity($pdo, $id, 'Proses Otomasi Diperbarui', 'Level Urgensi dan SLA diperbarui oleh Otomasi.', $actor);
        $message = 'Data proses Otomasi berhasil disimpan.';
    }


    throw new RuntimeException(
        'Aksi Otomasi tidak dikenal.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'automation_action error: '
        . $e->getMessage()
    );

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat memproses Otomasi.'
    ];

    header('Location: ../otomasi/detail.php?id=' . $id);
    exit;
}
