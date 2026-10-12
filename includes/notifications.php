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
        INSERT INTO crf_notifikasi (pengguna_id, judul, pesan, url, pengajuan_id)
        VALUES (:pengguna_id, :title, :message, :url, :crf_id)
    ');

    foreach ($userIds as $userId) {
        $stmt->execute([
            'pengguna_id'   => $userId,
            'title'     => mb_substr($title, 0, 150),
            'message'   => $message,
            'url'       => $url !== '' ? $url : null,
            'crf_id'    => $crfId,
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
        SELECT pengguna_id FROM crf_log_aktivitas
        WHERE pengajuan_id = :id AND aktivitas = 'Lolos Filter CMO' AND pengguna_id IS NOT NULL
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
        SELECT DISTINCT pengguna_id FROM crf_notifikasi
        WHERE pengajuan_id = :crf AND judul = :title AND tgl_dibaca IS NULL AND pengguna_id IN ($in)
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
        $stmt = $pdo->prepare('SELECT pengguna_id FROM crf_peran_pengguna WHERE peran = :role AND aktif = 1');
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
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crf_notifikasi WHERE pengguna_id = :pengguna_id AND tgl_dibaca IS NULL');
        $stmt->execute(['pengguna_id' => $userId]);

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
            SELECT * FROM crf_notifikasi
            WHERE pengguna_id = :pengguna_id
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $stmt->execute(['pengguna_id' => $userId]);

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
            $pdo->exec("UPDATE crf_notifikasi SET status_email = 'skipped' WHERE status_email = 'pending'");
            return;
        }

        $limit = max(1, min(100, $limit));
        $userTable = crfUserTable();
        $rows = $pdo->query("
            SELECT n.*, u.email AS recipient_email, u.nama AS recipient_name
            FROM crf_notifikasi n
            LEFT JOIN {$userTable} u ON u.id = n.pengguna_id
            WHERE n.status_email = 'pending'
            ORDER BY n.id
            LIMIT {$limit}
        ")->fetchAll();

        $update = $pdo->prepare('UPDATE crf_notifikasi SET status_email = :status WHERE id = :id');

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

/**
 * Isi email notifikasi: satu kolom, minimalis, dan formal. Memakai tabel dan
 * gaya inline supaya tampil sama di Outlook, Gmail, dan klien email lain.
 *
 * @param bool $withLogo true bila gambar tersemat dengan cid "crf-logo"
 */
function crfEmailHtml(string $recipientName, string $title, string $message, string $link, bool $withLogo): string
{
    $greeting = h(trim($recipientName) !== '' ? trim($recipientName) : 'Bapak/Ibu');
    $title = h($title);
    $message = nl2br(h($message));
    $font = "font-family:'Segoe UI',Helvetica,Arial,sans-serif;";

    $logo = $withLogo
        ? '<img src="cid:crf-logo" width="36" height="36" alt="PT Persona Prima Utama" style="display:block;border:0;">'
        : '';

    $button = '';
    if ($link !== '') {
        $button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 4px;"><tr>'
            . '<td style="background:#1f568f;border-radius:6px;">'
            . '<a href="' . h($link) . '" style="display:inline-block;padding:11px 26px;' . $font
            . 'font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">Buka CRF</a>'
            . '</td></tr></table>'
            . '<p style="margin:14px 0 0;' . $font . 'font-size:12px;line-height:1.5;color:#6b7785;">'
            . 'Jika tombol tidak berfungsi, salin tautan berikut ke peramban Anda:<br>'
            . '<a href="' . h($link) . '" style="color:#1f568f;word-break:break-all;">' . h($link) . '</a></p>';
    }

    return '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f6f9;">'
        // Teks pratinjau di daftar kotak masuk.
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $title . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;">'
        . '<tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" '
        . 'style="width:100%;max-width:560px;background:#ffffff;border:1px solid #e3e8ee;border-radius:8px;">'
        // Kepala surat
        . '<tr><td style="padding:22px 32px;border-bottom:1px solid #e3e8ee;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . ($logo !== '' ? '<td style="padding-right:12px;">' . $logo . '</td>' : '')
        . '<td style="' . $font . 'font-size:13px;line-height:1.4;color:#1f568f;font-weight:700;letter-spacing:.4px;">'
        . 'PT PERSONA PRIMA UTAMA<br>'
        . '<span style="font-weight:400;color:#6b7785;letter-spacing:0;">Change Request Form</span></td>'
        . '</tr></table></td></tr>'
        // Isi
        . '<tr><td style="padding:32px;' . $font . 'color:#1f2933;">'
        . '<p style="margin:0 0 20px;font-size:14px;line-height:1.6;">Yth. ' . $greeting . ',</p>'
        . '<p style="margin:0 0 16px;font-size:17px;line-height:1.4;font-weight:700;color:#12263f;">' . $title . '</p>'
        . '<p style="margin:0;font-size:14px;line-height:1.7;color:#3e4c59;">' . $message . '</p>'
        . $button
        . '</td></tr>'
        // Kaki
        . '<tr><td style="padding:18px 32px;border-top:1px solid #e3e8ee;' . $font
        . 'font-size:12px;line-height:1.6;color:#8795a1;">'
        . 'Email ini dikirim otomatis oleh Sistem CRF PT Persona Prima Utama. '
        . 'Mohon tidak membalas email ini.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
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

        $mail->setFrom((string) $config['from_email'], (string) ($config['from_name'] ?? 'CRF'));
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = (string) $notification['judul'];

        $link = '';
        if (!empty($notification['url'])) {
            $link = rtrim((string) ($config['app_url'] ?? ''), '/') . '/' . ltrim((string) $notification['url'], '/');
        }

        $logoPath = __DIR__ . '/../assets/img/logo-header.png';
        $hasLogo = is_file($logoPath)
            && $mail->addEmbeddedImage($logoPath, 'crf-logo', 'logo-ppu.png', 'base64', 'image/png');

        $mail->Body = crfEmailHtml(
            $toName,
            (string) $notification['judul'],
            (string) $notification['pesan'],
            $link,
            $hasLogo
        );
        $mail->AltBody = (string) $notification['pesan'] . ($link !== '' ? "\n\n" . $link : '');
        $mail->send();

        return true;
    } catch (Throwable $e) {
        error_log('sendNotificationEmail: ' . $e->getMessage());
        return false;
    }
}
