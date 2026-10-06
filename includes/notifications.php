<?php
/**
 * includes/notifications.php
 * ---------------------------------------------------------------
 * Notifikasi in-app (lonceng) + email.
 *
 * Pola pakai di action:
 *   1. notifyUsers(...) di dalam transaksi (baris notifikasi ikut
 *      di-rollback jika proses gagal).
 *   2. Setelah commit: dispatchPendingNotificationEmails($pdo).
 *      Email yang gagal tidak menggagalkan proses utama.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/session.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Simpan notifikasi untuk beberapa user sekaligus.
 * $url relatif terhadap root aplikasi, contoh 'crf/open.php?id=5'.
 *
 * @param int[] $userIds
 */
function notifyUsers(
    PDO $pdo,
    array $userIds,
    string $title,
    string $message,
    string $url = '',
    ?int $crfId = null,
    ?int $ticketId = null,
    ?int $exceptUserId = null
): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($exceptUserId !== null) {
        $userIds = array_values(array_diff($userIds, [$exceptUserId]));
    }

    if (!$userIds) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO notifications (user_id, title, message, url, change_request_id, helpdesk_ticket_id)
        VALUES (:user_id, :title, :message, :url, :crf_id, :ticket_id)
    ');

    foreach ($userIds as $userId) {
        $stmt->execute([
            'user_id'   => $userId,
            'title'     => mb_substr($title, 0, 150),
            'message'   => $message,
            'url'       => $url !== '' ? $url : null,
            'crf_id'    => $crfId,
            'ticket_id' => $ticketId,
        ]);
    }
}

/**
 * CMO yang meloloskan CRF (penerima notifikasi finalisasi). Bila tidak
 * tercatat atau sudah bukan CMO, kembali ke seluruh CMO.
 *
 * @return int[]
 */
