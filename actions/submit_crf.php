<?php
/**
 * actions/submit_crf.php
 * ---------------------------------------------------------------
 * Menangani tombol "Submit CRF".
 *
 * Alur:
 * - Form baru -> INSERT sebagai pengajuan resmi
 * - Draft -> UPDATE Draft yang sama menjadi "Belum Ditindak Lanjuti"
 * - Jika validasi gagal -> kembali ke form yang sama
 * - Nomor register Draft tetap dipertahankan saat Submit
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/forum_discussions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user/form_crf.php');
    exit;
}

verifyCsrf();


$pdo  = getConnection();
$user = getCurrentUser();

/* ------------------------------------------------------------------
 * 1. Ambil ID Draft
 * ------------------------------------------------------------------ */
$draftId = (int) ($_POST['id'] ?? 0);
$isResubmission = false;

/* ------------------------------------------------------------------
 * 2. Ambil & bersihkan input
 * ------------------------------------------------------------------ */
$fullName = trim($_POST['nama_pemohon'] ?? '');
$phone    = trim($_POST['telepon'] ?? '');
$email    = trim($_POST['email'] ?? '');

$changeDescription = trim($_POST['deskripsi_perubahan'] ?? '');
$benefit            = trim($_POST['manfaat'] ?? '');
$impact             = trim($_POST['dampak'] ?? '');
$requestType        = trim($_POST['jenis_pengajuan'] ?? '');
$impactCategory     = trim($_POST['kategori_dampak'] ?? '');
$reason             = trim($_POST['alasan'] ?? '');

$fromDepartment = trim($_POST['dept_asal'] ?? '');
$fromDivision   = trim($_POST['divisi_asal'] ?? '');

$budgetTypeRaw   = $_POST['jenis_anggaran'] ?? null;
// "Tidak ada biaya" (bawaan) disimpan sebagai tanpa jenis dan tanpa nominal.
if ($budgetTypeRaw === 'tidak_ada' || $budgetTypeRaw === '') {
    $budgetTypeRaw = null;
}
$budgetAmountRaw = $_POST['nilai_anggaran'] ?? null;

$crfCategory          = resolveActiveCrfCategory($pdo, $_POST['kategori_id'] ?? null);
$changeCategory       = $crfCategory['kategori_perubahan_lama'] ?? '';
$changeCategoryDetail = trim($_POST['detail_kategori_perubahan'] ?? '');
$requesterPosition    = mb_substr(trim($_POST['jabatan_pemohon'] ?? ''), 0, 150);

$alternativeSuggestion = trim($_POST['saran_alternatif'] ?? '');

/* ------------------------------------------------------------------
 * 3. Validasi
 * ------------------------------------------------------------------ */
$allowedCategories = [
    'Aplikasi',
    'Infrastruktur',
    'Proses',
    'Security',
    'Lainnya'
];

$allowedBudgetTypes = [
    'rkap',
    'boq_pks',
    'anggaran_baru'
];

$errors = [];
$allowedRequestTypes = crfRequestTypeOptions();
$allowedImpactCategories = array_keys(crfImpactOptions());
$level = crfUrgencyForImpact($impactCategory);

/* Informasi pengajuan */
if ($fullName === '') {
    $errors[] = 'Nama Lengkap wajib diisi.';
}

if ($phone === '') {
    $errors[] = 'No. Handphone/WA wajib diisi.';
}

if ($email === '') {
    $errors[] = 'Email wajib diisi.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Format email tidak valid.';
}

if (!in_array($requestType, $allowedRequestTypes, true)) {
    $errors[] = 'Tipe Pengajuan wajib dipilih.';
}

if (!in_array($impactCategory, $allowedImpactCategories, true)) {
    $errors[] = 'Dampak wajib dipilih.';
}

/* Change Request Description */
if ($changeDescription === '') {
    $errors[] = 'Rincian Permohonan Perubahan wajib diisi.';
}

if ($benefit === '') {
    $errors[] = 'Benefit dari Perubahan wajib diisi.';
}

if ($impact === '') {
    $errors[] = 'Dampak Jika Tidak Dilakukan Perubahan wajib diisi.';
}

if ($reason === '') {
    $errors[] = 'Alasan Permohonan Perubahan wajib diisi.';
}

