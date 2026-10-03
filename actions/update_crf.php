<?php
/**
 * actions/update_crf.php
 * ---------------------------------------------------------------
 * Menangani penyimpanan dari admin/edit.php:
 *   - Level Urgensi (Tinggi / Normal / Rendah)
 *   - Status
 *     (Belum Ditindak Lanjuti / Dalam Proses / Solve / Cancel)
 *   - Tanggapan / Tindak Lanjut
 *
 * Post Implementation Review dan Implementasi
 * TIDAK diubah melalui file ini.
 * Kedua field tersebut diisi oleh user melalui detail pengajuan.
 *
 * Aturan solved_at / cancelled_at:
 *   - Saat status berubah menjadi Solve -> solved_at diisi waktu saat itu
 *     kalau belum pernah diisi.
 *   - Saat status berubah menjadi Cancel -> cancelled_at diisi waktu saat itu
 *     kalau belum pernah diisi.
 *   - Saat status menjadi Belum Ditindak Lanjuti atau Dalam Proses
 *     -> solved_at dan cancelled_at dikosongkan.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpdesk.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: ../admin/dashboard.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$admin = getCurrentUser();

$id = (int) ($_POST['id'] ?? 0);

$levelRaw = $_POST['level'] ?? '';

$statusRaw = $_POST['status'] ?? '';

$tanggapan = trim(
    $_POST['tanggapan_tindak_lanjut'] ?? ''
);


$allowedLevels = [
    'Tinggi',
    'Normal',
    'Rendah'
];

$allowedStatuses = [
    'Belum Ditindak Lanjuti',
    'Perlu Revisi',
    'Dalam Proses',
    'Solve',
    'Cancel'
];


$level = in_array(
    $levelRaw,
    $allowedLevels,
    true
)
    ? $levelRaw
    : null;


$status = in_array(
    $statusRaw,
    $allowedStatuses,
    true
)
    ? $statusRaw
    : null;

    if (
        $status === 'Perlu Revisi'
        && $tanggapan === ''
    ) {

        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Tanggapan / Tindak Lanjut wajib diisi jika status Perlu Revisi.'
        ];

        header(
            'Location: ../admin/edit.php?id='
            . $id
        );

        exit;
    }

    if (
        $status === 'Cancel'
        && $tanggapan === ''
    ) {

        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Tanggapan / Tindak Lanjut wajib diisi jika status Dibatalkan.'
        ];

        header(
            'Location: ../admin/edit.php?id='
            . $id
        );

        exit;
    }


if ($id <= 0 || $status === null) {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Data tidak valid.'
    ];

    header('Location: ../admin/dashboard.php');
    exit;
}


/*
 * Ambil status dan timestamp sebelumnya.
 */
$stmt = $pdo->prepare(
    'SELECT
        user_id,
        status,
        level,
        tanggapan_tindak_lanjut,
        approval_at,
        solved_at,
        cancelled_at
     FROM change_requests
     WHERE id = :id
            AND status <> \'Draft\'
     LIMIT 1'
);

$stmt->execute([
    'id' => $id
]);

$current = $stmt->fetch();

if (!$current) {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Pengajuan CRF tidak ditemukan.'
    ];

    header('Location: ../admin/dashboard.php');
    exit;
}

$currentStatus = $current['status'];

/*
 * Tolak perpindahan status yang tidak diperbolehkan.
 */
if (!canChangeStatus($currentStatus, $status)) {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' =>
            'Perubahan status dari "'
            . statusLabel($currentStatus)
            . '" ke "'
            . statusLabel($status)
            . '" tidak diperbolehkan.'
    ];

    header('Location: ../admin/edit.php?id=' . $id);
    exit;
}

/*
 * Pertahankan timestamp yang sudah ada.
 */
$approvalAt = $current['approval_at'] ?? null;

$solvedAt = $current['solved_at'] ?? null;

$cancelledAt = $current['cancelled_at'] ?? null;


/*
 * Belum Ditindak Lanjuti -> Dalam Proses
 * dianggap sebagai waktu approval / mulai proses.
 */
if (
    ($current['status'] ?? '') === 'Belum Ditindak Lanjuti'
    &&
    $statusRaw === 'Dalam Proses'
) {

    $approvalAt = $approvalAt ?: date('Y-m-d H:i:s');
}


/*
 * Status Solve.
 */
