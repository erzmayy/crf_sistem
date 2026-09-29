<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * includes/functions.php
 * ---------------------------------------------------------------
 * Kumpulan fungsi bantu yang dipakai di beberapa halaman:
 * generator Nomor Register, format tanggal Indonesia, format Rupiah,
 * dan label untuk kategori/status/level.
 * ---------------------------------------------------------------
 */

/**
 * Membuat Nomor Register otomatis.
 *
 * Format mengikuti contoh pada dokumen CRF perusahaan:
 *     PPU-02.4.1234.07.26
 *
 * Dokumen perusahaan hanya memberi CONTOH format ini dan tidak
 * menjelaskan arti tiap segmen angka. Sesuai brief (butir 10), kita
 * TIDAK mengarang arti "02" dan "4" - keduanya dipertahankan persis
 * seperti contoh. Bagian yang dibuat dinamis (agar nomor unik) adalah:
 *   - 4 digit urut, diambil dari next AUTO_INCREMENT tabel change_requests
 *   - bulan & tahun pengajuan (2 digit)
 */
/**
 * Membuat Nomor Register BARU (menaikkan penghitung).
 *
 * Format: PPU-02.4.NNNN.MM.YY
 * Dipanggil hanya saat data benar-benar disimpan (save_draft.php
 * dan submit_crf.php). Untuk sekadar menampilkan pratinjau di form,
 * gunakan previewRequestNumber().
 *
 * Satu query UPDATE bersifat atomik: baris penghitung dikunci
 * sampai transaksi selesai, sehingga dua pengajuan bersamaan
 * tidak akan mendapat nomor yang sama.
 */