/* Dari */
if ($fromDepartment === '') {
    $errors[] = 'Departemen wajib diisi.';
}

if ($fromDivision === '') {
    $errors[] = 'Divisi wajib diisi.';
}

/* Kategori (master Kategori CRF yang aktif) */
if (!$crfCategory || !in_array($changeCategory, $allowedCategories, true)) {
    $errors[] = 'Kategori CRF wajib dipilih.';
}

if ($changeCategory === 'Lainnya' && $changeCategoryDetail === '') {
    $errors[] = 'Detail Kategori wajib diisi untuk kategori "Lainnya".';
}

/* Budget */
if (
    $budgetTypeRaw !== null &&
    $budgetTypeRaw !== '' &&
    !in_array($budgetTypeRaw, $allowedBudgetTypes, true)
) {
    $errors[] = 'Pilihan Biaya / Anggaran tidak valid.';
}

$budgetAmount = null;

if ($budgetTypeRaw !== null && $budgetTypeRaw !== '') {

    if ($budgetAmountRaw === '' || !is_numeric($budgetAmountRaw)) {
        $errors[] = 'Nominal Biaya / Anggaran belum diisi.';
    } else {
        $budgetAmount = (float) $budgetAmountRaw;

        if ($budgetAmount < 0) {
            $errors[] = 'Nominal Biaya / Anggaran tidak valid.';
        }
    }

}

/* ------------------------------------------------------------------
 * 4. Jika validasi gagal
 * ------------------------------------------------------------------ */
if ($errors) {

    $_SESSION['old_crf'] = $_POST;

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' =>
            "Mohon lengkapi data berikut:\n\n• "
            . implode("\n• ", $errors),
    ];

    /*
     * Kalau sedang edit Draft, kembali ke Draft yang sama.
     * Jangan kembali ke form kosong.
     */
    if ($draftId > 0) {
        header('Location: ../user/form_crf.php?id=' . $draftId);
    } else {
        header('Location: ../user/form_crf.php');
    }

    exit;
}

/* ------------------------------------------------------------------
 * 5. Data otomatis
 * ------------------------------------------------------------------ */
$today = new DateTime();
$submissionDate = $today->format('Y-m-d');

$toDepartment = 'Departemen Operasional';
$toDivision   = 'Divisi Otomasi';

/*
 * Detail kategori wajib tetap berupa string kosong jika memang
 * tidak diperlukan, karena kolom database bersifat NOT NULL.
 */
$changeCategoryDetailValue = $changeCategoryDetail;

/* ------------------------------------------------------------------
 * 6. Simpan
 * ------------------------------------------------------------------ */
