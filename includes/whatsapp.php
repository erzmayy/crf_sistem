<?php
/**
 * includes/whatsapp.php
 * ---------------------------------------------------------------
 * Notifikasi WhatsApp CRF lewat ANTREAN WA SIAP (tabel tbl_wa_send).
 *
 * CRF tidak memanggil API WhatsApp dan tidak menyimpan token sendiri. Seperti
 * Help Desk, CRF hanya menambah baris ke tbl_wa_send dengan status_kirim = '3'
 * (antre); pengirim WA SIAP yang sudah ada yang mengirimnya ke WhatsApp.
 *
 * Alur (sama dengan email di includes/notifications.php):
 *   1. notifyUsers(..., $whatsapp = true) menyimpan notifikasi dengan status_wa = 'pending'
 *      (ikut transaksi: ikut di-rollback bila proses gagal).
 *   2. Setelah commit, dispatchPendingNotificationEmails() memanggil
 *      dispatchPendingNotificationWhatsapp() yang memasukkan pesan ke antrean.
 *      Kegagalan WA tidak pernah menggagalkan proses utama.
 *
 * Diatur dari config/siap.php (semuanya opsional, default WA MATI):
 *   CRF_WA_ENABLED        true  = kirim; false = notifikasi 'pending' ditandai 'skipped'
 *   CRF_WA_TEST_USERIDS   bila diisi, hanya userid ini yang dikirimi WA (untuk uji)
 *   CRF_WA_APP_URL        alamat SIAP untuk tautan di pesan
 *   CRF_WA_QUEUE_TABLE    nama tabel antrean (default tbl_wa_send)
 * Perlu migrasi 027_wa_notifikasi_migration.sql (kolom crf_notifikasi.status_wa).
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../config/siap.php';
require_once __DIR__ . '/siap_user_provider.php';
require_once __DIR__ . '/crf_url.php';

function crfWaEnabled(): bool
{
    return defined('CRF_WA_ENABLED') && CRF_WA_ENABLED === true;
}

/** @return string[] */
function crfWaTestUserIds(): array
{
    return defined('CRF_WA_TEST_USERIDS') ? array_map('strval', (array) CRF_WA_TEST_USERIDS) : [];
}

/** Nama tabel antrean WA yang sudah di-quote (boleh "database.tabel"). */
function crfWaQueueTable(): string
{
    $name = defined('CRF_WA_QUEUE_TABLE') ? (string) CRF_WA_QUEUE_TABLE : 'tbl_wa_send';
    if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $name)) {
        $name = 'tbl_wa_send';
    }

    return implode('.', array_map('quoteMysqlIdentifier', explode('.', $name)));
}

