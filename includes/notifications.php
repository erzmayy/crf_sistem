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
require_once __DIR__ . '/crf_url.php';
require_once __DIR__ . '/whatsapp.php';

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
    ?int $exceptUserId = null,
    bool $whatsapp = false
): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($exceptUserId !== null) {
        $userIds = array_values(array_diff($userIds, [$exceptUserId]));
    }

    if (!$userIds) {
        return;
    }

    // status_wa 'pending' hanya bila diminta dan migrasi 027 sudah dijalankan (kolom ada).
    $withWhatsapp = $whatsapp && crfNotifikasiHasWaColumn($pdo);
    $stmt = $pdo->prepare($withWhatsapp
        ? "INSERT INTO crf_notifikasi (pengguna_id, judul, pesan, url, pengajuan_id, status_wa)
           VALUES (:pengguna_id, :title, :message, :url, :crf_id, 'pending')"
        : 'INSERT INTO crf_notifikasi (pengguna_id, judul, pesan, url, pengajuan_id)
           VALUES (:pengguna_id, :title, :message, :url, :crf_id)'
    );

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

function crfMailConfig(?PDO $pdo = null): ?array
{
    static $config = false;

    if ($config === false) {
        $path = __DIR__ . '/../config/mail.php';
        $config = is_file($path) ? require $path : null;
        if (!is_array($config) || empty($config['enabled'])) {
            $config = null;
        }

        // Mode module: tanpa config/mail.php, pakai pengaturan SMTP SIAP (menu Email > Pengaturan
        // Email, tabel tbl_email_settings) yang sama dengan 3rdparty/email_helper.php.
        if ($config === null && $pdo !== null && crfLayoutIsModule()) {
            $config = crfSiapMailConfig($pdo);
        }
    }

    return $config;
}

/** Pengaturan SMTP SIAP (tbl_email_settings, id = 1) dalam bentuk konfigurasi email CRF; null bila belum diisi. */
function crfSiapMailConfig(PDO $pdo): ?array
{
    try {
        $row = $pdo->query('SELECT * FROM tbl_email_settings WHERE id = 1')->fetch();
    } catch (Throwable $e) {
        error_log('crfSiapMailConfig: ' . $e->getMessage());
        return null;
    }

    if (!is_array($row) || trim((string) ($row['smtp_host'] ?? '')) === '') {
        return null;
    }

    $encryption = strtolower(trim((string) ($row['smtp_encryption'] ?? '')));

    return [
        'enabled'    => true,
        'host'       => (string) $row['smtp_host'],
        'port'       => (int) ($row['smtp_port'] ?? 587),
        'encryption' => in_array($encryption, ['ssl', 'tls'], true) ? $encryption : '',
        'username'   => (string) ($row['smtp_username'] ?? ''),
        'password'   => (string) ($row['smtp_password'] ?? ''),
        'from_email' => (string) ($row['from_email'] ?? ''),
        'from_name'  => (string) ($row['from_name'] ?? 'CRF PPU'),
        'app_url'    => defined('CRF_WA_APP_URL') ? (string) CRF_WA_APP_URL : 'https://siap.ptppu.co.id',
    ];
}

/**
 * Kirim email untuk notifikasi berstatus 'pending'. Dipanggil setelah commit.
 */
