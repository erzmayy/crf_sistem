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
require_once __DIR__ . '/../includes/helpdesk.php';
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
$fullName = trim($_POST['full_name'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$email    = trim($_POST['email'] ?? '');

$changeDescription = trim($_POST['change_description'] ?? '');
$benefit            = trim($_POST['benefit'] ?? '');
$impact             = trim($_POST['impact'] ?? '');
$requestType        = trim($_POST['request_type'] ?? '');
$impactCategory     = trim($_POST['impact_category'] ?? '');
$reason             = trim($_POST['reason'] ?? '');

$fromDepartment = trim($_POST['from_department'] ?? '');
$fromDivision   = trim($_POST['from_division'] ?? '');

$budgetTypeRaw   = $_POST['budget_type'] ?? null;
// "Tidak ada biaya" (bawaan) disimpan sebagai tanpa jenis dan tanpa nominal.
if ($budgetTypeRaw === 'tidak_ada' || $budgetTypeRaw === '') {
    $budgetTypeRaw = null;
}
$budgetAmountRaw = $_POST['budget_amount'] ?? null;

$crfCategory          = resolveActiveCrfCategory($pdo, $_POST['crf_category_id'] ?? null);
$changeCategory       = $crfCategory['legacy_change_category'] ?? '';
$changeCategoryDetail = trim($_POST['change_category_detail'] ?? '');
$requesterPosition    = mb_substr(trim($_POST['requester_position'] ?? ''), 0, 150);

$alternativeSuggestion = trim($_POST['alternative_suggestion'] ?? '');

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
            SELECT id, request_number, status
            FROM change_requests
            WHERE id = :id
              AND user_id = :user_id
              AND status IN ('Draft', 'Perlu Revisi')
            LIMIT 1
        ");

        $checkStmt->execute([
            'id'      => $draftId,
            'user_id' => $user['id']
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
        $requestNumber = !empty($draft['request_number'])
            ? $draft['request_number']
            : generateRequestNumber($pdo, $today);

        $stmt = $pdo->prepare("
            UPDATE change_requests
            SET
                request_number = :request_number,
                full_name = :full_name,
                phone = :phone,
                email = :email,
                submission_date = :submission_date,
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
                crf_category_id = :crf_category_id,
                requester_position = :requester_position,
                change_category_detail = :change_category_detail,
                alternative_suggestion = :alternative_suggestion,
                workflow_stage = 'CMO_FILTER',
                status = 'Belum Ditindak Lanjuti'
            WHERE id = :id
              AND user_id = :user_id
              AND status IN ('Draft', 'Perlu Revisi')
        ");

        $stmt->execute([
            'request_number'         => $requestNumber,
            'full_name'              => $fullName,
            'phone'                  => $phone,
            'email'                  => $email,
            'submission_date'        => $submissionDate,
            'to_department'          => $toDepartment,
            'to_division'            => $toDivision,
            'from_department'        => $fromDepartment,
            'from_division'          => $fromDivision,
            'change_description'     => $changeDescription,
            'benefit'                => $benefit,
            'impact'                 => $impact,
            'request_type'           => $requestType,
            'impact_category'        => $impactCategory,
            'level'                  => $level,
            'reason'                 => $reason,
            'budget_type'            => $budgetTypeRaw,
            'budget_amount'          => $budgetAmount,
            'change_category'        => $changeCategory,
            'crf_category_id'        => (int) $crfCategory['id'],
            'requester_position'     => $requesterPosition !== '' ? $requesterPosition : null,
            'change_category_detail' => $changeCategoryDetailValue,
            'alternative_suggestion' => $alternativeSuggestion !== '' ? $alternativeSuggestion : null,
            'id'                     => $draftId,
            'user_id'                => $user['id'],
        ]);

        $crfId = $draftId;

    /*
     * ==============================================================
     * B. SUBMIT FORM BARU
     * ==============================================================
     */
    } else {

        $requestNumber = generateRequestNumber($pdo, $today);

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
                crf_category_id,
                requester_position,
                change_category_detail,
                alternative_suggestion,
                level,
                workflow_stage,
                status
            ) VALUES (
                :request_number,
                :user_id,
                :full_name,
                :phone,
                :email,
                :submission_date,
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
                :crf_category_id,
                :requester_position,
                :change_category_detail,
                :alternative_suggestion,
                :level,
                'CMO_FILTER',
                'Belum Ditindak Lanjuti'
            )
        ");

        $stmt->execute([
            'request_number'          => $requestNumber,
            'user_id'                => $user['id'],
            'full_name'              => $fullName,
            'phone'                  => $phone,
            'email'                  => $email,
            'submission_date'        => $submissionDate,
            'to_department'          => $toDepartment,
            'to_division'            => $toDivision,
            'from_department'        => $fromDepartment,
            'from_division'          => $fromDivision,
            'change_description'     => $changeDescription,
            'benefit'                => $benefit,
            'impact'                 => $impact,
            'request_type'           => $requestType,
            'impact_category'        => $impactCategory,
            'level'                  => $level,
            'reason'                 => $reason,
            'budget_type'            => $budgetTypeRaw,
            'budget_amount'          => $budgetAmount,
            'change_category'        => $changeCategory,
            'crf_category_id'        => (int) $crfCategory['id'],
            'requester_position'     => $requesterPosition !== '' ? $requesterPosition : null,
            'change_category_detail' => $changeCategoryDetailValue,
            'alternative_suggestion' => $alternativeSuggestion !== '' ? $alternativeSuggestion : null,
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
     * 6B. Ticket Helpdesk ikut diperbarui + notifikasi CMO & Handler
     * ------------------------------------------------------------------ */
    syncHelpdeskTicketFromCrf($pdo, $crfId, $actor);

    $notifyTitle = ($isResubmission ? 'CRF dikirim ulang: ' : 'CRF baru: ') . $requestNumber;
    $notifyMessage = $actor . ' mengajukan CRF kategori ' . $crfCategory['name']
        . '. CRF menunggu verifikasi CMO.';
    notifyUsers(
        $pdo,
        crfUserIdsForRole($pdo, 'cmo'),
        $notifyTitle,
        $notifyMessage,
        'crf/open.php?id=' . $crfId,
        $crfId,
        null,
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