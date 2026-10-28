<?php
/**
 * actions/automation_start.php
 * PIC CRF menekan "Mulai kerjakan" pada CRF yang sudah disetujui Kepala
 * Departemen Operasional. Waktu ini menjadi waktu respon (SLA respon) dan
 * PIC tersebut tercatat sebagai pemegang CRF. SLA penyelesaian tidak berubah.
 */
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
$back = '../otomasi/detail.php?id=' . $id;

$finish = static function (string $type, string $message, string $location) use ($pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $location);
    exit;
};

if ($id <= 0) {
    $finish('danger', 'CRF tidak valid.', '../otomasi/index.php');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, nomor_register, kategori_id, pic_id, pic_nama, tahap_alur,
               tgl_disetujui_kadep, otomasi_selesai
        FROM crf_pengajuan
        WHERE id = :id
        FOR UPDATE
    ");
    $stmt->execute(['id' => $id]);
    $crf = $stmt->fetch();

    if (!$crf || $crf['tahap_alur'] !== 'OTOMASI' || empty($crf['tgl_disetujui_kadep']) || !empty($crf['otomasi_selesai'])) {
        $finish('danger', 'CRF tidak ditemukan pada daftar eksekusi.', '../otomasi/index.php');
    }

    if (!canHandleCrf($pdo, $crf)) {
        $finish('danger', 'CRF ini bukan kategori yang Anda tangani.', '../otomasi/index.php');
    }

    if (!empty($crf['pic_id'])) {
        $message = isAssignedCrfHandler($crf)
            ? 'Anda sudah mulai mengerjakan CRF ini.'
            : 'CRF ini sudah dikerjakan oleh ' . ($crf['pic_nama'] ?? 'PIC CRF lain') . '.';
        $finish('warning', $message, $back);
    }

    $now = date('Y-m-d H:i:s');
    $actor = crfActorName($user);
    $crfNumber = (string) ($crf['nomor_register'] ?: 'CRF #' . $id);

    $pdo->prepare('
        UPDATE crf_pengajuan
        SET pic_id = :pic_id, pic_nama = :pic_nama, tgl_ditugaskan = :now
        WHERE id = :id AND pic_id IS NULL
    ')->execute(['pic_id' => (int) $user['id'], 'pic_nama' => $actor, 'now' => $now, 'id' => $id]);

    recordCrfResponse($pdo, $id, $now);

    logCrfActivity(
        $pdo,
        $id,
        'PIC Mulai Mengerjakan',
        $actor . ' mulai mengerjakan CRF ' . $crfNumber . '. Response time tercatat.',
        $actor,
        'Disetujui · Eksekusi',
        'Disetujui · Eksekusi'
    );

    $pdo->commit();
} catch (Throwable $e) {
    error_log('automation_start: ' . $e->getMessage());
    $finish('danger', 'Gagal menyimpan. Silakan coba lagi.', $back);
}

$_SESSION['flash'] = ['type' => 'success', 'message' => 'Anda mulai mengerjakan CRF ini. Response time sudah tercatat.'];
header('Location: ' . $back);
exit;
