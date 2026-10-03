<?php
/**
 * actions/helpdesk_follow_up.php
 * Tindak lanjut ticket Helpdesk non-CRF oleh PIC kategori / Admin.
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../helpdesk/handling.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$actor = crfActorName($user);
$ticketId = (int) ($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$level = $_POST['level'] ?? '';
$followUp = trim($_POST['follow_up'] ?? '');
$redirect = '../helpdesk/detail.php?id=' . $ticketId;

try {
    $pdo->beginTransaction();

    // Kunci baris dulu supaya dua PIC tidak menimpa bersamaan.
    $pdo->prepare('SELECT id FROM helpdesk_tickets WHERE id = :id FOR UPDATE')->execute(['id' => $ticketId]);
    $ticket = findHelpdeskTicket($pdo, $ticketId);

    if (!$ticket || !canManageTicket($pdo, $ticket)) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Anda tidak berwenang menindaklanjuti ticket ini.'];
        header('Location: ' . ($ticket ? $redirect : '../helpdesk/handling.php'));
        exit;
    }

    $allowed = helpdeskStatusTransitions()[$ticket['status']] ?? [];
    $errors = [];
    if (!in_array($status, $allowed, true)) {
        $errors[] = 'Perubahan status tidak diizinkan.';
    }
    if ($level !== '' && !in_array($level, ['Tinggi', 'Sedang', 'Rendah'], true)) {
        $errors[] = 'Level tidak valid.';
    }
    if ($followUp === '' || mb_strlen($followUp) > 5000) {
        $errors[] = 'Catatan tindak lanjut wajib diisi (maks. 5000 karakter).';
    }

    if ($errors) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $errors)];
        header('Location: ' . $redirect);
        exit;
    }

    $pdo->prepare('
        UPDATE helpdesk_tickets
        SET status = :status,
            level = :level,
            follow_up = :follow_up,
            handled_by = :handled_by,
            handled_by_name = :handled_by_name,
            handled_at = NOW(),
            completed_at = CASE WHEN :is_done = 1 THEN NOW() ELSE completed_at END,
            cancelled_at = CASE WHEN :is_cancel = 1 THEN NOW() ELSE cancelled_at END
        WHERE id = :id
    ')->execute([
        'status'          => $status,
        'level'           => $level !== '' ? $level : null,
        'follow_up'       => $followUp,
        'handled_by'      => (int) $user['id'],
        'handled_by_name' => $actor,
        'is_done'         => $status === 'Selesai' ? 1 : 0,
        'is_cancel'       => $status === 'Dibatalkan' ? 1 : 0,
        'id'              => $ticketId,
    ]);

    logHelpdeskActivity($pdo, $ticketId, 'Tindak Lanjut PIC', $followUp, $actor, $ticket['status'], $status);

    notifyUsers(
        $pdo,
        [(int) $ticket['user_id']],
        'Ticket ' . $ticket['ticket_number'] . ': ' . $status,
        'PIC ' . $ticket['category_name'] . ' (' . $actor . ') menindaklanjuti ticket Anda: '
            . mb_strimwidth($followUp, 0, 200, '…'),
        'helpdesk/detail.php?id=' . $ticketId,
        null,
        $ticketId,
        (int) $user['id']
    );

    $pdo->commit();
    dispatchPendingNotificationEmails($pdo);

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Tindak lanjut ticket berhasil disimpan.'];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('helpdesk_follow_up error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Terjadi kesalahan saat menyimpan tindak lanjut.'];
}

header('Location: ' . $redirect);
exit;
