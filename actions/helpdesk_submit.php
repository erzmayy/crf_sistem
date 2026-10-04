<?php
/**
 * actions/helpdesk_submit.php
 * ---------------------------------------------------------------
 * Submit Formulir Helpdesk.
 *
 * - Permintaan yang sudah ada : tambah keterangan ke ticket milik user.
 * - Permintaan Baru (biasa)   : buat ticket, notifikasi PIC kategori.
 * - Permintaan Baru + kategori Butuh CRF:
 *     dalam SATU transaksi buat ticket + Draft CRF yang sudah terisi
 *     (data pelapor, isi pesan, kategori CRF default), lalu redirect
 *     ke Form CRF. Pemohon tidak mengisi data yang sama dua kali.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../helpdesk/form.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser(true);
$actor = crfActorName($user);

$mode = ($_POST['request_mode'] ?? 'new') === 'existing' ? 'existing' : 'new';
$message = trim($_POST['message'] ?? '');
$phone = trim((string) ($user['no_wa'] ?? ''));
if ($phone === '') {
    $phone = mb_substr(trim($_POST['phone'] ?? ''), 0, 30);
}

function helpdeskSubmitFail(array $errors): void
{
    $_SESSION['old_helpdesk'] = $_POST;
    $_SESSION['flash'] = [
        'type' => 'danger',
        'message' => "Mohon lengkapi data berikut:\n\n• " . implode("\n• ", $errors),
    ];
    header('Location: ../helpdesk/form.php');
    exit;
}

$errors = [];
if ($message === '') {
    $errors[] = 'Isi Pesan wajib diisi.';
} elseif (mb_strlen($message) > 5000) {
    $errors[] = 'Isi Pesan maksimal 5000 karakter.';
}

/* ==================================================================
 * A. Keterangan tambahan untuk ticket yang sudah ada
 * ================================================================== */
if ($mode === 'existing') {
    $ticketId = (int) ($_POST['existing_ticket_id'] ?? 0);
    $ticket = $ticketId > 0 ? findHelpdeskTicket($pdo, $ticketId) : null;

    if (!$ticket || (int) $ticket['user_id'] !== (int) $user['id']) {
        $errors[] = 'Ticket yang dipilih tidak ditemukan.';
    } elseif (in_array($ticket['status'], ['Selesai', 'Dibatalkan'], true)) {
        $errors[] = 'Ticket sudah ditutup. Silakan buat Permintaan Baru.';
    }

    if ($errors) {
        helpdeskSubmitFail($errors);
    }

    try {
        $pdo->beginTransaction();
        logHelpdeskActivity($pdo, $ticketId, 'Keterangan Tambahan', $message, $actor);
        notifyUsers(
            $pdo,
            helpdeskCategoryPicIds($pdo, (int) $ticket['helpdesk_category_id']),
            'Keterangan tambahan: ' . helpdeskTicketLabel($ticket),
            $actor . ' menambahkan keterangan pada ticket ' . helpdeskTicketLabel($ticket) . '.',
            'helpdesk/detail.php?id=' . $ticketId,
            null,
            $ticketId,
            (int) $user['id']
        );
        $pdo->commit();
        dispatchPendingNotificationEmails($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('helpdesk_submit existing error: ' . $e->getMessage());
        helpdeskSubmitFail(['Terjadi kesalahan saat menyimpan keterangan. Silakan coba lagi.']);
    }

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Keterangan tambahan berhasil dikirim.'];
    header('Location: ../helpdesk/detail.php?id=' . $ticketId);
    exit;
}

/* ==================================================================
 * B. Permintaan Baru
 * ================================================================== */
$category = findHelpdeskCategory($pdo, (int) ($_POST['helpdesk_category_id'] ?? 0));
$requestKind = $_POST['request_kind'] ?? '';
$reportTime = trim($_POST['report_time'] ?? '');

if (!$category || (int) $category['is_active'] !== 1) {
    $errors[] = 'Kategori Helpdesk wajib dipilih.';
}
if (!array_key_exists($requestKind, helpdeskRequestKinds())) {
    $errors[] = 'Kategori/Dampak wajib dipilih.';
}
if ($phone === '') {
    $errors[] = 'No Handphone/WA wajib diisi.';
}

/*
 * Request pada kategori "via CRF" tidak menjadi ticket Helpdesk: pemohon
 * langsung diarahkan ke Form CRF dengan isi pesan & kategori terisi.
 * Belum ada data yang disimpan sampai pemohon menyimpan draft / mengajukan CRF.
 */
if ($category && helpdeskRoutesToCrf($category, (string) $requestKind)) {
    if ($errors) {
        helpdeskSubmitFail($errors);
    }

    $crfCategory = !empty($category['default_crf_category_id'])
        ? findCrfCategory($pdo, (int) $category['default_crf_category_id'])
        : null;

    $_SESSION['old_crf'] = array_filter([
        'change_description' => $message,
        'crf_category_id'    => $crfCategory['id'] ?? null,
        'phone'              => $phone,
    ]);
    $_SESSION['flash'] = [
        'type' => 'info',
        'message' => 'Permintaan perubahan ' . $category['name'] . ' diajukan melalui Change Request Form. '
            . 'Data pelapor dan isi pesan sudah terisi — lengkapi lalu klik Ajukan CRF.',
    ];
    header('Location: ../user/form_crf.php');
    exit;
}

if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $reportTime)) {
    $errors[] = 'Jam Mulai Laporan tidak valid.';
}
if ($errors) {
    helpdeskSubmitFail($errors);
}

$uploadErrors = [];

try {
    $pdo->beginTransaction();

    $ticketId = createHelpdeskTicket($pdo, $user, $category, [
        'phone'        => $phone,
        'request_kind' => $requestKind,
        'report_time'  => $reportTime,
        'message'      => $message,
    ]);

    $ticketLabel = helpdeskTicketLabel(['category_name' => $category['name'], 'created_at' => date('Y-m-d H:i:s')]);

    $uploadErrors = handleHelpdeskAttachmentUploads($pdo, $ticketId, $_FILES['attachments'] ?? []);

    notifyUsers(
        $pdo,
        helpdeskCategoryPicIds($pdo, (int) $category['id']),
        'Ticket baru: ' . $ticketLabel,
        $actor . ' mengirim permintaan pada kategori ' . $category['name'] . ': '
            . mb_strimwidth($message, 0, 200, '…'),
        'helpdesk/detail.php?id=' . $ticketId,
        null,
        $ticketId,
        (int) $user['id']
    );

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('helpdesk_submit error: ' . $e->getMessage());
    helpdeskSubmitFail(['Terjadi kesalahan saat menyimpan permintaan. Silakan coba lagi.']);
}

$_SESSION['flash'] = $uploadErrors
    ? ['type' => 'warning', 'message' => 'Ticket berhasil dikirim, namun ada lampiran yang gagal diupload: ' . implode(' ', $uploadErrors)]
    : ['type' => 'success', 'message' => 'Ticket berhasil dikirim ke PIC ' . $category['name'] . '.'];
header('Location: ../helpdesk/detail.php?id=' . $ticketId);
exit;