if ($statusRaw === 'Solve') {

    $solvedAt = $solvedAt ?: date('Y-m-d H:i:s');

    $cancelledAt = null;


/*
 * Status Cancel.
 */
} elseif ($statusRaw === 'Cancel') {

    $cancelledAt = $cancelledAt ?: date('Y-m-d H:i:s');

    $solvedAt = null;


/*
 * Status aktif / belum selesai.
 */
} elseif (
    $statusRaw === 'Dalam Proses'
    ||
    $statusRaw === 'Belum Ditindak Lanjuti'
    ||
    $statusRaw === 'Perlu Revisi'
) {

    $solvedAt = null;

    $cancelledAt = null;
}


try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'UPDATE change_requests
         SET
            level = :level,
            status = :status,
            tanggapan_tindak_lanjut = :tanggapan,
            approval_at = :approval_at,
            solved_at = :solved_at,
            cancelled_at = :cancelled_at
         WHERE id = :id'
    );


    $stmt->execute([
        'level' => $level,

        'status' => $status,

        'tanggapan' =>
            $tanggapan !== ''
                ? $tanggapan
                : null,

        'approval_at' => $approvalAt,

        'solved_at' => $solvedAt,

        'cancelled_at' => $cancelledAt,

        'id' => $id,
    ]);

     /*
     * ---------------------------------------------------------------
     * Catat perubahan ke timeline: status, Level Complain, dan
     * Tanggapan / Tindak Lanjut masing-masing dicatat sebagai entri
     * terpisah, supaya riwayatnya lengkap.
     * ---------------------------------------------------------------
     */
    $actor = !empty($admin['nama'])
        ? $admin['nama']
        : $admin['userid'];

    $logStmt = $pdo->prepare("
        INSERT INTO crf_activity_logs (
            change_request_id,
            activity,
            description,
            actor
        ) VALUES (
            :change_request_id,
            :activity,
            :description,
            :actor
        )
    ");

    if ($currentStatus !== $status) {

        $description = null;

        if ($status === 'Dalam Proses') {
            $description = 'Pengajuan sedang diproses oleh admin.';
        } elseif ($status === 'Perlu Revisi') {
            $description = $tanggapan;
        } elseif ($status === 'Solve') {
            $description = 'Pengajuan telah selesai diproses.';
        } elseif ($status === 'Cancel') {
            $description = 'Pengajuan dibatalkan.';
        }

        $logStmt->execute([
            'change_request_id' => $id,
            'activity'          => $status,
            'description'       => $description,
            'actor'             => $actor,
        ]);
    }

    $oldLevel     = $current['level'] ?? null;
    $oldTanggapan = $current['tanggapan_tindak_lanjut'] ?? null;

    if ($oldLevel !== $level) {
        $logStmt->execute([
            'change_request_id' => $id,
            'activity'          => 'Ubah Level Complain',
            'description'       => 'Level Complain diubah menjadi "'
                . ($level ?? 'Belum ditentukan') . '".',
            'actor'             => $actor,
        ]);
    }

    // Kalau status ikut berubah ke Perlu Revisi/Cancel, tanggapannya
    // sudah tercatat di log status di atas, jadi tidak perlu dobel.
    if (
        $currentStatus === $status
        && $oldTanggapan !== $tanggapan
        && $tanggapan !== ''
    ) {
        $logStmt->execute([
            'change_request_id' => $id,
            'activity'          => 'Ubah Tanggapan',
            'description'       => $tanggapan,
            'actor'             => $actor,
        ]);
    }

    // Status ticket Helpdesk terkait mengikuti perubahan status CRF.
    syncHelpdeskTicketFromCrf($pdo, $id, $actor);

    if ($currentStatus !== $status && in_array($status, ['Perlu Revisi', 'Solve', 'Cancel'], true)) {
        notifyUsers(
            $pdo,
            [(int) ($current['user_id'] ?? 0)],
            'Status CRF diperbarui: ' . statusLabel($status),
            'Admin mengubah status CRF Anda menjadi ' . statusLabel($status) . '.',
            'crf/open.php?id=' . $id,
            $id,
            null,
            (int) $_SESSION['user_id']
        );
    }

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);

    $_SESSION['flash'] = [
        'type' => 'success',

        'message' =>
            'Perubahan berhasil disimpan. Status saat ini: '
            . statusLabel($status)
            . '.',
    ];

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'update_crf error: '
        . $e->getMessage()
    );


    $_SESSION['flash'] = [
        'type' => 'danger',

        'message' =>
            'Terjadi kesalahan saat menyimpan perubahan. Silakan coba lagi.',
    ];
}


header(
    'Location: ../admin/detail.php?id='
    . $id
);

exit;