try {

    $pdo->beginTransaction();

    /*
     * ==============================================================
     * A. SUBMIT DARI DRAFT
     * ==============================================================
     */
    if ($draftId > 0) {

        /*
         * Pastikan Draft memang milik user yang sedang login.
         */
        $checkStmt = $pdo->prepare("
            SELECT id, nomor_register, status
            FROM crf_pengajuan
            WHERE id = :id
              AND pengguna_id = :pengguna_id
              AND status IN ('Draft', 'Perlu Revisi')
            LIMIT 1
        ");

        $checkStmt->execute([
            'id'      => $draftId,
            'pengguna_id' => $user['id']
        ]);

        $draft = $checkStmt->fetch();

        if (!$draft) {
            throw new RuntimeException(
                'Draft atau pengajuan revisi tidak ditemukan atau tidak dapat di-submit.'
            );
        }

        /*
        * Tandai jika user sedang mengirim ulang CRF
        * yang sebelumnya berstatus Perlu Revisi.
        */
        if ($draft['status'] === 'Perlu Revisi') {
            $isResubmission = true;
        }

                /*
         * Nomor Register dibuat saat Submit. Draft lama yang sudah
         * punya nomor, dan CRF "Perlu Revisi", tetap memakai nomornya.
         */
        $requestNumber = !empty($draft['nomor_register'])
            ? $draft['nomor_register']
            : generateRequestNumber($pdo, $today, $user);

        $stmt = $pdo->prepare("
            UPDATE crf_pengajuan
            SET
                nomor_register = :nomor_register,
                nama_pemohon = :nama_pemohon,
                telepon = :telepon,
                email = :email,
                tgl_pengajuan = :tgl_pengajuan,
                dept_tujuan = :dept_tujuan,
                divisi_tujuan = :divisi_tujuan,
                dept_asal = :dept_asal,
                divisi_asal = :divisi_asal,
                deskripsi_perubahan = :deskripsi_perubahan,
                manfaat = :manfaat,
                dampak = :dampak,
                jenis_pengajuan = :jenis_pengajuan,
                kategori_dampak = :kategori_dampak,
                level = :level,
                alasan = :alasan,
                jenis_anggaran = :jenis_anggaran,
                nilai_anggaran = :nilai_anggaran,
                kategori_perubahan = :kategori_perubahan,
                kategori_id = :kategori_id,
                jabatan_pemohon = :jabatan_pemohon,
                detail_kategori_perubahan = :detail_kategori_perubahan,
                saran_alternatif = :saran_alternatif,
                tahap_alur = 'CMO_FILTER',
                status = 'Belum Ditindak Lanjuti'
            WHERE id = :id
              AND pengguna_id = :pengguna_id
              AND status IN ('Draft', 'Perlu Revisi')
        ");

        $stmt->execute([
            'nomor_register'         => $requestNumber,
            'nama_pemohon'              => $fullName,
            'telepon'                  => $phone,
            'email'                  => $email,
            'tgl_pengajuan'        => $submissionDate,
            'dept_tujuan'          => $toDepartment,
            'divisi_tujuan'            => $toDivision,
            'dept_asal'        => $fromDepartment,
            'divisi_asal'          => $fromDivision,
            'deskripsi_perubahan'     => $changeDescription,
            'manfaat'                => $benefit,
            'dampak'                 => $impact,
            'jenis_pengajuan'           => $requestType,
            'kategori_dampak'        => $impactCategory,
            'level'                  => $level,
            'alasan'                 => $reason,
            'jenis_anggaran'            => $budgetTypeRaw,
            'nilai_anggaran'          => $budgetAmount,
            'kategori_perubahan'        => $changeCategory,
            'kategori_id'        => (int) $crfCategory['id'],
            'jabatan_pemohon'     => $requesterPosition !== '' ? $requesterPosition : null,
            'detail_kategori_perubahan' => $changeCategoryDetailValue,
            'saran_alternatif' => $alternativeSuggestion !== '' ? $alternativeSuggestion : null,
            'id'                     => $draftId,
            'pengguna_id'                => $user['id'],
        ]);

        $crfId = $draftId;

    /*
     * ==============================================================
     * B. SUBMIT FORM BARU
     * ==============================================================
     */
    } else {

        $requestNumber = generateRequestNumber($pdo, $today, $user);

        $stmt = $pdo->prepare("
            INSERT INTO crf_pengajuan (
                nomor_register,
                pengguna_id,
                nama_pemohon,
                telepon,
                email,
                tgl_pengajuan,
                dept_tujuan,
                divisi_tujuan,
                dept_asal,
                divisi_asal,
                deskripsi_perubahan,
                manfaat,
                dampak,
                jenis_pengajuan,
                kategori_dampak,
                alasan,
                jenis_anggaran,
                nilai_anggaran,
                kategori_perubahan,
                kategori_id,
                jabatan_pemohon,
                detail_kategori_perubahan,
                saran_alternatif,
                level,
                tahap_alur,
                status
            ) VALUES (
                :nomor_register,
                :pengguna_id,
                :nama_pemohon,
                :telepon,
                :email,
                :tgl_pengajuan,
                :dept_tujuan,
                :divisi_tujuan,
                :dept_asal,
                :divisi_asal,
                :deskripsi_perubahan,
                :manfaat,
                :dampak,
                :jenis_pengajuan,
                :kategori_dampak,
                :alasan,
                :jenis_anggaran,
                :nilai_anggaran,
                :kategori_perubahan,
                :kategori_id,
                :jabatan_pemohon,
                :detail_kategori_perubahan,
                :saran_alternatif,
                :level,
                'CMO_FILTER',
                'Belum Ditindak Lanjuti'
            )
        ");

        $stmt->execute([
            'nomor_register'          => $requestNumber,
            'pengguna_id'                => $user['id'],
            'nama_pemohon'              => $fullName,
            'telepon'                  => $phone,
            'email'                  => $email,
            'tgl_pengajuan'        => $submissionDate,
            'dept_tujuan'          => $toDepartment,
            'divisi_tujuan'            => $toDivision,
            'dept_asal'        => $fromDepartment,
            'divisi_asal'          => $fromDivision,
            'deskripsi_perubahan'     => $changeDescription,
            'manfaat'                => $benefit,
            'dampak'                 => $impact,
            'jenis_pengajuan'           => $requestType,
            'kategori_dampak'        => $impactCategory,
            'level'                  => $level,
            'alasan'                 => $reason,
            'jenis_anggaran'            => $budgetTypeRaw,
            'nilai_anggaran'          => $budgetAmount,
            'kategori_perubahan'        => $changeCategory,
            'kategori_id'        => (int) $crfCategory['id'],
            'jabatan_pemohon'     => $requesterPosition !== '' ? $requesterPosition : null,
            'detail_kategori_perubahan' => $changeCategoryDetailValue,
            'saran_alternatif' => $alternativeSuggestion !== '' ? $alternativeSuggestion : null,
        ]);

            $crfId = (int) $pdo->lastInsertId();
    }

    /* ------------------------------------------------------------------
     * 6A. Catat aktivitas timeline
     * ------------------------------------------------------------------ */

    $activity = $isResubmission
        ? 'Kirim Ulang'
        : 'Pengajuan Diajukan';

    $description = $isResubmission
        ? 'CRF dikirim ulang setelah dilakukan perbaikan.'
        : 'CRF berhasil diajukan.';

    $actor = crfActorName($user);

    logCrfActivity(
        $pdo,
        $crfId,
        $activity,
        $description,
        $actor,
        $isResubmission ? 'Perlu Revisi' : 'Draft',
        'Menunggu Verifikasi'
    );

    // SLA default terisi sejak submit, jadi CMO dan Forum langsung melihat nilai yang sama.
    forumApplyDefaultSla($pdo, $crfId, $user);

    /* ------------------------------------------------------------------
     * 6B. Notifikasi CMO & Handler
     * ------------------------------------------------------------------ */
    $notifyTitle = ($isResubmission ? 'CRF dikirim ulang: ' : 'CRF baru: ') . $requestNumber;
    $notifyMessage = $actor . ' mengajukan CRF kategori ' . $crfCategory['nama']
        . '. CRF menunggu verifikasi CMO.';
    notifyUsers(
        $pdo,
        crfUserIdsForRole($pdo, 'cmo'),
        $notifyTitle,
        $notifyMessage,
        'crf/open.php?id=' . $crfId,
        $crfId,
        (int) $user['id']
    );

    /* ------------------------------------------------------------------
     * 7. Upload attachment
     * ------------------------------------------------------------------ */
    $uploadErrors = handleAttachmentUploads(
        $pdo,
        $crfId,
        $_FILES['attachments'] ?? []
    );

    $pdo->commit();

    dispatchPendingNotificationEmails($pdo);

    /* ------------------------------------------------------------------
     * 8. Pesan sukses
     * ------------------------------------------------------------------ */
    if ($uploadErrors) {

        $_SESSION['flash'] = [
            'type'    => 'warning',
            'message' => 'CRF berhasil diajukan dengan Nomor Register '
                . $requestNumber
                . ', namun ada file yang gagal diupload: '
                . implode(' ', $uploadErrors),
        ];

    } else {

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'CRF berhasil diajukan dengan Nomor Register '
                . $requestNumber
                . '.',
        ];
    }

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('submit_crf error: ' . $e->getMessage());

    $_SESSION['old_crf'] = $_POST;

    $_SESSION['flash'] = [
        'type'    => 'danger',
        'message' => 'Terjadi kesalahan saat menyimpan CRF. Silakan coba lagi.',
    ];

    /*
     * Kalau error saat Edit Draft,
     * tetap kembali ke Draft yang sama agar data tidak hilang.
     */
    if ($draftId > 0) {
        header('Location: ../user/form_crf.php?id=' . $draftId);
    } else {
        header('Location: ../user/form_crf.php');
    }

    exit;
}

/* ------------------------------------------------------------------
 * 9. Setelah Submit berhasil
 * ------------------------------------------------------------------ */
header('Location: ../user/detail.php?id=' . $crfId);
exit;