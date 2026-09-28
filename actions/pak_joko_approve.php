<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireCrfRole(['pak_joko']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pak_joko/index.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();

$id = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'approve';
$note = trim($_POST['approval_note'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak valid.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, workflow_stage
    FROM change_requests
    WHERE id = :id
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

if (!$crf || $crf['workflow_stage'] !== 'pak_joko') {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak tersedia untuk approval.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}


if ($action === 'revision' && $note === '') {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Catatan revisi wajib diisi.'
    ];

    header('Location: ../pak_joko/detail.php?id=' . $id);
    exit;
}


try {

    $pdo->beginTransaction();

    $now = date('Y-m-d H:i:s');

    $actor = !empty($user['nama'])
        ? $user['nama']
        : $user['userid'];


    /* =====================================================
     * PERLU REVISI
     * ===================================================== */

    if ($action === 'revision') {

        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                pak_joko_approved_by = NULL,
                pak_joko_approved_at = NULL,
                pak_joko_approval_note = :approval_note,
                workflow_stage = 'OTOMASI',
                status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'PAK_JOKO'
        ");

        $stmt->execute([
            'approval_note' => $note,
            'id' => $id,
        ]);


        logCrfActivity(
            $pdo,
            $id,
            'Perlu Revisi',
            'Kepala Departemen Operasional mengembalikan CRF ke Otomasi untuk revisi sebelum approval. Catatan: ' . $note,
            $actor
        );


        $pdo->commit();


        $_SESSION['flash'] = [
            'type' => 'warning',
            'message' => 'CRF berhasil dikembalikan ke Otomasi untuk revisi.'
        ];


        header('Location: ../pak_joko/index.php');
        exit;
    }


    /* =====================================================
     * APPROVE
     * Kembali ke Otomasi untuk eksekusi
     * ===================================================== */

    if ($action === 'approve') {

        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                pak_joko_approved_by = :approved_by,
                pak_joko_approved_at = :approved_at,
                pak_joko_approval_note = :approval_note,
                workflow_stage = 'OTOMASI',
                status = 'Dalam Proses'
            WHERE id = :id
              AND workflow_stage = 'PAK_JOKO'
        ");

        $stmt->execute([
            'approved_by' => $user['id'],
            'approved_at' => $now,
            'approval_note' => $note !== ''
                ? $note
                : null,
            'id' => $id,
        ]);


        logCrfActivity(
            $pdo,
            $id,
            'Approval Kepala Departemen Operasional',
            $note !== ''
                ? 'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. CRF diteruskan ke Otomasi untuk eksekusi. Catatan: ' . $note
                : 'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. CRF diteruskan ke Otomasi untuk eksekusi.',
            $actor
        );


        $pdo->commit();


        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'CRF berhasil di-approve dan diteruskan ke Otomasi untuk eksekusi.'
        ];


        header('Location: ../pak_joko/index.php');
        exit;
    }


    throw new RuntimeException(
        'Aksi approval tidak dikenal.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'pak_joko_approve error: '
        . $e->getMessage()
    );

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat memproses approval.'
    ];

    header('Location: ../pak_joko/index.php');
    exit;
}