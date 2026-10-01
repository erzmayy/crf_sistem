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
$fullName = trim($_POST['full_name'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$email    = trim($_POST['email'] ?? '');

$fromDepartment = trim($_POST['from_department'] ?? '');
$fromDivision   = trim($_POST['from_division'] ?? '');

$changeDescription = trim($_POST['change_description'] ?? '');
$benefit           = trim($_POST['benefit'] ?? '');
$impact            = trim($_POST['impact'] ?? '');
$requestType       = trim($_POST['request_type'] ?? '');
$impactCategory    = trim($_POST['impact_category'] ?? '');
$reason            = trim($_POST['reason'] ?? '');

$budgetType = $_POST['budget_type'] ?? null;

$budgetAmountRaw = trim($_POST['budget_amount'] ?? '');

if (
    $budgetAmountRaw !== ''
    && is_numeric($budgetAmountRaw)
) {
    $budgetAmount = (float) $budgetAmountRaw;
} else {
    $budgetAmount = null;
}

$changeCategory = $_POST['change_category'] ?? '';

$changeCategoryDetail = trim(
    $_POST['change_category_detail'] ?? ''
);

$alternativeSuggestion = trim(
    $_POST['alternative_suggestion'] ?? ''
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
            'SELECT id, request_number, status
             FROM change_requests
             WHERE id = :id
               AND user_id = :user_id
               AND status IN (\'Draft\', \'Perlu Revisi\')
             LIMIT 1'
        );

        $checkStmt->execute([
            'id'      => $id,
            'user_id' => $user['id']
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
         * submission_date tetap NULL karena belum submit.
         */
        $stmt = $pdo->prepare("
            UPDATE change_requests
             SET
                full_name = :full_name,
                phone = :phone,
                email = :email,
                -- submission_date = NULL,
                to_department = :to_department,
                to_division = :to_division,
                from_department = :from_department,
                from_division = :from_division,
                change_description = :change_description,
                benefit = :benefit,
                impact = :impact,
                request_type = :request_type,
                impact_category = :impact_category,
                level = :level,
                reason = :reason,
                budget_type = :budget_type,
                budget_amount = :budget_amount,
                change_category = :change_category,
                change_category_detail = :change_category_detail,
                alternative_suggestion = :alternative_suggestion,
                workflow_stage = 'PEMOHON'
             WHERE id = :id
               AND user_id = :user_id
               AND status IN ('Draft', 'Perlu Revisi')
        " );

        $stmt->execute([
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'to_department' => $toDepartment,
            'to_division' => $toDivision,
            'from_department' => $fromDepartment,
            'from_division' => $fromDivision,
            'change_description' => $changeDescription,
            'benefit' => $benefit,
            'impact' => $impact,
            'request_type' => $requestType !== '' ? $requestType : null,
            'impact_category' => $impactCategory !== '' ? $impactCategory : null,
            'level' => $level,
            'reason' => $reason,
            'budget_type' => $budgetType !== '' ? $budgetType : null,
            'budget_amount' => $budgetAmount,
            'change_category' => $changeCategory !== '' ? $changeCategory : null,
            'change_category_detail' => $changeCategoryDetail,
            'alternative_suggestion' => $alternativeSuggestion,
            'id' => $id,
            'user_id' => $user['id']
        ]);

        $draftId = $id;
        $requestNumber = $draft['request_number'];


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
            INSERT INTO change_requests (
                request_number,
                user_id,
                full_name,
                phone,
                email,
                submission_date,
                to_department,
                to_division,
                from_department,
                from_division,
                change_description,
                benefit,
                impact,
                request_type,
                impact_category,
                reason,
                budget_type,
                budget_amount,
                change_category,
                change_category_detail,
                alternative_suggestion,
                post_implementation_review,
                implementation,
                level,
                workflow_stage,
                status
            ) VALUES (
                :request_number,
                :user_id,
                :full_name,
                :phone,
                :email,
                NULL,
                :to_department,
                :to_division,
                :from_department,
                :from_division,
                :change_description,
                :benefit,
                :impact,
                :request_type,
                :impact_category,
                :reason,
                :budget_type,
                :budget_amount,
                :change_category,
                :change_category_detail,
                :alternative_suggestion,
                NULL,
                NULL,
                :level,
                'PEMOHON',
                'Draft'
            )
        " );


                $stmt->execute([
                'request_number' => $requestNumber,
                'user_id' => $user['id'],
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email,
                'to_department' => $toDepartment,
                'to_division' => $toDivision,
                'from_department' => $fromDepartment,
                'from_division' => $fromDivision,
                'change_description' => $changeDescription,
                'benefit' => $benefit,
                'impact' => $impact,
                'request_type' => $requestType !== '' ? $requestType : null,
                'impact_category' => $impactCategory !== '' ? $impactCategory : null,
                'level' => $level,
                'reason' => $reason,
                'budget_type' => $budgetType !== '' ? $budgetType : null,
                'budget_amount' => $budgetAmount,
                'change_category' => $changeCategory !== '' ? $changeCategory : null,
                'change_category_detail' => $changeCategoryDetail,
                'alternative_suggestion' => $alternativeSuggestion
            ]);

            $draftId = (int) $pdo->lastInsertId();

            /*
            * Catat aktivitas pertama saat pengajuan dibuat.
            */
            // $actor = !empty($user['nama'])
            //     ? $user['nama']
            //     : $user['userid'];

            // $logStmt = $pdo->prepare("
            //     INSERT INTO crf_activity_logs (
            //         change_request_id,
            //         activity,
            //         description,
            //         actor
            //     ) VALUES (
            //         :change_request_id,
            //         :activity,
            //         :description,
            //         :actor
            //     )
            // ");

            // $logStmt->execute([
            //     'change_request_id' => $draftId,
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