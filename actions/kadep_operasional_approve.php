<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forum_discussions.php';

requireCrfRole(['kadep_operasional']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crfRedirect(crfUrl('kadep.index'));
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();

$id = (int) ($_POST['id'] ?? 0);
// Kepala Departemen Operasional hanya menyetujui (tidak ada jalur revisi).
$action = 'approve';
$note = trim($_POST['approval_note'] ?? '');

if ($id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak valid.'
    ];

    crfRedirect(crfUrl('kadep.index'));
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, pengguna_id, nomor_register, pic_id, kategori_id, tahap_alur
    FROM crf_pengajuan
    WHERE id = :id
    LIMIT 1
");

$stmt->execute(['id' => $id]);

$crf = $stmt->fetch();

if (!$crf || $crf['tahap_alur'] !== 'kadep_operasional') {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'CRF tidak tersedia untuk persetujuan.'
    ];

    crfRedirect(crfUrl('kadep.index'));
    exit;
}


if ($note === '') {

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Catatan persetujuan wajib diisi.'
    ];

    crfRedirect(crfUrl('kadep.detail', ['id' => $id]));
    exit;
}


try {

    $pdo->beginTransaction();

    $now = date('Y-m-d H:i:s');

    $actor = crfActorName($user);
    $crfLink = 'crf/open.php?id=' . $id;
    $crfNumber = (string) $crf['nomor_register'];

    // Handler pemegang CRF; bila belum ada, seluruh handler kategori.
    $handlerRecipients = !empty($crf['pic_id'])
        ? [(int) $crf['pic_id']]
        : (!empty($crf['kategori_id']) ? crfCategoryHandlerIds($pdo, (int) $crf['kategori_id']) : []);


    /* =====================================================
     * APPROVE
     * Kembali ke Otomasi untuk eksekusi
     * ===================================================== */

    if ($action === 'approve') {

        $stmt = $pdo->prepare("
            SELECT
                id,
                sla_nilai,
                sla_satuan,
                kategori_id,
                level,
                level_urgensi_final,
                pembahasan_forum_terbuka
            FROM crf_pengajuan
            WHERE id = :id
              AND tahap_alur = 'kadep_operasional'
            FOR UPDATE
        ");

        $stmt->execute(['id' => $id]);
        $approvalCrf = $stmt->fetch();

        if (!$approvalCrf) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk persetujuan.');
        }

        if (!crfHasValidSla($approvalCrf) || !empty($approvalCrf['pembahasan_forum_terbuka'])) {
            $pdo->rollBack();
            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => !empty($approvalCrf['pembahasan_forum_terbuka'])
                    ? 'CRF ini masih menunggu pembahasan Forum sehingga belum dapat disetujui.'
                    : 'SLA CRF ini belum tersedia, sehingga belum dapat disetujui. Hubungi Admin.',
            ];
            crfRedirect(crfUrl('kadep.detail', ['id' => $id]));
            exit;
        }

        // SLA mulai dihitung sejak Kepala Departemen Operasional menyetujui,
        // lalu CRF diserahkan ke PIC CRF untuk dieksekusi.
        $slaStartedAt = $now;
        $slaDueAt = slaDueAt($slaStartedAt, $approvalCrf['sla_nilai'], $approvalCrf['sla_satuan']);
        if ($slaDueAt === null) {
            throw new RuntimeException('Tenggat SLA tidak dapat dihitung.');
        }

        // Target respon PIC dibekukan saat ini: tabel target global (sama untuk semua kategori) lebih dulu;
        // bila belum diatur, matriks lama per kategori sebagai cadangan; tanpa keduanya tidak ada SLA respon.
        $responUrgency = $approvalCrf['level_urgensi_final'] ?: ($approvalCrf['level'] ?: 'Normal');
        $responSla = crfRespTableReady($pdo) ? crfRespTarget($pdo, 'PIC_MULAI', $responUrgency) : null;
        if ($responSla === null && !empty($approvalCrf['kategori_id'])) {
            $responCategory = findCrfCategory($pdo, (int) $approvalCrf['kategori_id']);
            $responSla = $responCategory ? (crfCategoryResponMatrix($responCategory)[$responUrgency] ?? null) : null;
        }
        $responDueAt = $responSla ? slaDueAt($slaStartedAt, $responSla['value'], $responSla['unit']) : null;

        $stmt = $pdo->prepare("
            UPDATE crf_pengajuan
            SET
                sla_respon_nilai = :respon_nilai,
                sla_respon_satuan = :respon_satuan,
                sla_respon_tenggat = :respon_tenggat,
                sla_respon_at = NULL,
                sla_respon_hasil = NULL,
                disetujui_kadep_oleh = :approved_by,
                tgl_disetujui_kadep = :approved_at,
                catatan_persetujuan_kadep = :approval_note,
                sla_mulai = :sla_mulai,
                sla_tenggat = :sla_tenggat,
                otomasi_mulai = :otomasi_mulai,
                tahap_alur = 'OTOMASI',
                status = 'Dalam Proses'
            WHERE id = :id
              AND tahap_alur = 'kadep_operasional'
        ");

        $stmt->execute([
            'respon_nilai' => $responDueAt !== null ? $responSla['value'] : null,
            'respon_satuan' => $responDueAt !== null ? $responSla['unit'] : null,
            'respon_tenggat' => $responDueAt,
            'approved_by' => $user['id'],
            'approved_at' => $now,
            'approval_note' => $note !== ''
                ? $note
                : null,
            'sla_mulai' => $slaStartedAt,
            'sla_tenggat' => $slaDueAt,
            'otomasi_mulai' => $slaStartedAt,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('CRF sudah tidak tersedia untuk persetujuan.');
        }

        logCrfActivity(
            $pdo,
            $id,
            'Approval Kepala Departemen Operasional',
            'Kepala Departemen Operasional menyetujui permintaan CRF dari CMO. Resolution Time ' . slaLabel($approvalCrf['sla_nilai'], $approvalCrf['sla_satuan'])
                . ' mulai dihitung, batas ' . date('d-m-Y H:i', strtotime($slaDueAt)) . '. CRF diserahkan ke PIC Otomasi untuk dikerjakan.'
                . ($note !== '' ? ' Catatan: ' . $note : ''),
            $actor,
            'Menunggu Persetujuan',
            'Disetujui · Pengerjaan'
        );

        // PIC CRF menerima rincian SLA; Pemohon hanya diberi tahu CRF-nya disetujui.
        $picRecipients = array_values(array_diff(
            array_map('intval', $handlerRecipients ?: crfUserIdsForRole($pdo, 'otomasi')),
            [(int) $crf['pengguna_id']]
        ));
        notifyUsers(
            $pdo,
            $picRecipients,
            'CRF disetujui: ' . $crfNumber,
            'CRF ' . $crfNumber . ' disetujui Kepala Departemen Operasional. Resolution Time mulai dihitung dan batas ' . date('d-m-Y H:i', strtotime($slaDueAt)) . '. PIC Otomasi dapat mengerjakan CRF.',
            $crfLink,
            $id,
            (int) $user['id'],
            true
        );
        notifyUsers(
            $pdo,
            [(int) $crf['pengguna_id']],
            'CRF disetujui: ' . $crfNumber,
            'CRF ' . $crfNumber . ' disetujui Kepala Departemen Operasional dan diteruskan ke PIC Otomasi untuk dikerjakan.',
            $crfLink,
            $id,
            (int) $user['id'],
            true
        );

        $pdo->commit();
        dispatchPendingNotificationEmails($pdo);


        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'CRF berhasil disetujui. Resolution Time mulai dihitung dan CRF diserahkan ke PIC Otomasi.'
        ];


        crfRedirect(crfUrl('kadep.index'));
        exit;
    }


    throw new RuntimeException(
        'Aksi persetujuan tidak dikenal.'
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'kadep_operasional_approve error: '
        . $e->getMessage()
    );

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat memproses persetujuan.'
    ];

    crfRedirect(crfUrl('kadep.index'));
    exit;
}
