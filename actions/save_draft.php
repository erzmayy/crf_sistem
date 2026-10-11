<?php
/**
 * actions/save_draft.php
 * ---------------------------------------------------------------
 * Menyimpan pengajuan CRF sebagai Draft.
 *
 * Draft boleh belum lengkap.
 * Validasi kelengkapan dilakukan saat user menekan Submit CRF.
 *
 * Jika id tidak ada:
 *   -> membuat draft baru
 *
 * Jika id ada:
 *   -> memperbarui draft milik user yang sedang login
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../user/form_crf.php');
    exit;
}

verifyCsrf();


$pdo  = getConnection();
$user = getCurrentUser();

$id = (int) ($_POST['id'] ?? 0);


/*
 * ---------------------------------------------------------------
 * Ambil data dari form
 * ---------------------------------------------------------------
 */
$fullName = trim($_POST['nama_pemohon'] ?? '');
$phone    = trim($_POST['telepon'] ?? '');
$email    = trim($_POST['email'] ?? '');

$fromDepartment = trim($_POST['dept_asal'] ?? '');
$fromDivision   = trim($_POST['divisi_asal'] ?? '');

$changeDescription = trim($_POST['deskripsi_perubahan'] ?? '');
$benefit           = trim($_POST['manfaat'] ?? '');
$impact            = trim($_POST['dampak'] ?? '');
$requestType       = trim($_POST['jenis_pengajuan'] ?? '');
$impactCategory    = trim($_POST['kategori_dampak'] ?? '');
$reason            = trim($_POST['alasan'] ?? '');

$budgetType = $_POST['jenis_anggaran'] ?? null;
if ($budgetType === 'tidak_ada') {
    $budgetType = null;
}

$budgetAmountRaw = trim($_POST['nilai_anggaran'] ?? '');

if (
    $budgetAmountRaw !== ''
    && is_numeric($budgetAmountRaw)
) {
    $budgetAmount = (float) $budgetAmountRaw;
} else {
    $budgetAmount = null;
}

/*
 * Kategori CRF dinamis (master crf_kategori). Kolom ENUM lama
 * kategori_perubahan tetap diisi dari kelompok laporan kategori.
 */
$crfCategory = resolveActiveCrfCategory($pdo, $_POST['kategori_id'] ?? null);
$crfCategoryId = $crfCategory ? (int) $crfCategory['id'] : null;
$changeCategory = $crfCategory['kategori_perubahan_lama'] ?? '';
$requesterPosition = mb_substr(trim($_POST['jabatan_pemohon'] ?? ''), 0, 150);

$changeCategoryDetail = trim(
    $_POST['detail_kategori_perubahan'] ?? ''
);

$alternativeSuggestion = trim(
    $_POST['saran_alternatif'] ?? ''
);


/*
 * Validasi ringan untuk nilai dropdown.
 * Draft boleh kosong, tetapi kalau nilainya diisi harus valid.
 */
$allowedBudgetTypes = [
    'rkap',
    'boq_pks',
    'anggaran_baru'
];

$allowedCategories = [
    'Aplikasi',
    'Infrastruktur',
    'Proses',
    'Security',
    'Lainnya'
];
$allowedRequestTypes = crfRequestTypeOptions();
$allowedImpactCategories = array_keys(crfImpactOptions());

if (
    $budgetType !== null
    &&
    $budgetType !== ''
    &&
    !in_array($budgetType, $allowedBudgetTypes, true)
) {
    $budgetType = null;
}

// Tanpa jenis biaya berarti tanpa nominal.
if ($budgetType === null || $budgetType === '') {
    $budgetAmount = null;
}

if (
    $changeCategory !== ''
    &&
    !in_array($changeCategory, $allowedCategories, true)
) {
    $changeCategory = '';
}

if (!in_array($requestType, $allowedRequestTypes, true)) {
    $requestType = '';
}

if (!in_array($impactCategory, $allowedImpactCategories, true)) {
    $impactCategory = '';
}

$level = crfUrgencyForImpact($impactCategory);


/*
 * Nilai otomatis / tetap.
 */
$toDepartment = 'Departemen Operasional';
$toDivision   = 'Divisi Otomasi';


