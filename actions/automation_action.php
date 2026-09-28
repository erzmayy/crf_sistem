<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

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

if (!in_array($level, ['Tinggi','Normal','Rendah'], true)) $level = '';
if (!in_array($slaUnit, ['Menit','Jam','Hari'], true)) $slaUnit = '';

if ($id <= 0 || $level === '' || $slaValue === '' || !is_numeric($slaValue) || (float)$slaValue <= 0 || $slaUnit === '') {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'Level Urgensi dan SLA wajib diisi dengan benar.'];
    header('Location: ../otomasi/detail.php?id=' . $id);
    exit;
}

$stmt = $pdo->prepare("SELECT id, workflow_stage, automation_started_at FROM change_requests WHERE id = :id LIMIT 1");
$stmt->execute(['id'=>$id]);
$crf = $stmt->fetch();
if (!$crf || $crf['workflow_stage'] !== 'OTOMASI') {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'CRF tidak ditemukan pada tahap Otomasi.'];
    header('Location: ../otomasi/index.php');
    exit;
}

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');
    $startedAt = $crf['automation_started_at'] ?: $now;
    $dueAt = slaDueAt($startedAt, $slaValue, $slaUnit);
    $actor = !empty($user['nama']) ? $user['nama'] : $user['userid'];

    if ($action === 'complete') {
       $stmt = $pdo->prepare("UPDATE change_requests SET level=:level, sla_value=:sla_value, sla_unit=:sla_unit, sla_started_at=:sla_started_at, sla_due_at=:sla_due_at, automation_started_at=:automation_started_at, automation_completed_at=:automation_completed_at, workflow_stage='PEMOHON_PIR', status='Dalam Proses' WHERE id=:id AND workflow_stage='OTOMASI'");
        $stmt->execute([
            'level'=>$level,
            'sla_value'=>(float)$slaValue,
            'sla_unit'=>$slaUnit,
            'sla_started_at'=>$startedAt,
            'sla_due_at'=>$dueAt,
            'automation_started_at'=>$startedAt,
            'automation_completed_at'=>$now,
            'id'=>$id,
        ]);
        logCrfActivity($pdo, $id, 'Otomasi Selesai', 'Otomasi selesai menangani permintaan. Pemohon diminta mengisi Post Implementation Review.', $actor);
        $message = 'CRF selesai ditangani Otomasi dan diteruskan ke Pemohon untuk PIR.';
    } else {
        $stmt = $pdo->prepare("UPDATE change_requests SET level=:level, sla_value=:sla_value, sla_unit=:sla_unit, sla_started_at=:sla_started_at, sla_due_at=:sla_due_at, automation_started_at=:automation_started_at WHERE id=:id AND workflow_stage='OTOMASI'");
        $stmt->execute([
            'level'=>$level,
            'sla_value'=>(float)$slaValue,
            'sla_unit'=>$slaUnit,
            'sla_started_at'=>$startedAt,
            'sla_due_at'=>$dueAt,
            'automation_started_at'=>$startedAt,
            'id'=>$id,
        ]);
        logCrfActivity($pdo, $id, 'Proses Otomasi Diperbarui', 'Level Urgensi dan SLA diperbarui oleh Otomasi.', $actor);
        $message = 'Data proses Otomasi berhasil disimpan.';
    }

    $pdo->commit();
    $_SESSION['flash'] = ['type'=>'success','message'=>$message];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('automation_action error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type'=>'danger','message'=>'Terjadi kesalahan saat menyimpan proses Otomasi.'];
}

if ($action === 'complete') {
    header('Location: ../otomasi/index.php');
} else {
    header('Location: ../otomasi/detail.php?id=' . $id);
}
exit;