function crfCmoRecipients(PDO $pdo, int $crfId): array
{
    $allCmo = crfUserIdsForRole($pdo, 'cmo');

    $stmt = $pdo->prepare("
        SELECT user_id FROM crf_activity_logs
        WHERE change_request_id = :id AND activity = 'Lolos Filter CMO' AND user_id IS NOT NULL
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(['id' => $crfId]);
    $screenerId = (int) $stmt->fetchColumn();

    return $screenerId > 0 && in_array($screenerId, $allCmo, true) ? [$screenerId] : $allCmo;
}

/**
 * Buang user yang masih punya notifikasi belum dibaca dengan judul sama
 * untuk CRF yang sama (hindari tumpukan notifikasi komentar).
 *
 * @param int[] $userIds
 * @return int[]
 */
function filterUsersWithoutUnread(PDO $pdo, array $userIds, int $crfId, string $title): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) {
        return [];
    }

    $in = implode(',', $userIds);
    $stmt = $pdo->prepare("
        SELECT DISTINCT user_id FROM notifications
        WHERE change_request_id = :crf AND title = :title AND read_at IS NULL AND user_id IN ($in)
    ");
    $stmt->execute(['crf' => $crfId, 'title' => mb_substr($title, 0, 150)]);

    return array_values(array_diff($userIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

/**
 * ID user untuk role workflow CRF (mengikuti aturan resolver atau tabel role).
 *
 * @return int[]
 */
function crfUserIdsForRole(PDO $pdo, string $role): array
{
    if (CRF_ROLE_SOURCE !== 'resolver') {
        $stmt = $pdo->prepare('SELECT user_id FROM crf_user_roles WHERE role = :role AND is_active = 1');
        $stmt->execute(['role' => $role]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $userTable = crfUserTable();

    switch ($role) {
        case 'cmo':
            $values = CRF_CMO_DEPTS;
            $column = 'dept';
            break;
        case 'kadep_operasional':
            $values = CRF_KADEP_OPERASIONAL_USERIDS;
            $column = 'userid';
            break;
        case 'otomasi':
            $values = CRF_OTOMASI_USERIDS;
            $column = 'userid';
            break;
        case 'admin':
            $values = CRF_ADMIN_USERIDS;
            $column = 'userid';
            break;
        default:
            return [];
    }

    if (!$values) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $stmt = $pdo->prepare("SELECT id FROM {$userTable} WHERE {$column} IN ({$placeholders})");
    $stmt->execute(array_values($values));

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function unreadNotificationCount(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // Tabel notifikasi belum dimigrasi: jangan sampai header ikut error.
        error_log('unreadNotificationCount: ' . $e->getMessage());
        return 0;
    }
}

function recentNotifications(PDO $pdo, int $userId, int $limit = 6): array
{
    try {
        $limit = max(1, min(50, $limit));
        $stmt = $pdo->prepare("
            SELECT * FROM notifications
            WHERE user_id = :user_id
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('recentNotifications: ' . $e->getMessage());
        return [];
    }
}

function crfMailConfig(): ?array
{
    static $config = false;

    if ($config === false) {
        $path = __DIR__ . '/../config/mail.php';
        $config = is_file($path) ? require $path : null;
        if (!is_array($config) || empty($config['enabled'])) {
            $config = null;
        }
    }

    return $config;
}

/**
 * Kirim email untuk notifikasi berstatus 'pending'. Dipanggil setelah commit.
 */
function dispatchPendingNotificationEmails(PDO $pdo, int $limit = 30): void
{
    try {
        $config = crfMailConfig();

        if ($config === null) {
            $pdo->exec("UPDATE notifications SET email_status = 'skipped' WHERE email_status = 'pending'");
            return;
        }

        $limit = max(1, min(100, $limit));
        $userTable = crfUserTable();
        $rows = $pdo->query("
            SELECT n.*, u.email AS recipient_email, u.nama AS recipient_name
            FROM notifications n
            LEFT JOIN {$userTable} u ON u.id = n.user_id
            WHERE n.email_status = 'pending'
            ORDER BY n.id
            LIMIT {$limit}
        ")->fetchAll();

        $update = $pdo->prepare('UPDATE notifications SET email_status = :status WHERE id = :id');

        foreach ($rows as $row) {
            $email = trim((string) ($row['recipient_email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $update->execute(['status' => 'skipped', 'id' => $row['id']]);
                continue;
            }

            $status = sendNotificationEmail($config, $email, (string) ($row['recipient_name'] ?? ''), $row)
                ? 'sent'
                : 'failed';
            $update->execute(['status' => $status, 'id' => $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('dispatchPendingNotificationEmails: ' . $e->getMessage());
    }
}

function sendNotificationEmail(array $config, string $toEmail, string $toName, array $notification): bool
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $config['host'];
        $mail->Port = (int) $config['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10;

        if (!empty($config['username'])) {
            $mail->SMTPAuth = true;
            $mail->Username = (string) $config['username'];
            $mail->Password = (string) $config['password'];
        }
        if (!empty($config['encryption'])) {
            $mail->SMTPSecure = (string) $config['encryption'];
        }

        $mail->setFrom((string) $config['from_email'], (string) ($config['from_name'] ?? 'Helpdesk & CRF'));
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = (string) $notification['title'];

        $link = '';
        if (!empty($notification['url'])) {
            $link = rtrim((string) ($config['app_url'] ?? ''), '/') . '/' . ltrim((string) $notification['url'], '/');
        }

        $body = '<p>Yth. ' . h($toName ?: 'Bapak/Ibu') . ',</p>'
            . '<p>' . nl2br(h((string) $notification['message'])) . '</p>';
        if ($link !== '') {
            $body .= '<p><a href="' . h($link) . '">Buka di aplikasi</a></p>';
        }
        $body .= '<p style="color:#888;font-size:12px">Email otomatis dari Helpdesk &amp; CRF PT Persona Prima Utama.</p>';

        $mail->Body = $body;
        $mail->AltBody = (string) $notification['message'] . ($link !== '' ? "\n\n" . $link : '');
        $mail->send();

        return true;
    } catch (Throwable $e) {
        error_log('sendNotificationEmail: ' . $e->getMessage());
        return false;
    }
}