try {

    $pdo->beginTransaction();


    /*
     * ===========================================================
     * UPDATE DRAFT LAMA
     * ===========================================================
     */
    if ($id > 0) {

        /*
         * Pastikan draft benar-benar milik user yang sedang login.
         */
        $checkStmt = $pdo->prepare(
            'SELECT id, nomor_register, status
             FROM crf_pengajuan
             WHERE id = :id
               AND pengguna_id = :pengguna_id
               AND status IN (\'Draft\', \'Perlu Revisi\')
             LIMIT 1'
        );

        $checkStmt->execute([
            'id'      => $id,
            'pengguna_id' => $user['id']
        ]);

        $draft = $checkStmt->fetch();

        if (!$draft) {

            $pdo->rollBack();

            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => 'Draft tidak ditemukan atau bukan milik Anda.'
            ];

            header('Location: ../user/pengajuan_saya.php');
            exit;
        }


        /*
         * Update isi draft.
         *
         * Field yang belum diisi disimpan sebagai string kosong.
         * tgl_pengajuan tetap NULL karena belum submit.
         */
        $stmt = $pdo->prepare("
            UPDATE crf_pengajuan
             SET
                nama_pemohon = :nama_pemohon,
                telepon = :telepon,
                email = :email,
                -- tgl_pengajuan = NULL,
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
                tahap_alur = 'PEMOHON'
             WHERE id = :id
               AND pengguna_id = :pengguna_id
               AND status IN ('Draft', 'Perlu Revisi')
        " );

        $stmt->execute([
            'nama_pemohon' => $fullName,
            'telepon' => $phone,
            'email' => $email,
            'dept_tujuan' => $toDepartment,
            'divisi_tujuan' => $toDivision,
            'dept_asal' => $fromDepartment,
            'divisi_asal' => $fromDivision,
            'deskripsi_perubahan' => $changeDescription,
            'manfaat' => $benefit,
            'dampak' => $impact,
            'jenis_pengajuan' => $requestType !== '' ? $requestType : null,
            'kategori_dampak' => $impactCategory !== '' ? $impactCategory : null,
            'level' => $level,
            'alasan' => $reason,
            'jenis_anggaran' => $budgetType !== '' ? $budgetType : null,
            'nilai_anggaran' => $budgetAmount,
            'kategori_perubahan' => $changeCategory !== '' ? $changeCategory : null,
            'kategori_id' => $crfCategoryId,
            'jabatan_pemohon' => $requesterPosition !== '' ? $requesterPosition : null,
            'detail_kategori_perubahan' => $changeCategoryDetail,
            'saran_alternatif' => $alternativeSuggestion !== '' ? $alternativeSuggestion : null,
            'id' => $id,
            'pengguna_id' => $user['id']
        ]);

        $draftId = $id;
        $requestNumber = $draft['nomor_register'];


    /*
     * ===========================================================
     * BUAT DRAFT BARU
     * ===========================================================
     */
    } else {

                /*
         * Draft belum memiliki Nomor Register.
         * Nomor dibuat saat CRF di-Submit.
         */
        $requestNumber = null;


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
                tinjauan_pasca_implementasi,
                implementasi,
                level,
                tahap_alur,
                status
            ) VALUES (
                :nomor_register,
                :pengguna_id,
                :nama_pemohon,
                :telepon,
                :email,
                NULL,
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
                NULL,
                NULL,
                :level,
                'PEMOHON',
                'Draft'
            )
        " );


                $stmt->execute([
                'nomor_register' => $requestNumber,
                'pengguna_id' => $user['id'],
                'nama_pemohon' => $fullName,
                'telepon' => $phone,
                'email' => $email,
                'dept_tujuan' => $toDepartment,
                'divisi_tujuan' => $toDivision,
                'dept_asal' => $fromDepartment,
                'divisi_asal' => $fromDivision,
                'deskripsi_perubahan' => $changeDescription,
                'manfaat' => $benefit,
                'dampak' => $impact,
                'jenis_pengajuan' => $requestType !== '' ? $requestType : null,
                'kategori_dampak' => $impactCategory !== '' ? $impactCategory : null,
                'level' => $level,
                'alasan' => $reason,
                'jenis_anggaran' => $budgetType !== '' ? $budgetType : null,
                'nilai_anggaran' => $budgetAmount,
                'kategori_perubahan' => $changeCategory !== '' ? $changeCategory : null,
                'kategori_id' => $crfCategoryId,
                'jabatan_pemohon' => $requesterPosition !== '' ? $requesterPosition : null,
                'detail_kategori_perubahan' => $changeCategoryDetail,
                'saran_alternatif' => $alternativeSuggestion !== '' ? $alternativeSuggestion : null
            ]);

            $draftId = (int) $pdo->lastInsertId();

            /*
            * Catat aktivitas pertama saat pengajuan dibuat.
            */
            // $actor = !empty($user['nama'])
            //     ? $user['nama']
            //     : $user['userid'];

            // $logStmt = $pdo->prepare("
            //     INSERT INTO crf_log_aktivitas (
            //         pengajuan_id,
            //         activity,
            //         description,
            //         actor
            //     ) VALUES (
            //         :pengajuan_id,
            //         :activity,
            //         :description,
            //         :actor
            //     )
            // ");

            // $logStmt->execute([
            //     'pengajuan_id' => $draftId,
            //     'activity'          => 'Pengajuan Dibuat',
            //     'description'       => 'Draft pengajuan CRF berhasil dibuat.',
            //     'actor'             => $actor,
            // ]);
        }


    /*
     * ===========================================================
     * UPLOAD LAMPIRAN
     * ===========================================================
     *
     * Sama seperti Submit CRF:
     * file bisa ikut disimpan ketika draft dibuat/diperbarui.
     */
    $uploadErrors = handleAttachmentUploads(
        $pdo,
        $draftId,
        $_FILES['attachments'] ?? []
    );


    $pdo->commit();


    /*
     * Pesan berhasil.
     */
    $message = ($id > 0 && ($draft['status'] ?? '') === 'Perlu Revisi')
        ? 'Perubahan revisi berhasil disimpan. Silakan lanjutkan perbaikan lalu kirim ulang.'
        : ($id > 0
            ? 'Draft berhasil diperbarui. Anda dapat melanjutkan pengisian kapan saja.'
            : 'Draft baru berhasil disimpan. Nomor Register akan dibuat setelah CRF diajukan.');

    if ($uploadErrors) {

        $_SESSION['flash'] = [
            'type' => 'warning',
            'message' =>
                $message . ' Namun ada file yang gagal diupload: '
                . implode(' ', $uploadErrors)
        ];

    } else {

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => $message
        ];
    }


    /*
     * Kembali ke form.
     *
     * Untuk sekarang draft akan dikembangkan agar bisa
     * dibuka kembali berdasarkan ID pada tahap berikutnya.
     */
    header(
        'Location: ../user/form_crf.php?id=' . $draftId
    );

    exit;


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'save_draft error: ' . $e->getMessage()
    );

    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => 'Terjadi kesalahan saat menyimpan draft. Silakan coba lagi.'
    ];

    if ($id > 0) {
        header('Location: ../user/form_crf.php?id=' . $id);
    } else {
        header('Location: ../user/form_crf.php');
    }

    exit;
}