/** True bila kolom crf_notifikasi.status_wa ada (migrasi 027 sudah dijalankan). */
function crfNotifikasiHasWaColumn(PDO $pdo): bool
{
    static $has = null;

    if ($has === null) {
        try {
            $has = (bool) $pdo->query("SHOW COLUMNS FROM crf_notifikasi LIKE 'status_wa'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }

    return $has;
}

/** Nomor HP bentuk 08xxxxxxxx seperti di tbl_user.no_wa SIAP; kosong bila tidak valid. */
function crfWaNormalizePhone(?string $number): string
{
    $digits = preg_replace('/\D+/', '', (string) $number);
    if ($digits === '') {
        return '';
    }
    if (strncmp($digits, '62', 2) === 0) {
        $digits = '0' . substr($digits, 2);
    } elseif ($digits[0] !== '0') {
        $digits = '0' . $digits;
    }

    return strlen($digits) >= 9 && strlen($digits) <= 15 ? $digits : '';
}

/** Tautan absolut ke halaman CRF di SIAP dari path internal notifikasi ('crf/open.php?id=5'). */
function crfWaLink(string $url): string
{
    $origin = rtrim(defined('CRF_WA_APP_URL') ? (string) CRF_WA_APP_URL : 'https://siap.ptppu.co.id', '/');

    return $url === '' ? $origin : $origin . crfUrlFromPath($url);
}

/**
 * Isi pesan WhatsApp (tebal dengan tanda bintang). Susunan minimalis:
 *   *Judul*
 *   Nomor CRF
 *
 *   Satu kalimat inti
 *
 *   Pemohon  : Nama (Unit)      <- hanya untuk penerima selain pemohon
 *   Kategori : Aplikasi · Tinggi
 *
 *   tautan
 *
 * $ctx: pemohon, unit, kategori, urgensi (semua opsional). Nomor CRF diambil dari judul.
 */
function crfWaMessage(string $title, string $message, string $link, array $ctx = []): string
{
    $title = trim($title);
    $message = trim(strip_tags($message));

    // Nomor dipindah ke baris sendiri; di judul dibuang, di kalimat inti diganti "CRF ini".
    $number = '';
    if (preg_match('/PPU-[^\s,;:)]+/', $title . ' ' . $message, $m)) {
        $number = rtrim($m[0], '.');
        $title = trim(str_replace($number, '', $title));
        $title = trim(preg_replace('/\s+/', ' ', $title), " :-");
        $quoted = preg_quote($number, '/');
        $message = preg_replace('/\bCRF\s+' . $quoted . '/u', 'CRF ini', $message);
        $message = str_replace($number, 'CRF ini', $message);
    }
    $message = trim(preg_replace('/[ \t]+/', ' ', $message));
    if (mb_strlen($message) > 200) {
        $message = rtrim(mb_substr($message, 0, 197)) . '...';
    }

    $lines = ['*' . $title . '*'];
    if ($number !== '') {
        $lines[] = $number;
    }
    $lines[] = '';
    $lines[] = $message;

    $pemohon = trim((string) ($ctx['pemohon'] ?? ''));
    $unit = trim((string) ($ctx['unit'] ?? ''));
    $kategori = trim((string) ($ctx['kategori'] ?? ''));
    $urgensi = trim((string) ($ctx['urgensi'] ?? ''));
    $meta = [];
    if ($pemohon !== '') {
        $meta[] = 'Pemohon  : ' . $pemohon . ($unit !== '' ? ' (' . $unit . ')' : '');
    }
    if ($kategori !== '') {
        $meta[] = 'Kategori : ' . $kategori . ($urgensi !== '' ? ' · ' . $urgensi : '');
    }
    if ($meta) {
        $lines[] = '';
        array_push($lines, ...$meta);
    }

    if ($link !== '') {
        $lines[] = '';
        $lines[] = $link;
    }

    return implode("\n", $lines);
}

/** Tambah satu baris ke antrean tbl_wa_send (status '3' = antre). Tidak melempar exception. */
function crfWaEnqueue(PDO $pdo, string $phone, string $name, string $nik, string $subject, string $text): bool
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . crfWaQueueTable() . ' (tgl_kirim, nik, nama, no_hp, perihal, kode, isi, status_kirim)
             VALUES (CURDATE(), :nik, :nama, :no_hp, :perihal, :kode, :isi, \'3\')'
        );

        return $stmt->execute([
            'nik'     => mb_substr($nik, 0, 20),
            'nama'    => mb_substr($name, 0, 60),
            'no_hp'   => $phone,
            'perihal' => $subject,
            'kode'    => $subject,
            'isi'     => $text,
        ]);
    } catch (Throwable $e) {
        error_log('crfWaEnqueue: ' . $e->getMessage());

        return false;
    }
}

/**
 * Masukkan notifikasi berstatus_wa 'pending' ke antrean WA. Status akhir per notifikasi:
 *   sent    = masuk antrean tbl_wa_send
 *   skipped = WA mati, bukan userid uji, nomor kosong/tidak valid, atau duplikat dalam satu putaran
 *   failed  = gagal menulis ke antrean
 */
function dispatchPendingNotificationWhatsapp(PDO $pdo, int $limit = 50): void
{
    try {
        if (!crfNotifikasiHasWaColumn($pdo)) {
            return;
        }

        if (!crfWaEnabled()) {
            $pdo->exec("UPDATE crf_notifikasi SET status_wa = 'skipped' WHERE status_wa = 'pending'");
            return;
        }

        $limit = max(1, min(200, $limit));
        $userTable = crfUserTable();
        $rows = $pdo->query("
            SELECT n.id, n.pengguna_id, n.judul, n.pesan, n.url, n.pengajuan_id,
                   cr.pengguna_id AS pemilik_id, cr.nama_pemohon, cr.dept_asal, cr.kategori_perubahan,
                   COALESCE(cr.level_urgensi_final, cr.level) AS urgensi,
                   u.userid AS recipient_userid, u.nama AS recipient_name, u.no_wa AS recipient_wa
            FROM crf_notifikasi n
            LEFT JOIN {$userTable} u ON u.id = n.pengguna_id
            LEFT JOIN crf_pengajuan cr ON cr.id = n.pengajuan_id
            WHERE n.status_wa = 'pending'
            ORDER BY n.id
            LIMIT {$limit}
        ")->fetchAll();

        $update = $pdo->prepare('UPDATE crf_notifikasi SET status_wa = :status WHERE id = :id');
        $testUserIds = crfWaTestUserIds();
        $seen = [];

        foreach ($rows as $row) {
            $userid = (string) ($row['recipient_userid'] ?? '');
            $phone = crfWaNormalizePhone($row['recipient_wa'] ?? null);
            $key = $phone . '|' . ($row['pengajuan_id'] ?? '') . '|' . $row['judul'];

            if ($phone === '' || ($testUserIds && !in_array($userid, $testUserIds, true)) || isset($seen[$key])) {
                $update->execute(['status' => 'skipped', 'id' => $row['id']]);
                continue;
            }
            $seen[$key] = true;

            $message = (string) ($row['pesan'] ?? '');
            // Pemohon tidak melihat informasi SLA (sama dengan notifikasi di aplikasi).
            $isOwner = !empty($row['pemilik_id']) && (int) $row['pemilik_id'] === (int) $row['pengguna_id'];
            if ($isOwner && function_exists('crfStripSlaText')) {
                $message = crfStripSlaText($message);
            }

            // Ringkasan pemohon dan kategori hanya untuk penerima selain pemohon sendiri.
            $ctx = [];
            if (!$isOwner && !empty($row['pengajuan_id'])) {
                $ctx = [
                    'pemohon'  => (string) ($row['nama_pemohon'] ?? ''),
                    'unit'     => (string) ($row['dept_asal'] ?? ''),
                    'kategori' => (string) ($row['kategori_perubahan'] ?? ''),
                    'urgensi'  => (string) ($row['urgensi'] ?? ''),
                ];
            }

            $text = crfWaMessage((string) $row['judul'], $message, crfWaLink((string) ($row['url'] ?? '')), $ctx);
            $ok = crfWaEnqueue($pdo, $phone, (string) ($row['recipient_name'] ?? ''), $userid, 'CRF', $text);
            $update->execute(['status' => $ok ? 'sent' : 'failed', 'id' => $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('dispatchPendingNotificationWhatsapp: ' . $e->getMessage());
    }
}