function generateRequestNumber(PDO $pdo, DateTime $date): string
{
    $year = $date->format('y');

    $ensureStmt = $pdo->prepare(
        'INSERT INTO crf_sequence (year, last_number)
         VALUES (:year, 0)
         ON DUPLICATE KEY UPDATE year = year'
    );
    $ensureStmt->execute(['year' => $year]);

    $stmt = $pdo->prepare(
        'UPDATE crf_sequence
         SET last_number = LAST_INSERT_ID(
             GREATEST(
                 last_number,
                 COALESCE((
                     SELECT MAX(
                         CAST(
                             SUBSTRING_INDEX(
                                 SUBSTRING_INDEX(request_number, ".", 3),
                                 ".",
                                 -1
                             ) AS UNSIGNED
                         )
                     )
                     FROM change_requests
                     WHERE request_number LIKE CONCAT("PPU-02.4.%.", :year1)
                 ), 0)
             ) + 1
         )
         WHERE year = :year2'
    );

    $stmt->execute([
        'year1' => $year,
        'year2' => $year,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException(
            'Penghitung nomor register belum tersedia (tabel crf_sequence).'
        );
    }

    $sequence = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    if ($sequence > 9999) {
        throw new RuntimeException(
            'Nomor register tahun ' . $year . ' sudah mencapai batas maksimum 9999.'
        );
    }

    return sprintf(
        'PPU-02.4.%04d.%s.%s',
        $sequence,
        $date->format('m'),
        $year
    );
}

function previewRequestNumber(PDO $pdo, DateTime $date): string
{
    $year = $date->format('y');

    $stmt = $pdo->prepare(
        'SELECT last_number FROM crf_sequence WHERE year = :year'
    );
    $stmt->execute(['year' => $year]);

    $lastNumber = (int) $stmt->fetchColumn();

    return sprintf(
        'PPU-02.4.%04d.%s.%s',
        $lastNumber + 1,
        $date->format('m'),
        $year
    );
}

/**
 * Format tanggal ke format Indonesia, contoh:
 *     Selasa, 08 September 2026
 */
function formatTanggalIndonesia(DateTime $date): string
{
    $hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];
    $bulan = [
        '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    $namaHari  = $hari[(int) $date->format('w')];
    $tanggal   = $date->format('d');
    $namaBulan = $bulan[(int) $date->format('n')];
    $tahun     = $date->format('Y');

    return "{$namaHari}, {$tanggal} {$namaBulan} {$tahun}";
}

/**
 * Format nominal ke Rupiah, contoh: Rp 15.000.000
 */
function formatRupiah($amount): string
{
    if ($amount === null || $amount === '') {
        return '-';
    }
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

/**
 * Label untuk pilihan Biaya / Anggaran (lihat brief butir 13).
 */
function budgetTypeLabel(?string $key): string
{
    switch ($key) {
        case 'rkap':
            return 'RKAP tahun berjalan';
        case 'boq_pks':
            return 'Tercantum dalam BoQ PKS';
        case 'anggaran_baru':
            return 'Akan diajukan anggaran baru';
        default:
            return '-';
    }
}

/**
 * Label tampilan Status. Solve/Cancel ditampilkan sebagai
 * "Selesai" / "Dibatalkan" 
 */
function statusLabel(string $status): string
{
    switch ($status) {
        case 'Belum Ditindak Lanjuti':
            return 'Menunggu Tindakan';
        case 'Dalam Proses':
            return 'Sedang Diproses';
        case 'Solve':
            return 'Selesai';
        case 'Cancel':
            return 'Dibatalkan';
        default:
            return $status; 
    }
}

/**
 * Aturan perpindahan status yang boleh dilakukan admin.
 * Kunci = status sekarang, nilai = status tujuan yang diizinkan.
 * Mau mengubah aturan? Cukup edit daftar di bawah ini.
 */
function statusTransitions(): array
{
    return [
        'Belum Ditindak Lanjuti' => ['Perlu Revisi', 'Dalam Proses', 'Cancel'],
        'Perlu Revisi'           => ['Cancel'],
        'Dalam Proses'           => ['Perlu Revisi', 'Solve', 'Cancel'],
        'Solve'                  => [],
        'Cancel'                 => [],
    ];
}

/**
 * Apakah perpindahan status dari $from ke $to diperbolehkan?
 * Status yang sama selalu boleh (hanya mengubah Level / Tanggapan).
 */
function canChangeStatus(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }

    $map = statusTransitions();

    return in_array($to, $map[$from] ?? [], true);
}

function statusBadgeClass(string $status): string
{
    switch ($status) {
        case 'Draft':
            return 'badge-status-draft';

        case 'Belum Ditindak Lanjuti':
            return 'badge-status-belum';

        case 'Perlu Revisi':
            return 'badge-status-revisi';

        case 'Dalam Proses':
            return 'badge-status-proses';

        case 'Solve':
            return 'badge-status-solve';

        case 'Cancel':
            return 'badge-status-cancel';

        default:
            return 'badge-status-belum';
    }
}


/**
 * Label tahap workflow CRF.
 */
function workflowStageLabel(string $stage): string
{
    switch ($stage) {
        case 'PEMOHON':
            return 'Menunggu Pemeriksaan';
        case 'CMO_FILTER':
            return 'Pemeriksaan CMO';
        case 'OTOMASI':
            return 'Proses Otomasi';
        case 'PEMOHON_PIR':
            return 'Review Hasil Perubahan';
        case 'kadep_operasional':
            return 'Menunggu Persetujuan Kepala Departemen';
        case 'CMO_FINAL':
            return 'Finalisasi CMO';
        case 'SELESAI':
            return 'Selesai';
        default:
            return $stage ?: '-';
    }
}

function workflowStageBadgeClass(string $stage): string
{
    switch ($stage) {
        case 'CMO_FILTER':
            return 'badge-stage-cmo';
        case 'OTOMASI':
            return 'badge-stage-otomasi';
        case 'PEMOHON_PIR':
            return 'badge-stage-pir';
        case 'kadep_operasional':
            return 'badge-stage-joko';
        case 'CMO_FINAL':
            return 'badge-stage-cmo-final';
        case 'SELESAI':
            return 'badge-stage-selesai';
        case 'PEMOHON':
        default:
            return 'badge-stage-pemohon';
    }
}

function slaDueAt(?string $startedAt, $value, ?string $unit): ?string
{
    if (empty($startedAt) || $value === null || $value === '' || empty($unit)) {
        return null;
    }

    try {
        $date = new DateTime($startedAt);
        $numeric = (float) $value;

        if ($numeric <= 0) {
            return null;
        }

        switch ($unit) {
            case 'Menit':
                $seconds = (int) round($numeric * 60);
                break;
            case 'Jam':
                $seconds = (int) round($numeric * 3600);
                break;
            case 'Hari':
                $seconds = (int) round($numeric * 86400);
                break;
            default:
                return null;
        }

        $date->modify('+' . $seconds . ' seconds');
        return $date->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function logCrfActivity(PDO $pdo, int $crfId, string $activity, string $description, string $actor): void
{
    $stmt = $pdo->prepare("
        INSERT INTO crf_activity_logs (
            change_request_id,
            activity,
            description,
            actor
        ) VALUES (
            :change_request_id,
            :activity,
            :description,
            :actor
        )
    ");

    $stmt->execute([
        'change_request_id' => $crfId,
        'activity' => $activity,
        'description' => $description,
        'actor' => $actor,
    ]);
}

function levelBadgeClass(?string $level): string
{
    switch ($level) {
        case 'Tinggi':
            return 'badge-level-tinggi';

        case 'Normal':
            return 'badge-level-sedang';

        case 'Rendah':
            return 'badge-level-kecil';

        default:
            return 'badge-level-none';
    }
}

/**
 * Konfigurasi upload file (lihat brief butir 12).
 */
const CRF_ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const CRF_ALLOWED_MIME_TYPES = [
    'application/pdf',
    'image/jpeg',
    'image/png',
];

const CRF_MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB per file

/**
 * Memproses upload lampiran (Bukti dan Informasi Pendukung).
 * Melakukan validasi extension, MIME type, dan ukuran file sebelum
 * memindahkan file ke folder uploads/ dan mencatatnya ke tabel attachments.
 *
 * @return string[] daftar pesan error (kosong jika semua berhasil / tidak ada file)
 */
function handleAttachmentUploads(PDO $pdo, int $crfId, array $filesInput): array
{
    $errors = [];

    if (empty($filesInput['name']) || empty($filesInput['name'][0])) {
        return $errors;
    }

    // Ambil konfigurasi Wasabi
    $wasabiConfig = require __DIR__ . '/../config/wasabi.php';

    // Inisialisasi S3 Client
    $s3Client = new S3Client([
        'version' => $wasabiConfig['version'],
        'region' => $wasabiConfig['region'],
        'endpoint' => $wasabiConfig['endpoint'],
        'credentials' => $wasabiConfig['credentials'],
        'use_path_style_endpoint' => $wasabiConfig['use_path_style_endpoint'],
    ]);

    $bucket = $wasabiConfig['bucket'];
    $uploadPath = rtrim($wasabiConfig['upload_path'], '/') . '/';

    $total = count($filesInput['name']);

    for ($i = 0; $i < $total; $i++) {

        if ($filesInput['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($filesInput['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = 'Gagal mengupload file "' . $filesInput['name'][$i] . '".';
            continue;
        }

        $originalName = basename($filesInput['name'][$i]);
        $tmpPath = $filesInput['tmp_name'][$i];
        $size = (int) $filesInput['size'][$i];

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Validasi extension
        if (!in_array($ext, CRF_ALLOWED_EXTENSIONS, true)) {
            $errors[] = 'Jenis file "' . $originalName . '" tidak diizinkan.';
            continue;
        }

        // Validasi ukuran
        if ($size > CRF_MAX_FILE_SIZE) {
            $errors[] = 'File "' . $originalName . '" melebihi batas ukuran 5 MB.';
            continue;
        }

        // Validasi MIME type
        $mimeType = function_exists('mime_content_type')
            ? mime_content_type($tmpPath)
            : null;

        if (
            $mimeType !== null &&
            $mimeType !== false &&
            !in_array($mimeType, CRF_ALLOWED_MIME_TYPES, true)
        ) {
            $errors[] = 'Format file "' . $originalName . '" tidak valid.';
            continue;
        }

        // Buat nama file unik
        $storedName = uniqid('crf_' . $crfId . '_', true) . '.' . $ext;

        // Path file di Wasabi
        $key = $uploadPath . $storedName;

        try {

            // Upload file ke Wasabi
            $result = $s3Client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'SourceFile' => $tmpPath,
                'ACL' => 'public-read',
                'ContentType' => $mimeType ?: 'application/octet-stream',
            ]);

            // URL file di Wasabi
            $fileUrl = $result['ObjectURL'];

            // Simpan informasi file ke database
            $stmt = $pdo->prepare(
                'INSERT INTO attachments
                (
                    change_request_id,
                    original_name,
                    stored_name,
                    file_path,
                    file_type,
                    file_size
                )
                VALUES
                (
                    :crf_id,
                    :original_name,
                    :stored_name,
                    :file_path,
                    :file_type,
                    :file_size
                )'
            );

            $stmt->execute([
                'crf_id' => $crfId,
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'file_path' => $fileUrl,
                'file_type' => $mimeType ?: null,
                'file_size' => $size,
            ]);

        } catch (AwsException $e) {

            $errors[] =
                'Gagal mengupload file "' .
                $originalName .
                '" ke Wasabi: ' .
                $e->getMessage();

            continue;
        }
    }

    return $errors;
}

/**
 * Daftar kategori perubahan beserta contoh placeholder untuk field
 * "Detail Kategori" (lihat brief butir 14).
 */
function categoryPlaceholder(string $category): string
{
    switch ($category) {
        case 'Aplikasi':
            return 'Contoh: Modul CL / PKS / PKWT / Absensi';
        case 'Infrastruktur':
            return 'Contoh: Jaringan Lokal (LAN) / Jaringan Internet Publik (WAN)';
        case 'Proses':
            return 'Contoh: Modul Payroll - perubahan proses pembuatan Payroll';
        case 'Security':
            return 'Contoh: Perubahan Kewenangan Menu / User ID / Password';
        case 'Lainnya':
            return 'Jelaskan kategori perubahan yang dimaksud';
        default:
            return '';
    }
}