function dispatchPendingNotificationEmails(PDO $pdo, int $limit = 30): void
{
    // WhatsApp lewat antrean WA SIAP (includes/whatsapp.php); tidak pernah melempar exception.
    dispatchPendingNotificationWhatsapp($pdo);

    try {
        $config = crfMailConfig($pdo);

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

            $crfData = crfEmailCrfData($pdo, (int) ($row['pengajuan_id'] ?? 0));
            $status = sendNotificationEmail($config, $email, (string) ($row['recipient_name'] ?? ''), $row, $crfData)
                ? 'sent'
                : 'failed';
            $update->execute(['status' => $status, 'id' => $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('dispatchPendingNotificationEmails: ' . $e->getMessage());
    }
}

/**
 * Ringkasan CRF untuk kartu detail di email; null bila notifikasi tidak terkait CRF atau CRF tidak ditemukan.
 */
function crfEmailCrfData(PDO $pdo, int $crfId): ?array
{
    static $cache = [];

    if ($crfId <= 0) {
        return null;
    }
    if (array_key_exists($crfId, $cache)) {
        return $cache[$crfId];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT nomor_register, nama_pemohon, dept_asal, kategori_perubahan, level, status,
                    tgl_pengajuan, deskripsi_perubahan
             FROM crf_pengajuan WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $crfId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('crfEmailCrfData: ' . $e->getMessage());
        $row = false;
    }

    return $cache[$crfId] = is_array($row) ? $row : null;
}

/** Warna aksen email menurut jenis notifikasi (dibaca dari judulnya): blue | amber | green | red. */
function crfEmailTone(string $title): string
{
    $t = mb_strtolower($title);

    if (preg_match('/dibatalkan|terlewati|terlambat/u', $t)) {
        return 'red';
    }
    if (preg_match('/revisi|mendesak|pengingat/u', $t)) {
        return 'amber';
    }
    if (preg_match('/selesai|disetujui|finalisasi/u', $t)) {
        return 'green';
    }

    return 'blue';
}

/** Tanggal pengajuan: "3 November 2026, 14.20 WIB" (jam hanya bila tersimpan). */
function crfEmailDate(?string $value): string
{
    $time = $value ? strtotime($value) : false;
    if ($time === false) {
        return (string) $value;
    }

    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $text = date('j', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . date('Y', $time);

    return date('H:i', $time) !== '00:00' ? $text . ', ' . date('H.i', $time) . ' WIB' : $text;
}

/**
 * Isi email notifikasi. Tampilan: logo, kepala berwarna (aksen menurut jenis notifikasi), kartu ringkasan CRF
 * bila notifikasi terkait CRF, tombol "Buka CRF", dan kaki. Memakai tabel dan gaya inline supaya tampil sama di
 * Outlook, Gmail, dan klien email lain.
 *
 * @param bool       $withLogo true bila gambar tersemat dengan cid "crf-logo"
 * @param array|null $crf      baris dari crfEmailCrfData(); null = tanpa kartu ringkasan
 * @param string     $tone     blue | amber | green | red (lihat crfEmailTone())
 */
function crfEmailHtml(
    string $recipientName,
    string $title,
    string $message,
    string $link,
    bool $withLogo,
    ?array $crf = null,
    string $tone = 'blue'
): string {
    $tones = [
        'blue'  => ['#2f7fe0', '#cfe3fb', '#f1f6fd', '#dbe6f5', '#1f568f', '#e3eefb'],
        'amber' => ['#d97706', '#fde9c4', '#fff8eb', '#f8e3bf', '#8a5707', '#fff1d6'],
        'green' => ['#059669', '#d1fae5', '#effaf5', '#cdeedd', '#047857', '#dcf5e9'],
        'red'   => ['#dc2626', '#fee2e2', '#fef3f3', '#f6d4d4', '#b91c1c', '#fde3e3'],
    ];
    [$accent, $eyebrow, $soft, $line, $deep, $pillBg] = $tones[$tone] ?? $tones['blue'];

    $greeting = h(trim($recipientName) !== '' ? trim($recipientName) : 'Bapak/Ibu');
    $title = h($title);
    $message = nl2br(h($message));
    $font = 'font-family:Segoe UI, Arial, Helvetica, sans-serif;';

    $logo = $withLogo
        ? '<tr><td style="padding:20px 32px;border-bottom:1px solid #e7ecf3;' . $font . '">'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="padding-right:12px;"><img src="cid:crf-logo" alt="PT Persona Prima Utama" '
            . 'style="height:40px;width:auto;display:block;border:0;"></td>'
            . '<td style="font-size:13px;line-height:1.4;font-weight:600;color:#1f568f;letter-spacing:.4px;">'
            . 'PT PERSONA PRIMA UTAMA<br><span style="font-weight:400;color:#64748b;letter-spacing:0;">'
            . 'Sistem Change Request Form (CRF)</span></td>'
            . '</tr></table></td></tr>'
        : '';

    $card = '';
    if ($crf !== null) {
        $rows = [
            'Pemohon'   => (string) ($crf['nama_pemohon'] ?? ''),
            'Unit asal' => (string) ($crf['dept_asal'] ?? ''),
            'Kategori'  => (string) ($crf['kategori_perubahan'] ?? ''),
            'Urgensi'   => (string) ($crf['level'] ?? ''),
            'Diajukan'  => crfEmailDate($crf['tgl_pengajuan'] ?? null),
        ];
        $summary = trim((string) ($crf['deskripsi_perubahan'] ?? ''));
        if ($summary !== '') {
            $rows['Ringkasan'] = mb_strlen($summary) > 140 ? mb_substr($summary, 0, 140) . '...' : $summary;
        }

        $body = '';
        foreach ($rows as $label => $value) {
            if (trim($value) === '') {
                continue;
            }
            $body .= '<tr><td style="padding:11px 18px;width:34%;border-top:1px solid #e7ecf3;font-size:13px;'
                . 'color:#64748b;vertical-align:top;' . $font . '">' . h($label) . '</td>'
                . '<td style="padding:11px 18px;border-top:1px solid #e7ecf3;font-size:13px;font-weight:600;'
                . 'color:#1e293b;' . $font . '">' . h($value) . '</td></tr>';
        }

        $statusText = statusLabel((string) ($crf['status'] ?? ''));
        $card = '<tr><td style="padding:0 32px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="border:1px solid ' . $line . ';border-radius:10px;border-collapse:separate;overflow:hidden;">'
            . '<tr><td colspan="2" style="background-color:' . $soft . ';padding:12px 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="font-family:Consolas, Menlo, monospace;font-size:13px;font-weight:700;color:' . $deep . ';">'
            . h((string) ($crf['nomor_register'] ?? '')) . '</td>'
            . ($statusText !== ''
                ? '<td align="right"><span style="display:inline-block;background-color:' . $pillBg . ';color:' . $deep
                    . ';font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;' . $font . '">'
                    . h($statusText) . '</span></td>'
                : '')
            . '</tr></table></td></tr>'
            . $body
            . '</table></td></tr>';
    }

    $button = '';
    if ($link !== '') {
        $button = '<tr><td style="padding:26px 32px 10px;text-align:center;">'
            . '<a href="' . h($link) . '" style="background-color:' . $accent . ';color:#ffffff;font-size:14px;'
            . 'font-weight:600;text-decoration:none;padding:13px 36px;border-radius:8px;display:inline-block;'
            . $font . '">Buka CRF</a></td></tr>'
            . '<tr><td style="padding:6px 32px 28px;text-align:center;font-size:12px;line-height:1.6;color:#64748b;'
            . $font . '">Jika tombol tidak berfungsi, salin tautan ini ke peramban Anda:<br>'
            . '<a href="' . h($link) . '" style="color:' . $accent . ';word-break:break-all;">' . h($link) . '</a>'
            . '</td></tr>';
    }

    return '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $title . '</title></head>'
        . '<body style="margin:0;padding:0;background-color:#f4f5fb;">'
        // Teks pratinjau di daftar kotak masuk.
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $title . '</div>'
        . '<div style="background-color:#f4f5fb;padding:32px 16px;' . $font . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:560px;margin:0 auto;background-color:#ffffff;border:1px solid #dfe5ee;'
        . 'border-radius:12px;overflow:hidden;border-collapse:separate;">'
        . $logo
        // Kepala berwarna
        . '<tr><td style="background-color:' . $accent . ';padding:28px 32px;' . $font . '">'
        . '<div style="font-size:12px;letter-spacing:0.6px;color:' . $eyebrow . ';margin-bottom:8px;">NOTIFIKASI CRF</div>'
        . '<div style="font-size:22px;font-weight:600;line-height:1.35;color:#ffffff;">' . $title . '</div>'
        . '</td></tr>'
        // Sapaan dan pesan
        . '<tr><td style="padding:28px 32px 20px;' . $font . '">'
        . '<p style="margin:0 0 6px;font-size:14px;color:#64748b;">Yth. <strong style="color:#1e293b;">'
        . $greeting . '</strong>,</p>'
        . '<p style="margin:0;font-size:14px;line-height:1.7;color:#475569;">' . $message . '</p>'
        . '</td></tr>'
        . $card
        . $button
        // Kaki
        . '<tr><td style="background-color:' . $soft . ';border-top:1px solid ' . $line . ';padding:18px 32px;'
        . 'text-align:center;font-size:12px;line-height:1.7;color:#64748b;' . $font . '">'
        . 'Email ini dikirim otomatis oleh Sistem CRF, mohon tidak membalas.<br>'
        . '&copy; ' . date('Y') . ' Divisi Otomasi &mdash; PT Persona Prima Utama'
        . '</td></tr>'
        . '</table></div></body></html>';
}

function sendNotificationEmail(array $config, string $toEmail, string $toName, array $notification, ?array $crf = null): bool
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
        } else {
            // Tanpa enkripsi: jangan upgrade otomatis ke TLS (sama dengan 3rdparty/email_helper.php SIAP).
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom((string) $config['from_email'], (string) ($config['from_name'] ?? 'CRF'));
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = (string) $notification['judul'];

        $link = '';
        if (!empty($notification['url'])) {
            $appUrl = (string) ($config['app_url'] ?? '');
            if (crfLayoutIsModule()) {
                // Di SIAP, tautan email membuka halaman di layout SIAP: hanya skema+host app_url dipakai.
                $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $appUrl);
                $link = rtrim((string) $origin, '/') . crfUrlFromPath((string) $notification['url']);
            } else {
                $link = rtrim($appUrl, '/') . '/' . ltrim((string) $notification['url'], '/');
            }
        }

        $logoPath = __DIR__ . '/../assets/img/logo-header.png';
        $hasLogo = is_file($logoPath)
            && $mail->addEmbeddedImage($logoPath, 'crf-logo', 'logo-ppu.png', 'base64', 'image/png');

        $mail->Body = crfEmailHtml(
            $toName,
            (string) $notification['judul'],
            (string) $notification['pesan'],
            $link,
            $hasLogo,
            $crf,
            crfEmailTone((string) $notification['judul'])
        );
        $mail->AltBody = (string) $notification['pesan'] . ($link !== '' ? "\n\n" . $link : '');
        $mail->send();

        return true;
    } catch (Throwable $e) {
        error_log('sendNotificationEmail: ' . $e->getMessage());
        return false;
    }
}
