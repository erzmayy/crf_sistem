<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/../config/sla.php';
require_once __DIR__ . '/crf_url.php'; // crfUrl()/crfRedirect() dipakai actions yang hanya memuat session.php + functions.php

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
 *   - 4 digit urut, diambil dari next AUTO_INCREMENT tabel crf_pengajuan
 *   - bulan & tahun pengajuan (2 digit)
 */
/**
 * Membuat Nomor Register BARU (menaikkan penghitung).
 *
 * Format: PPU-<kode KPU>.4.NNNN.MM.YY (kode dari akun pemohon, lihat crfRegisterCode()).
 * Urutan NNNN satu penghitung bersama per tahun untuk semua KPU.
 * Dipanggil hanya saat data benar-benar disimpan (save_draft.php
 * dan submit_crf.php). Untuk sekadar menampilkan pratinjau di form,
 * gunakan previewRequestNumber().
 *
 * Satu query UPDATE bersifat atomik: baris penghitung dikunci
 * sampai transaksi selesai, sehingga dua pengajuan bersamaan
 * tidak akan mendapat nomor yang sama.
 */
/**
 * Kode KPU untuk awalan Nomor Register: kpu_kode akun SIAP pemohon,
 * hanya huruf/angka (maks. 3 karakter). Kosong = kode cadangan (config/siap.php).
 */
function crfRegisterCode(?array $user): string
{
    $code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($user['kpu_kode'] ?? ''));
    $code = substr((string) $code, 0, 3);

    if ($code === '') {
        $code = defined('CRF_REGISTER_DEFAULT_CODE') ? (string) CRF_REGISTER_DEFAULT_CODE : '02';
    }

    return $code;
}

function generateRequestNumber(PDO $pdo, DateTime $date, ?array $user = null): string
{
    $year = $date->format('y');

    $ensureStmt = $pdo->prepare(
        'INSERT INTO crf_nomor_urut (tahun, nomor_terakhir)
         VALUES (:tahun, 0)
         ON DUPLICATE KEY UPDATE tahun = tahun'
    );
    $ensureStmt->execute(['tahun' => $year]);

    $stmt = $pdo->prepare(
        'UPDATE crf_nomor_urut
         SET nomor_terakhir = LAST_INSERT_ID(
             GREATEST(
                 nomor_terakhir,
                 COALESCE((
                     SELECT MAX(
                         CAST(
                             SUBSTRING_INDEX(
                                 SUBSTRING_INDEX(nomor_register, ".", 3),
                                 ".",
                                 -1
                             ) AS UNSIGNED
                         )
                     )
                     FROM crf_pengajuan
                     WHERE nomor_register LIKE CONCAT("PPU-%.4.%.", :year1)
                 ), 0)
             ) + 1
         )
         WHERE tahun = :year2'
    );

    $stmt->execute([
        'year1' => $year,
        'year2' => $year,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException(
            'Penghitung nomor register belum tersedia (tabel crf_nomor_urut).'
        );
    }

    $sequence = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    if ($sequence > 9999) {
        throw new RuntimeException(
            'Nomor register tahun ' . $year . ' sudah mencapai batas maksimum 9999.'
        );
    }

    return sprintf(
        'PPU-%s.4.%04d.%s.%s',
        crfRegisterCode($user),
        $sequence,
        $date->format('m'),
        $year
    );
}

function previewRequestNumber(PDO $pdo, DateTime $date, ?array $user = null): string
{
    $year = $date->format('y');

    $stmt = $pdo->prepare(
        'SELECT nomor_terakhir FROM crf_nomor_urut WHERE tahun = :tahun'
    );
    $stmt->execute(['tahun' => $year]);

    $lastNumber = (int) $stmt->fetchColumn();

    return sprintf(
        'PPU-%s.4.%04d.%s.%s',
        crfRegisterCode($user),
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
            // Kosong / tidak_ada = perubahan tanpa biaya.
            return 'Tidak ada biaya';
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
            return 'Perbaikan oleh Pemohon';
        case 'CMO_FILTER':
            return 'Verifikasi CMO';
        case 'OTOMASI':
            return 'Tindak Lanjut Otomasi';
        case 'UAT':
            return 'UAT oleh CMO';
        case 'PEMOHON_PIR':
            return 'Menunggu PIR Pemohon';
        case 'kadep_operasional':
            return 'Persetujuan Kepala Departemen Operasional';
        case 'CMO_FINAL':
            return 'Finalisasi CMO';
        case 'SELESAI':
            return 'Selesai';
        default:
            return $stage ?: '-';
    }
}

/**
 * Fase pekerjaan Otomasi pada tahap OTOMASI (tahap yang sama dipakai tiga kali):
 *   eksekusi     : belum menyelesaikan eksekusi (SLA berjalan)
 *   perbaikan    : CMO meminta perbaikan setelah UAT (SLA sudah berhenti, tidak dihitung ulang)
 *   implementasi : UAT lulus, Otomasi mengisi tanggal dan hasil implementasi
 * Mengembalikan '' bila CRF tidak sedang di tahap OTOMASI.
 */
function crfOtomasiPhase(array $crf): string
{
    if (($crf['tahap_alur'] ?? '') !== 'OTOMASI') {
        return '';
    }
    if (empty($crf['otomasi_selesai'])) {
        return 'eksekusi';
    }

    return empty($crf['tgl_uat_lulus']) ? 'perbaikan' : 'implementasi';
}

function workflowStageBadgeClass(string $stage): string
{
    switch ($stage) {
        case 'CMO_FILTER':
            return 'badge-stage-cmo';
        case 'OTOMASI':
            return 'badge-stage-otomasi';
        case 'UAT':
            return 'badge-stage-cmo';
        case 'PEMOHON_PIR':
            return 'badge-stage-pir';
        case 'kadep_operasional':
            return 'badge-stage-kadep';
        case 'CMO_FINAL':
            return 'badge-stage-cmo-final';
        case 'SELESAI':
            return 'badge-stage-selesai';
        case 'PEMOHON':
        default:
            return 'badge-stage-pemohon';
    }
}

/**
 * Durasi target SLA dalam detik kerja: Menit, Jam, atau Hari (1 Hari = satu hari kerja penuh).
 * Dipakai untuk membandingkan target, bukan untuk menghitung tenggat (lihat slaDueAt()).
 */
function crfSlaSeconds(float $value, string $unit): int
{
    switch ($unit) {
        case 'Menit':
            return (int) round($value * 60);
        case 'Jam':
            return (int) round($value * 3600);
        case 'Hari':
            return (int) round($value * CRF_SLA_WORK_SECONDS_PER_DAY);
        default:
            return 0;
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
                $seconds = (int) round($numeric * CRF_SLA_WORK_SECONDS_PER_DAY);
                break;
            default:
                return null;
        }

        return slaAddWorkingSeconds($date, $seconds)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Hari kerja SLA: Senin-Jumat dan bukan tanggal libur (config/sla.php).
 */
function slaIsWorkingDay(DateTimeInterface $date): bool
{
    return (int) $date->format('N') <= 5
        && !in_array($date->format('Y-m-d'), CRF_SLA_HOLIDAYS, true);
}

/**
 * Awal / akhir jam kerja SLA pada tanggal $date (config/sla.php).
 *
 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
 */
function slaWorkWindow(DateTimeInterface $date): array
{
    $midnight = DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);

    return [
        $midnight->modify('+' . CRF_SLA_WORK_START . ' seconds'),
        $midnight->modify('+' . CRF_SLA_WORK_END . ' seconds'),
    ];
}

/**
 * Tambah durasi SLA yang hanya berjalan di hari dan jam kerja.
 * Contoh (08:00-17:00): Jumat 16:00 + 1 Hari = Senin 16:00.
 */
function slaAddWorkingSeconds(DateTimeInterface $start, int $seconds): DateTimeImmutable
{
    $cursor = DateTimeImmutable::createFromInterface($start);

    while (true) {
        [$workStart, $workEnd] = slaWorkWindow($cursor);

        if (!slaIsWorkingDay($cursor) || $cursor >= $workEnd) {
            $cursor = $cursor->modify('tomorrow');
            continue;
        }

        if ($cursor < $workStart) {
            $cursor = $workStart;
        }

        $available = $workEnd->getTimestamp() - $cursor->getTimestamp();

        if ($seconds <= $available) {
            return $cursor->modify('+' . $seconds . ' seconds');
        }

        $seconds -= $available;
        $cursor = $cursor->modify('tomorrow');
    }
}

/**
 * Durasi (detik) di antara dua waktu yang jatuh pada hari dan jam kerja saja.
 */
function slaWorkingSecondsBetween(DateTimeInterface $start, DateTimeInterface $end): int
{
    $cursor = DateTimeImmutable::createFromInterface($start);
    $end = DateTimeImmutable::createFromInterface($end);
    $total = 0;

    while ($cursor < $end) {
        $dayEnd = $cursor->modify('tomorrow');

        if (slaIsWorkingDay($cursor)) {
            [$workStart, $workEnd] = slaWorkWindow($cursor);
            $from = $cursor > $workStart ? $cursor : $workStart;
            $to = $end < $workEnd ? $end : $workEnd;

            if ($to > $from) {
                $total += $to->getTimestamp() - $from->getTimestamp();
            }
        }

        $cursor = $dayEnd;
    }

    return $total;
}

/**
 * Format durasi jam kerja: 1 Hari = 1 hari kerja (CRF_SLA_WORK_SECONDS_PER_DAY).
 */
function formatSlaDuration(int $seconds): string
{
    $minutes = max(0, intdiv($seconds, 60));
    $dayMinutes = intdiv(CRF_SLA_WORK_SECONDS_PER_DAY, 60);
    $days = intdiv($minutes, $dayMinutes);
    $hours = intdiv($minutes % $dayMinutes, 60);
    $remainingMinutes = $minutes % 60;
    $parts = [];

    if ($days > 0) {
        $parts[] = $days . ' Hari';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' Jam';
    }
    if ($remainingMinutes > 0 || !$parts) {
        $parts[] = $remainingMinutes . ' Menit';
    }

    return implode(' ', $parts);
}

/**
 * Ambang "mendesak": sisa waktu SLA (detik kerja) yang lebih kecil antara
 * 25% total SLA dan 1 jam. Dipakai badge SLA dan peringatan ke PIC CRF.
 */
function crfSlaUrgentSeconds(int $totalSeconds): int
{
    return (int) min(3600, floor($totalSeconds * 0.25));
}

/**
 * Durasi jam kerja versi singkat untuk ruang sempit (daftar): "1 hr 8 j", "2 j 6 m".
 * Maksimal dua satuan; 1 hr = 1 hari kerja (CRF_SLA_WORK_SECONDS_PER_DAY).
 */
function formatSlaDurationShort(int $seconds): string
{
    $minutes = max(0, intdiv($seconds, 60));
    $dayMinutes = intdiv(CRF_SLA_WORK_SECONDS_PER_DAY, 60);
    $parts = [];

    if (($days = intdiv($minutes, $dayMinutes)) > 0) {
        $parts[] = $days . ' hr';
    }
    if (($hours = intdiv($minutes % $dayMinutes, 60)) > 0) {
        $parts[] = $hours . ' j';
    }
    if (($mins = $minutes % 60) > 0 || !$parts) {
        $parts[] = $mins . ' m';
    }

    return implode(' ', array_slice($parts, 0, 2));
}

/**
 * Lama CRF menunggu di tahap yang dipegang CMO atau Kadep Operasional (verifikasi CMO, persetujuan Kadep,
 * UAT, finalisasi). Hanya informasi, tanpa target: supaya CRF yang tertahan terlihat di daftar.
 * Waktu masuk tahap diambil dari log aktivitas terbaru yang menetapkan status tahap itu
 * (cadangan: tgl_diubah). Durasi dalam jam kerja, sama dengan SLA.
 *
 * @param  array<int,array> $rows baris CRF (butuh id, status, tahap_alur, pembahasan_forum_terbuka, tgl_diubah)
 * @return array<int,array{since:string,short:string,seconds:int}> diindeks id CRF
 */
function crfWaitingInfo(PDO $pdo, array $rows, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $stageStatuses = [
        'CMO_FILTER' => 'Menunggu Verifikasi',
        'kadep_operasional' => 'Menunggu Persetujuan',
        'UAT' => 'Menunggu UAT',
        'CMO_FINAL' => 'Menunggu Finalisasi',
    ];

    $candidates = [];
    foreach ($rows as $row) {
        if (
            isset($stageStatuses[$row['tahap_alur'] ?? ''])
            && in_array($row['status'] ?? '', ['Belum Ditindak Lanjuti', 'Dalam Proses'], true)
            && empty($row['pembahasan_forum_terbuka'])
        ) {
            $candidates[(int) $row['id']] = $row;
        }
    }
    if (!$candidates) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $stmt = $pdo->prepare("
        SELECT pengajuan_id, status_baru, MAX(tgl_dibuat) AS tgl
        FROM crf_log_aktivitas
        WHERE pengajuan_id IN ({$placeholders})
          AND (status_baru IN ('Menunggu Verifikasi', 'Menunggu Persetujuan', 'Menunggu UAT', 'Menunggu Finalisasi')
               OR status_baru LIKE 'Pembahasan Forum selesai%')
        GROUP BY pengajuan_id, status_baru
    ");
    $stmt->execute(array_keys($candidates));
    $logged = [];
    foreach ($stmt->fetchAll() as $log) {
        $logged[(int) $log['pengajuan_id']][] = $log;
    }

    $result = [];
    foreach ($candidates as $id => $row) {
        $stageStatus = $stageStatuses[$row['tahap_alur']];
        $since = null;
        foreach ($logged[$id] ?? [] as $log) {
            // Setelah Forum memutuskan, CRF kembali ke CMO: hitung dari keputusan itu.
            $matches = $log['status_baru'] === $stageStatus
                || ($row['tahap_alur'] === 'CMO_FILTER' && strpos((string) $log['status_baru'], 'Pembahasan Forum selesai') === 0);
            if ($matches && ($since === null || $log['tgl'] > $since)) {
                $since = $log['tgl'];
            }
        }
        $since = $since ?? ($row['tgl_diubah'] ?? null);
        if (empty($since)) {
            continue;
        }

        $seconds = slaWorkingSecondsBetween(new DateTimeImmutable($since), $now);
        $result[$id] = [
            'since' => date('d-m-Y H:i', strtotime($since)),
            'short' => $seconds < 60 ? 'baru saja' : formatSlaDurationShort($seconds),
            'seconds' => $seconds,
        ];
    }

    return $result;
}

function getCrfSlaStatus(array $crf, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $startedAt = !empty($crf['sla_mulai'])
        ? new DateTimeImmutable($crf['sla_mulai'])
        : null;
    $dueAt = !empty($crf['sla_tenggat'])
        ? new DateTimeImmutable($crf['sla_tenggat'])
        : null;
    $completedAt = !empty($crf['otomasi_selesai'])
        ? new DateTimeImmutable($crf['otomasi_selesai'])
        : null;
    $isCancelled = ($crf['status'] ?? '') === 'Cancel';

    if ($isCancelled) {
        return [
            'label' => 'Tidak berlaku',
            'detail' => 'CRF dibatalkan.',
            'class' => 'secondary',
            'alert' => null,
            'elapsed' => null,
            'remaining' => null,
            'overdue' => null,
            'live' => false,
        ];
    }

    if ($completedAt !== null && $startedAt !== null && $dueAt !== null) {
        $elapsed = slaWorkingSecondsBetween($startedAt, $completedAt);
        if ($completedAt <= $dueAt) {
            $remaining = slaWorkingSecondsBetween($completedAt, $dueAt);
            return [
                'label' => 'Selesai sebelum SLA',
                'detail' => 'Lebih cepat ' . formatSlaDuration($remaining) . '.',
                'class' => 'success',
                'alert' => null,
                'elapsed' => formatSlaDuration($elapsed),
                'remaining' => null,
                'overdue' => null,
                'live' => false,
            ];
        }

        $overdue = slaWorkingSecondsBetween($dueAt, $completedAt);
        return [
            'label' => 'Terlambat',
            'detail' => 'Melewati batas SLA ' . formatSlaDuration($overdue) . '.',
            'class' => 'danger',
            'alert' => null,
            'elapsed' => formatSlaDuration($elapsed),
            'remaining' => null,
            'overdue' => null,
            'live' => false,
        ];
    }

    if ($completedAt !== null) {
        return [
            'label' => 'Selesai',
            'detail' => 'Data waktu mulai atau batas SLA tidak tersedia.',
            'class' => 'secondary',
            'alert' => null,
            'elapsed' => $startedAt !== null
                ? formatSlaDuration(slaWorkingSecondsBetween($startedAt, $completedAt))
                : null,
            'remaining' => null,
            'overdue' => null,
            'live' => false,
        ];
    }

    if ($startedAt === null || $dueAt === null) {
        return [
            'label' => 'Belum dimulai',
            'detail' => 'Resolution Time dimulai setelah persetujuan Kepala Departemen Operasional.',
            'class' => 'secondary',
            'alert' => null,
            'elapsed' => null,
            'remaining' => null,
            'overdue' => null,
            'live' => false,
        ];
    }

    // Sisa waktu / keterlambatan dihitung dalam jam kerja, sama dengan batas SLA.
    $remaining = $now <= $dueAt
        ? slaWorkingSecondsBetween($now, $dueAt)
        : -slaWorkingSecondsBetween($dueAt, $now);
    if ($now <= $dueAt) {
        $isApproaching = $remaining <= crfSlaUrgentSeconds(slaWorkingSecondsBetween($startedAt, $dueAt));
        return [
            'label' => 'Masih dalam SLA',
            'detail' => 'Sisa waktu ' . formatSlaDuration($remaining) . '.',
            'class' => $isApproaching ? 'warning' : 'success',
            'alert' => $isApproaching ? 'approaching' : null,
            'elapsed' => null,
            'remaining' => $remaining,
            'overdue' => null,
            'live' => true,
        ];
    }

    $overdue = abs($remaining);
    return [
        'label' => 'Melewati SLA',
        'detail' => 'Terlambat ' . formatSlaDuration($overdue) . '.',
        'class' => 'danger',
        'alert' => 'overdue',
        'elapsed' => null,
        'remaining' => null,
        'overdue' => $overdue,
        'live' => true,
    ];
}

/**
 * Catat timeline/audit trail CRF. $oldStatus/$newStatus opsional
 * (label status tampilan, lihat crfDisplayStatus()).
 * pengguna_id diambil dari session bila tersedia.
 */
function logCrfActivity(
    PDO $pdo,
    int $crfId,
    string $activity,
    string $description,
    string $actor,
    ?string $oldStatus = null,
    ?string $newStatus = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO crf_log_aktivitas (
            pengajuan_id,
            pengguna_id,
            aktivitas,
            deskripsi,
            pelaksana,
            status_lama,
            status_baru
        ) VALUES (
            :pengajuan_id,
            :pengguna_id,
            :aktivitas,
            :deskripsi,
            :pelaksana,
            :status_lama,
            :status_baru
        )
    ");

    $stmt->execute([
        'pengajuan_id' => $crfId,
        'pengguna_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'aktivitas' => $activity,
        'deskripsi' => $description,
        'pelaksana' => $actor,
        'status_lama' => $oldStatus,
        'status_baru' => $newStatus,
    ]);

    // Setiap aksi yang tercatat bisa memindahkan CRF antar tahap: selaraskan jam Response Time.
    crfRespSync($pdo, $crfId, null, $actor);
}

/**
 * Buang informasi SLA dari sebuah teks untuk tampilan Pemohon.
 * Pemohon hanya melihat Level Urgensi dan tahap CRF; SLA adalah urusan
 * internal (CMO, Kadep, PIC CRF, Admin). Data yang tersimpan tidak diubah.
 */
function crfStripSlaText(?string $text): string
{
    $text = strtr((string) $text, [
        ' dan Resolution Time berhenti' => '',
        'Resolution Time berhenti; ' => '',
        'Resolution Time berjalan sejak persetujuan. ' => '',
        ' (tidak dihitung dalam Resolution Time)' => '',
        '(tidak dihitung dalam Resolution Time)' => '',
        ' dan SLA berhenti' => '',
        'SLA berhenti; ' => '',
        'SLA berjalan sejak persetujuan. ' => '',
        ' (tidak dihitung dalam SLA)' => '',
        '(tidak dihitung dalam SLA)' => '',
    ]);
    $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
    $sentences = array_filter($sentences, static fn(string $s): bool => !preg_match('/\b(?:SLA|Resolution Time|Response time)\b/iu', $s));

    return trim(implode(' ', $sentences));
}

/**
 * Status tampilan CRF (label sesuai alur CRF) yang diturunkan
 * dari kombinasi status + tahap_alur. ENUM database tidak diubah.
 *
 * @return array{key:string,label:string,class:string}
 */
function crfDisplayStatus(array $crf): array
{
    $status = (string) ($crf['status'] ?? '');
    $stage = (string) ($crf['tahap_alur'] ?? '');
    $approved = !empty($crf['tgl_disetujui_kadep']);

    if ($status === 'Draft') {
        return ['key' => 'draft', 'label' => 'Draft', 'class' => 'badge-status-draft'];
    }
    if ($status === 'Solve') {
        return ['key' => 'selesai', 'label' => 'Selesai', 'class' => 'badge-status-solve'];
    }
    if ($status === 'Cancel') {
        return ['key' => 'dibatalkan', 'label' => 'Dibatalkan', 'class' => 'badge-status-cancel'];
    }
    if ($status === 'Perlu Revisi') {
        return ['key' => 'revisi', 'label' => 'Perlu Revisi', 'class' => 'badge-status-revisi'];
    }
    if ($stage === 'CMO_FILTER' && !empty($crf['pembahasan_forum_terbuka'])) {
        return ['key' => 'pembahasan', 'label' => 'Menunggu Pembahasan Forum', 'class' => 'badge-status-pembahasan'];
    }
    if ($stage === 'CMO_FILTER') {
        return ['key' => 'review', 'label' => 'Menunggu Verifikasi', 'class' => 'badge-status-belum'];
    }
    if ($stage === 'kadep_operasional') {
        return ['key' => 'approval', 'label' => 'Menunggu Persetujuan', 'class' => 'badge-stage-kadep'];
    }
    if ($stage === 'OTOMASI' && $approved) {
        $phase = crfOtomasiPhase($crf);
        $label = $phase === 'perbaikan'
            ? 'Perbaikan Hasil UAT'
            : ($phase === 'implementasi' ? 'Isi Implementasi' : 'Disetujui · Pengerjaan');

        return ['key' => 'disetujui', 'label' => $label, 'class' => 'badge-stage-otomasi'];
    }
    if ($stage === 'UAT') {
        return ['key' => 'uat', 'label' => 'Menunggu UAT', 'class' => 'badge-stage-cmo'];
    }
    if ($stage === 'PEMOHON_PIR') {
        return ['key' => 'pir', 'label' => 'Menunggu PIR Pemohon', 'class' => 'badge-stage-pir'];
    }
    if ($stage === 'CMO_FINAL') {
        return ['key' => 'finalisasi', 'label' => 'Menunggu Finalisasi', 'class' => 'badge-stage-cmo-final'];
    }

    return ['key' => 'submitted', 'label' => 'Menunggu Tindakan', 'class' => 'badge-status-belum'];
}

/**
 * Kondisi SQL untuk setiap status tampilan (dipakai summary & filter dashboard).
 *
 * @return array<string,array{label:string,sql:string}>
 */
function crfDisplayStatusConditions(string $alias = 'cr'): array
{
    $a = $alias . '.';

    return [
        'draft'      => ['label' => 'Draft', 'sql' => "{$a}status = 'Draft'"],
        'review'     => ['label' => 'Menunggu Verifikasi', 'sql' => "{$a}status = 'Belum Ditindak Lanjuti' AND {$a}tahap_alur = 'CMO_FILTER' AND {$a}pembahasan_forum_terbuka = 0"],
        'pembahasan' => ['label' => 'Menunggu Pembahasan Forum', 'sql' => "{$a}tahap_alur = 'CMO_FILTER' AND {$a}pembahasan_forum_terbuka = 1 AND {$a}status NOT IN ('Draft','Solve','Cancel')"],
        'approval'   => ['label' => 'Menunggu Persetujuan', 'sql' => "{$a}status = 'Dalam Proses' AND {$a}tahap_alur = 'kadep_operasional'"],
        'disetujui'  => ['label' => 'Disetujui · Pengerjaan', 'sql' => "{$a}status = 'Dalam Proses' AND {$a}tahap_alur IN ('OTOMASI','UAT','PEMOHON_PIR','CMO_FINAL') AND {$a}tgl_disetujui_kadep IS NOT NULL"],
        'revisi'     => ['label' => 'Perlu Revisi', 'sql' => "{$a}status = 'Perlu Revisi'"],
        'selesai'    => ['label' => 'Selesai', 'sql' => "{$a}status = 'Solve'"],
        'dibatalkan' => ['label' => 'Dibatalkan', 'sql' => "{$a}status = 'Cancel'"],
    ];
}

/**
 * Hitung durasi aktual SLA dan hasilnya (Sesuai / Melebihi SLA) saat
 * eksekusi diselesaikan. Dipanggil di dalam transaksi.
 */
function finalizeCrfSla(PDO $pdo, int $crfId): void
{
    $stmt = $pdo->prepare('
        SELECT sla_mulai, sla_tenggat, otomasi_selesai
        FROM crf_pengajuan
        WHERE id = :id
    ');
    $stmt->execute(['id' => $crfId]);
    $row = $stmt->fetch();

    if (!$row || empty($row['sla_mulai']) || empty($row['otomasi_selesai'])) {
        return;
    }

    $started = new DateTimeImmutable($row['sla_mulai']);
    $completed = new DateTimeImmutable($row['otomasi_selesai']);
    // Durasi aktual hanya menghitung hari kerja, sama seperti batas SLA.
    $minutes = (int) round(slaWorkingSecondsBetween($started, $completed) / 60);
    $result = null;

    if (!empty($row['sla_tenggat'])) {
        $result = $completed->getTimestamp() <= strtotime($row['sla_tenggat']) ? 'Sesuai SLA' : 'Melebihi SLA';
    }

    $update = $pdo->prepare('
        UPDATE crf_pengajuan
        SET sla_menit_aktual = :minutes, sla_hasil = :result
        WHERE id = :id
    ');
    $update->execute(['minutes' => $minutes, 'result' => $result, 'id' => $crfId]);
}

/**
 * Pengingat PIR untuk Pemohon (dikirim CMO saat CRF tertahan di tahap
 * PEMOHON_PIR). Maksimal satu pengingat per CRF_PIR_REMINDER_INTERVAL detik.
 *
 * @return array{count:int, last_at:?string, can_send:bool, next_at:?string, waiting_days:?int}
 */
const CRF_PIR_REMINDER_INTERVAL = 86400;

function crfPirReminderInfo(PDO $pdo, array $crf): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, MAX(tgl_dibuat) AS last_at
        FROM crf_log_aktivitas
        WHERE pengajuan_id = :id AND aktivitas = 'Pengingat PIR'
    ");
    $stmt->execute(['id' => (int) $crf['id']]);
    $row = $stmt->fetch() ?: ['total' => 0, 'last_at' => null];

    $lastAt = $row['last_at'] ?: null;
    $nextAt = $lastAt !== null ? date('Y-m-d H:i:s', strtotime($lastAt) + CRF_PIR_REMINDER_INTERVAL) : null;
    // Penantian PIR dimulai saat Otomasi mengisi implementasi; data lama memakai waktu eksekusi selesai.
    $pirStartedAt = $crf['tgl_implementasi_diisi'] ?? $crf['otomasi_selesai'] ?? null;
    $waitingDays = !empty($pirStartedAt)
        ? (int) floor(slaWorkingSecondsBetween(new DateTimeImmutable($pirStartedAt), new DateTimeImmutable()) / CRF_SLA_WORK_SECONDS_PER_DAY)
        : null;

    return [
        'count' => (int) $row['total'],
        'last_at' => $lastAt,
        'can_send' => ($crf['tahap_alur'] ?? '') === 'PEMOHON_PIR' && ($nextAt === null || time() >= strtotime($nextAt)),
        'next_at' => $nextAt,
        'waiting_days' => $waitingDays,
    ];
}

function crfActorName(array $user): string
{
    return !empty($user['nama']) ? (string) $user['nama'] : (string) ($user['userid'] ?? '-');
}

function applyCrfRequestFilters(PDO $pdo, array &$where, array &$params, array $filters): array
{
    $filterString = static function ($value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    };
    $search = $filterString($filters['search'] ?? '');
    $status = $filterString($filters['status'] ?? '');
    $department = $filterString($filters['department'] ?? '');
    $level = $filterString($filters['level'] ?? '');
    $dateFrom = $filterString($filters['date_from'] ?? '');
    $dateTo = $filterString($filters['date_to'] ?? '');
    $allowedStatuses = ['Belum Ditindak Lanjuti', 'Perlu Revisi', 'Dalam Proses', 'Solve', 'Cancel'];
    $allowedLevels = ['Tinggi', 'Normal', 'Rendah'];

    if ($search !== '') {
        $where[] = '(
            cr.nomor_register LIKE :list_search_request
            OR cr.nama_pemohon LIKE :list_search_name
            OR cr.deskripsi_perubahan LIKE :list_search_description
        )';
        $searchValue = '%' . $search . '%';
        $params['list_search_request'] = $searchValue;
        $params['list_search_name'] = $searchValue;
        $params['list_search_description'] = $searchValue;
    }

    if (in_array($status, $allowedStatuses, true)) {
        $where[] = 'cr.status = :list_status';
        $params['list_status'] = $status;
    } else {
        $status = '';
    }

    if ($department !== '') {
        $where[] = 'cr.dept_asal = :list_department';
        $params['list_department'] = $department;
    }

    if (in_array($level, $allowedLevels, true)) {
        $where[] = 'cr.level = :list_level';
        $params['list_level'] = $level;
    } else {
        $level = '';
    }

    $normalizeDate = static function (string $date): string {
        if ($date === '') {
            return '';
        }
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsedDate && $parsedDate->format('Y-m-d') === $date ? $date : '';
    };
    $dateFrom = $normalizeDate($dateFrom);
    $dateTo = $normalizeDate($dateTo);

    if ($dateFrom !== '') {
        $where[] = 'cr.tgl_pengajuan >= :list_date_from';
        $params['list_date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'cr.tgl_pengajuan <= :list_date_to';
        $params['list_date_to'] = $dateTo;
    }

    $categoryId = (int) $filterString($filters['category_id'] ?? '');
    if ($categoryId > 0) {
        $where[] = 'cr.kategori_id = :list_category_id';
        $params['list_category_id'] = $categoryId;
    } else {
        $categoryId = 0;
    }

    $requestType = $filterString($filters['type'] ?? '');
    if (in_array($requestType, crfRequestTypeOptions(), true)) {
        $where[] = 'cr.jenis_pengajuan = :list_request_type';
        $params['list_request_type'] = $requestType;
    } else {
        $requestType = '';
    }

    $displayStatus = $filterString($filters['display_status'] ?? '');
    $displayConditions = crfDisplayStatusConditions('cr');
    if (isset($displayConditions[$displayStatus])) {
        $where[] = '(' . $displayConditions[$displayStatus]['sql'] . ')';
    } else {
        $displayStatus = '';
    }

    $departmentStmt = $pdo->query("
        SELECT DISTINCT dept_asal
        FROM crf_pengajuan
        WHERE status <> 'Draft'
          AND dept_asal IS NOT NULL
          AND dept_asal <> ''
        ORDER BY dept_asal
    ");

    $categories = [];
    try {
        $categories = $pdo->query('
            SELECT id, nama FROM crf_kategori
            WHERE tgl_dihapus IS NULL
            ORDER BY urutan, nama
        ')->fetchAll();
    } catch (Throwable $e) {
        error_log('applyCrfRequestFilters categories: ' . $e->getMessage());
    }

    return [
        'search' => $search,
        'status' => $status,
        'department' => $department,
        'level' => $level,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'category_id' => $categoryId,
        'type' => $requestType,
        'display_status' => $displayStatus,
        'departments' => $departmentStmt->fetchAll(PDO::FETCH_COLUMN),
        'categories' => $categories,
    ];
}

function getCrfPageSize($requestedPageSize): int
{
    $allowedPageSizes = [6, 10, 20, 50];
    if (!is_scalar($requestedPageSize)) {
        return 6;
    }

    $pageSize = filter_var($requestedPageSize, FILTER_VALIDATE_INT);
    return in_array($pageSize, $allowedPageSizes, true) ? $pageSize : 6;
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

function crfRequestTypeOptions(): array
{
    return [
        'Problem',
        'Permohonan Perubahan',
        'Permohonan Tambahan',
    ];
}

/**
 * Tipe Pengajuan yang boleh dipilih untuk CRF baru. Problem diproses di Helpdesk SIAP, jadi tidak
 * ditawarkan lagi; crfRequestTypeOptions() tetap memuatnya untuk data lama (filter, detail, laporan).
 *
 * @return string[]
 */
function crfRequestTypeOptionsBaru(): array
{
    return array_values(array_diff(crfRequestTypeOptions(), ['Problem']));
}

/**
 * Tipe yang diterima saat menyimpan: tipe baru, ditambah 'Problem' hanya bila CRF yang sedang
 * diedit (Draft/Perlu Revisi milik pengguna) memang sudah bertipe Problem.
 *
 * @return string[]
 */
function crfRequestTypeAllowedForSave(PDO $pdo, int $id, int $penggunaId): array
{
    $allowed = crfRequestTypeOptionsBaru();
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT jenis_pengajuan FROM crf_pengajuan WHERE id = :id AND pengguna_id = :pengguna_id LIMIT 1');
        $stmt->execute(['id' => $id, 'pengguna_id' => $penggunaId]);
        if ((string) $stmt->fetchColumn() === 'Problem') {
            $allowed[] = 'Problem';
        }
    }

    return $allowed;
}

function crfImpactGroups(): array
{
    return [
        'Tinggi' => [
            'high_system_stopped' => 'Fungsi aplikasi berhenti total.',
            'high_no_alternative' => 'Tanpa ada solusi alternatif.',
            'high_liquidity_legal_risk' => 'Dapat menimbulkan risiko likuiditas dan hukum.',
        ],
        'Normal' => [
            'normal_operations_disrupted' => 'Operasional terganggu berat.',
            'normal_partial_access' => 'Aplikasi masih bisa diakses sebagian.',
            'normal_temporary_workaround' => 'Ada solusi alternatif sementara yang merepotkan.',
            'normal_operational_risk' => 'Dapat menimbulkan risiko operasional.',
        ],
        'Rendah' => [
            'low_new_issue_or_feature' => 'Masalah atau permintaan fitur baru.',
            'low_no_efficiency_impact' => 'Tidak mempengaruhi efisiensi kerja.',
            'low_no_daily_interruption' => 'Tidak menghentikan operasional harian.',
            'low_no_risk' => 'Tidak menimbulkan risiko.',
        ],
    ];
}


/**
 * Pertanyaan dampak di Form CRF. Mengikuti tabel resmi "Keterangan Level Urgensi": tiap pilihan adalah
 * satu butir tabel, dipetakan ke kunci pernyataan di crfImpactGroups() (penyimpanan dampak_dipilih,
 * bobot, skor, level, dan SLA tidak berubah). 'judul' dan 'label' bisa berbeda menurut Tipe Pengajuan
 * (kunci 'default' dipakai bila tipe tidak punya teks sendiri); 'petunjuk' (opsional) tampil di bawah judul.
 *
 * Alur (lihat crfDampakPertanyaanTampil / crfDampakOpsiTampil):
 *  - q1 = c (berfungsi normal): q2 dan q3 tidak ditanyakan; kunci "tidak menghentikan operasional harian"
 *    dan "tidak mempengaruhi efisiensi kerja" ditambahkan otomatis.
 *  - q1 = a (berhenti total): q2 tidak ditanyakan; q3 hanya a/b.
 *  - q1 = b: q2 ditanyakan; q3 pilihan c hanya bila q2 = b (operasional tidak terganggu berat).
 * Butir Rendah "Masalah atau permintaan fitur baru" ditentukan oleh Tipe Pengajuan, bukan pertanyaan.
 *
 * @return array<string,array{judul:array<string,string>,petunjuk?:string,opsi:array<string,array{label:array<string,string>,kunci:?string}>}>
 */
function crfDampakQuestions(): array
{
    return [
        'q1' => [
            'judul' => [
                'default' => 'Bila CRF ini tidak dikerjakan, bagaimana keadaan fungsi aplikasi atau layanan?',
                'Permohonan Perubahan' => 'Bila perubahan ini tidak dilakukan, apakah pekerjaan Anda terhambat?',
                'Permohonan Tambahan' => 'Bila hal baru ini tidak ada, apakah pekerjaan Anda terhambat?',
            ],
            'opsi' => [
                'a' => [
                    'label' => ['default' => 'Fungsi aplikasi berhenti total'],
                    'kunci' => 'high_system_stopped',
                ],
                'b' => [
                    'label' => [
                        'default' => 'Aplikasi hanya bisa diakses sebagian',
                        'Permohonan Perubahan' => 'Sebagian pekerjaan terhambat',
                        'Permohonan Tambahan' => 'Sebagian pekerjaan terhambat',
                    ],
                    'kunci' => 'normal_partial_access',
                ],
                'c' => [
                    'label' => [
                        'default' => 'Aplikasi berfungsi normal',
                        'Permohonan Perubahan' => 'Pekerjaan berjalan normal',
                        'Permohonan Tambahan' => 'Pekerjaan berjalan normal',
                    ],
                    'kunci' => null,
                ],
            ],
        ],
        'q2' => [
            'judul' => ['default' => 'Bagaimana dampaknya pada operasional harian?'],
            'opsi' => [
                'a' => ['label' => ['default' => 'Operasional terganggu berat'], 'kunci' => 'normal_operations_disrupted'],
                'b' => ['label' => ['default' => 'Tidak menghentikan operasional harian'], 'kunci' => 'low_no_daily_interruption'],
            ],
        ],
        'q3' => [
            'judul' => ['default' => 'Adakah solusi alternatif?'],
            'opsi' => [
                'a' => ['label' => ['default' => 'Tanpa ada solusi alternatif'], 'kunci' => 'high_no_alternative'],
                'b' => ['label' => ['default' => 'Ada solusi alternatif sementara, tetapi merepotkan'], 'kunci' => 'normal_temporary_workaround'],
            ],
        ],
        'q4' => [
            'judul' => ['default' => 'Risiko apa yang dapat timbul?'],
            'petunjuk' => '(misalnya rugi uang, denda, melanggar kontrak, atau pekerjaan salah dan harus diulang)',
            'opsi' => [
                'a' => ['label' => ['default' => 'Dapat menimbulkan risiko likuiditas dan hukum'], 'kunci' => 'high_liquidity_legal_risk'],
                'b' => ['label' => ['default' => 'Dapat menimbulkan risiko operasional'], 'kunci' => 'normal_operational_risk'],
                'c' => ['label' => ['default' => 'Tidak menimbulkan risiko'], 'kunci' => 'low_no_risk'],
            ],
        ],
    ];
}

/** Judul pertanyaan dampak untuk tipe pengajuan tertentu. */
function crfDampakJudul(string $q, ?string $tipe = null): string
{
    $judul = crfDampakQuestions()[$q]['judul'] ?? [];

    return (string) ($judul[trim((string) $tipe)] ?? $judul['default'] ?? '');
}

/** Teks satu pilihan jawaban untuk tipe pengajuan tertentu. */
function crfDampakLabel(string $q, string $kode, ?string $tipe = null): string
{
    $label = crfDampakQuestions()[$q]['opsi'][$kode]['label'] ?? [];

    return (string) ($label[trim((string) $tipe)] ?? $label['default'] ?? '');
}

/**
 * Jawaban yang tidak berlaku untuk sebuah tipe: opsi yang kuncinya masuk
 * daftar 'tolak' tipe itu (config/urgensi.php), mis. Permohonan Tambahan dan
 * Perubahan tidak punya "fungsi aplikasi berhenti total".
 *
 * @return array<string,string[]> contoh ['q1' => ['a']]
 */
function crfDampakOpsiTersembunyi(?string $tipe): array
{
    $tolak = (array) ((crfUrgencyConfig()['tipe_pengajuan'][trim((string) $tipe)] ?? [])['tolak'] ?? []);
    $hasil = [];
    foreach (crfDampakQuestions() as $q => $pertanyaan) {
        foreach ($pertanyaan['opsi'] as $kode => $opsi) {
            if ($opsi['kunci'] !== null && in_array($opsi['kunci'], $tolak, true)) {
                $hasil[$q][] = $kode;
            }
        }
    }

    return $hasil;
}

/**
 * Apakah pertanyaan $q ditanyakan, menurut jawaban sebelumnya. Sebelum q1 dijawab,
 * semua pertanyaan tampil.
 *
 * @param array<string,string> $jawaban
 */
function crfDampakPertanyaanTampil(string $q, array $jawaban): bool
{
    $q1 = (string) ($jawaban['q1'] ?? '');

    if ($q === 'q2') {
        return $q1 !== 'a' && $q1 !== 'c';
    }
    if ($q === 'q3') {
        // Solusi alternatif hanya ditanyakan bila fungsi berhenti total atau operasional terganggu berat.
        return $q1 === 'a' || ($q1 === 'b' && (string) ($jawaban['q2'] ?? '') === 'a');
    }

    return true;
}

/**
 * Apakah pilihan $kode pada pertanyaan $q tersedia. Pilihan yang ditolak tipe ('tolak' di config)
 * tidak tersedia.
 *
 * @param array<string,string> $jawaban
 */
function crfDampakOpsiTampil(string $q, string $kode, array $jawaban, ?string $tipe = null): bool
{
    if (!isset(crfDampakQuestions()[$q]['opsi'][$kode])) {
        return false;
    }
    if (in_array($kode, crfDampakOpsiTersembunyi($tipe)[$q] ?? [], true)) {
        return false;
    }

    return true;
}

/**
 * Membersihkan jawaban: pertanyaan yang tidak ditanyakan dan pilihan yang tidak tersedia dikosongkan
 * (berurutan, karena tampilnya bergantung jawaban sebelumnya).
 *
 * @param array<string,string> $jawaban
 * @return array<string,string>
 */
function crfDampakNormalisasi(array $jawaban, ?string $tipe = null): array
{
    $hasil = [];
    foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
        $hasil[$q] = (string) ($jawaban[$q] ?? '');
    }
    foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
        if (!crfDampakPertanyaanTampil($q, $hasil)
            || ($hasil[$q] !== '' && !crfDampakOpsiTampil($q, $hasil[$q], $hasil, $tipe))) {
            $hasil[$q] = '';
        }
    }

    return $hasil;
}

/**
 * Jawaban q1..q4 dari data POST. Hanya kode yang dikenal; sisanya ''.
 * Mengembalikan null bila tidak ada satu pun jawaban baru (formulir lama).
 *
 * @return array<string,string>|null
 */
function crfDampakJawabanDariPost(array $post): ?array
{
    $jawaban = [];
    $ada = false;
    foreach (crfDampakQuestions() as $q => $pertanyaan) {
        $nilai = trim((string) ($post['dampak_' . $q] ?? ''));
        if ($nilai !== '') {
            $ada = true;
        }
        $jawaban[$q] = isset($pertanyaan['opsi'][$nilai]) ? $nilai : '';
    }

    return $ada ? $jawaban : null;
}

/**
 * Jawaban q1..q4 menjadi kunci dampak (urutan: Tinggi, Normal, Rendah).
 * Tidak pernah menghasilkan pasangan 'bertentangan'. Jawaban kosong dan pertanyaan yang tidak
 * ditanyakan dilewati.
 *
 * @param array<string,string> $jawaban
 * @return string[]
 */
function crfDampakDariJawaban(array $jawaban, ?string $tipe = null): array
{
    $j = crfDampakNormalisasi($jawaban, $tipe);
    $pertanyaan = crfDampakQuestions();

    $hasil = [];
    foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
        $kunci = $pertanyaan[$q]['opsi'][$j[$q]]['kunci'] ?? null;
        if ($kunci !== null) {
            $hasil[] = $kunci;
        }
    }

    if ($j['q1'] === 'c') {
        // Berfungsi atau berjalan normal: operasional tidak terhenti dan tanpa beban tambahan (tidak ditanyakan).
        $hasil[] = 'low_no_daily_interruption';
        $hasil[] = 'low_no_efficiency_impact';
        if (trim((string) $tipe) !== 'Problem') {
            $hasil[] = 'low_new_issue_or_feature';
        }
    }

    $urutan = array_keys(crfImpactOptions());
    usort($hasil, static fn(string $a, string $b): int => array_search($a, $urutan, true) <=> array_search($b, $urutan, true));

    return array_values(array_unique($hasil));
}

/**
 * Kebalikan crfDampakDariJawaban(): isi ulang jawaban dari kunci tersimpan
 * (draft, revisi, CRF lama). Kombinasi lama yang tak terwakili diisi dengan
 * pendekatan terdekat; pertanyaan yang tidak bisa disimpulkan dikosongkan
 * (dijawab ulang).
 *
 * @param string[] $keys
 * @return array<string,string>
 */
function crfJawabanDariDampak(array $keys): array
{
    $keys = crfDampakDipilih($keys);
    $ada = static fn(string $k): bool => in_array($k, $keys, true);

    $q1 = '';
    if ($ada('high_system_stopped')) {
        $q1 = 'a';
    } elseif ($ada('normal_partial_access')) {
        $q1 = 'b';
    } elseif ($ada('low_no_daily_interruption') && $ada('low_no_efficiency_impact') && !$ada('normal_operations_disrupted')) {
        $q1 = 'c'; // pasangan kunci otomatis dari "berfungsi atau berjalan normal"
    }

    $q2 = '';
    if ($q1 !== 'c') {
        if ($ada('normal_operations_disrupted')) {
            $q2 = 'a';
        } elseif ($ada('low_no_daily_interruption')) {
            $q2 = 'b';
        }
    }

    $q3 = '';
    if ($q1 !== 'c') {
        if ($ada('high_no_alternative')) {
            $q3 = 'a';
        } elseif ($ada('normal_temporary_workaround')) {
            $q3 = 'b';
        }
    }

    $q4 = '';
    foreach (['high_liquidity_legal_risk' => 'a', 'normal_operational_risk' => 'b', 'low_no_risk' => 'c'] as $kunci => $kode) {
        if ($ada($kunci)) {
            $q4 = $kode;
            break;
        }
    }

    return crfDampakNormalisasi(['q1' => $q1, 'q2' => $q2, 'q3' => $q3, 'q4' => $q4]);
}

/**
 * Jawaban 4 pertanyaan dampak sebuah CRF untuk halaman detail dan PDF, dari kunci tersimpan.
 * Hanya pertanyaan yang ditanyakan yang ditampilkan. Mengembalikan null bila kunci tidak bisa
 * dihasilkan form (CRF lama berbentuk centang bebas atau versi sebelumnya); detail lalu memakai
 * daftar pernyataan.
 *
 * @param string[] $keys
 * @return array<int,array{q:string,judul:string,jawaban:string,bobot:float}>|null
 */
function crfDampakJawabanTampil(array $keys, ?string $tipe): ?array
{
    $keys = crfDampakDipilih($keys);
    if (!$keys) {
        return null;
    }

    $jawaban = crfJawabanDariDampak($keys);
    foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
        if ($jawaban[$q] === '' && crfDampakPertanyaanTampil($q, $jawaban)) {
            return null;
        }
    }

    // Harus bisa dihasilkan ulang persis oleh form; kalau tidak, ini CRF lama.
    $ulang = crfDampakDariJawaban($jawaban, $tipe);
    $a = $keys;
    $b = $ulang;
    sort($a);
    sort($b);
    if ($a !== $b) {
        return null;
    }

    $bobot = (array) (crfUrgencyConfig()['bobot'] ?? []);
    $pertanyaan = crfDampakQuestions();
    $baris = [];
    foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
        if (!crfDampakPertanyaanTampil($q, $jawaban)) {
            continue;
        }
        $opsi = $pertanyaan[$q]['opsi'][$jawaban[$q]] ?? null;
        if ($opsi === null) {
            return null;
        }
        $kunciBaris = $opsi['kunci'] !== null ? [$opsi['kunci']] : [];
        if ($q === 'q1' && $jawaban['q1'] === 'c') {
            // Kunci otomatis dari "berfungsi atau berjalan normal" dihitung di baris ini.
            $kunciBaris = array_values(array_intersect($ulang, ['low_no_daily_interruption', 'low_no_efficiency_impact', 'low_new_issue_or_feature']));
        }
        $total = 0.0;
        foreach ($kunciBaris as $k) {
            $total += (float) ($bobot[$k] ?? 0);
        }
        $baris[] = [
            'q' => $q,
            'judul' => crfDampakJudul($q, $tipe),
            'jawaban' => crfDampakLabel($q, $jawaban[$q], $tipe),
            'bobot' => $total,
        ];
    }

    return $baris;
}

/**
 * Validasi pilihan dampak untuk pengiriman CRF. Mengembalikan pesan kesalahan
 * atau null bila sah. $jawaban null = formulir lama (kunci dipilih langsung).
 *
 * @param array<string,string>|null $jawaban
 * @param string[]                  $kunci
 */
function crfDampakValidasi(?array $jawaban, array $kunci, ?string $tipe): ?string
{
    if ($jawaban !== null) {
        // Pilihan yang ditolak tipe (mis. "berhenti total" pada Permohonan Tambahan) dikirim paksa.
        $tolak = (array) ((crfUrgencyConfig()['tipe_pengajuan'][trim((string) $tipe)] ?? [])['tolak'] ?? []);
        $pertanyaan = crfDampakQuestions();
        foreach ($jawaban as $q => $kode) {
            $kunciPilihan = $pertanyaan[$q]['opsi'][$kode]['kunci'] ?? null;
            if ($kunciPilihan !== null && in_array($kunciPilihan, $tolak, true)) {
                return 'Tipe pengajuan dan pernyataan dampak tidak sesuai. Periksa kembali pilihan Anda.';
            }
        }
        $bersih = crfDampakNormalisasi($jawaban, $tipe);
        foreach ($bersih as $q => $nilai) {
            if ($nilai === '' && crfDampakPertanyaanTampil($q, $bersih)) {
                return 'Jawab semua pertanyaan dampak.';
            }
        }
    } elseif (!$kunci) {
        return 'Pilih minimal satu pernyataan dampak.';
    } elseif (crfDampakBertentangan($kunci)) {
        return 'Beberapa pernyataan dampak saling bertentangan. Periksa kembali pilihan Anda.';
    }

    if (crfTipeBertentangan($tipe, $kunci)) {
        return 'Tipe pengajuan dan pernyataan dampak tidak sesuai. Periksa kembali pilihan Anda.';
    }

    return null;
}

function crfImpactOptions(): array
{
    return array_merge(...array_values(crfImpactGroups()));
}

function crfUrgencyForImpact(?string $impact): ?string
{
    $urgencyByImpact = [
        'high_system_stopped' => 'Tinggi',
        'high_no_alternative' => 'Tinggi',
        'high_liquidity_legal_risk' => 'Tinggi',
        'normal_operations_disrupted' => 'Normal',
        'normal_partial_access' => 'Normal',
        'normal_temporary_workaround' => 'Normal',
        'normal_operational_risk' => 'Normal',
        'low_new_issue_or_feature' => 'Rendah',
        'low_no_efficiency_impact' => 'Rendah',
        'low_no_daily_interruption' => 'Rendah',
        'low_no_risk' => 'Rendah',
        'high' => 'Tinggi',
        'normal' => 'Normal',
        'low' => 'Rendah',
    ];

    return $urgencyByImpact[$impact ?? ''] ?? null;
}

/* ---------------------------------------------------------------------
 * Penilaian urgensi berbobot (lihat config/urgensi.php)
 * ------------------------------------------------------------------- */

/** Konfigurasi bobot, ambang, dan pagar pengaman urgensi. */
function crfUrgencyConfig(): array
{
    static $config = null;

    if ($config === null) {
        $path = __DIR__ . '/../config/urgensi.php';
        $loaded = is_file($path) ? require $path : [];
        $config = is_array($loaded) ? $loaded : [];
    }

    return $config;
}

/**
 * Daftar kunci dampak yang valid dari input pemohon.
 * Menerima array (checkbox), string dipisah koma (kolom database), atau
 * satu kunci (nilai lama). Kunci tak dikenal dan duplikat dibuang.
 *
 * @param mixed $raw
 * @return string[]
 */
function crfDampakDipilih($raw): array
{
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }

    $valid = crfImpactOptions();
    $keys = [];
    foreach ($raw as $item) {
        $key = trim((string) $item);
        if ($key !== '' && isset($valid[$key]) && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }

    return $keys;
}

/**
 * Hitung skor dan Level Urgensi dari pernyataan dampak yang dipilih.
 *
 * @param string[]    $keys    hasil crfDampakDipilih()
 * @param array|null  $config  menimpa config/urgensi.php (untuk uji)
 * @param string|null $tipe    Tipe Pengajuan; tipe dengan 'tinggi_hanya_dengan' (Permohonan Tambahan)
 *                             dibatasi Normal bila tidak ada pernyataan syaratnya
 * @return array{skor:float,level:?string,dikunci:bool,rincian:array<string,float>,dibatasi:bool}
 */
function crfHitungUrgensi(array $keys, ?array $config = null, ?string $tipe = null): array
{
    $config = array_replace_recursive(crfUrgencyConfig(), $config ?? []);
    $bobot = (array) ($config['bobot'] ?? []);
    $maks = (float) ($config['maks'] ?? 100);
    $ambangTinggi = (float) ($config['ambang']['Tinggi'] ?? 35);
    $ambangNormal = (float) ($config['ambang']['Normal'] ?? 10);
    $wajibTinggi = (array) ($config['wajib_tinggi'] ?? []);

    $rincian = [];
    $dikunci = false;
    foreach (crfDampakDipilih($keys) as $key) {
        $rincian[$key] = (float) ($bobot[$key] ?? 0);
        if (in_array($key, $wajibTinggi, true)) {
            $dikunci = true;
        }
    }

    if (!$rincian) {
        return ['skor' => 0.0, 'level' => null, 'dikunci' => false, 'rincian' => [], 'dibatasi' => false];
    }

    $skor = min($maks, array_sum($rincian));
    if ($dikunci || $skor >= $ambangTinggi) {
        $level = 'Tinggi';
    } elseif ($skor >= $ambangNormal) {
        $level = 'Normal';
    } else {
        $level = 'Rendah';
    }

    // Pagar per tipe: Tinggi hanya bila ada pernyataan syarat (mis. risiko uang/hukum pada Permohonan Tambahan).
    $dibatasi = false;
    $syarat = (array) ($config['tipe_pengajuan'][trim((string) $tipe)]['tinggi_hanya_dengan'] ?? []);
    if ($level === 'Tinggi' && $syarat && !array_intersect($syarat, array_keys($rincian))) {
        $level = 'Normal';
        $dikunci = false;
        $dibatasi = true;
    }

    return ['skor' => (float) $skor, 'level' => $level, 'dikunci' => $dikunci, 'rincian' => $rincian, 'dibatasi' => $dibatasi];
}

/**
 * Pasangan pernyataan yang saling bertentangan (config 'bertentangan').
 *
 * @param string[] $keys
 * @return array<int,array{0:string,1:string}>
 */
function crfDampakBertentangan(array $keys): array
{
    $keys = crfDampakDipilih($keys);
    $peta = (array) (crfUrgencyConfig()['bertentangan'] ?? []);
    $pasangan = [];
    foreach ($peta as $a => $lawan) {
        if (!in_array($a, $keys, true)) {
            continue;
        }
        foreach ((array) $lawan as $b) {
            if (in_array($b, $keys, true)) {
                $pasangan[] = [$a, $b];
            }
        }
    }
    return $pasangan;
}

/**
 * Tipe Pengajuan mustahil dikombinasikan dengan pernyataan dampak ini
 * (config 'tipe_pengajuan' => ... => 'tolak').
 *
 * @param string[] $keys
 */
function crfTipeBertentangan(?string $tipe, array $keys): bool
{
    $tipe = trim((string) $tipe);
    $aturan = (array) ((crfUrgencyConfig()['tipe_pengajuan'][$tipe] ?? [])['tolak'] ?? []);
    return (bool) array_intersect($aturan, crfDampakDipilih($keys));
}

/**
 * Penanda "perlu dicek" untuk CMO: daftar alasan (kosong = wajar).
 * Hanya untuk peran internal; jangan ditampilkan ke Pemohon.
 *
 * @param string[] $keys
 * @return string[]
 */
function crfDampakCatatan(array $keys, ?string $tipe = null): array
{
    $keys = crfDampakDipilih($keys);
    $catatan = [];
    if (crfDampakBertentangan($keys)) {
        $catatan[] = 'Ada pernyataan yang saling bertentangan.';
    }
    $tipe = trim((string) $tipe);
    $aturanTipe = (array) (crfUrgencyConfig()['tipe_pengajuan'][$tipe] ?? []);
    if ($keys && $aturanTipe) {
        if (crfTipeBertentangan($tipe, $keys)) {
            $catatan[] = 'Tipe "' . $tipe . '" tidak lazim dengan salah satu pernyataan dampak yang dipilih.';
        } elseif (crfHitungUrgensi($keys, null, $tipe)['dibatasi']) {
            $catatan[] = 'Skor mencapai Tinggi, tetapi tipe "' . $tipe . '" dibatasi Normal karena tidak ada risiko uang/hukum/kontrak. Naikkan lewat Forum bila memang mendesak.';
        } elseif (array_intersect((array) ($aturanTipe['tandai'] ?? []), $keys)
            && crfHitungUrgensi(array_values(array_diff($keys, (array) ($aturanTipe['tandai_tinggi_tanpa'] ?? []))))['level'] === 'Tinggi') {
            // Ditandai hanya bila level Tinggi tetap tercapai tanpa jalur yang sah untuk tipe ini
            // (mis. risiko uang/hukum pada Permohonan Tambahan).
            $catatan[] = 'Tipe "' . $tipe . '" dengan pernyataan dampak tinggi: periksa apakah dampaknya nyata.';
        } elseif (!empty($aturanTipe['tandai_hanya']) && !array_diff($keys, (array) $aturanTipe['tandai_hanya'])) {
            $catatan[] = 'Tipe "' . $tipe . '" tetapi dampak yang dipilih hanya yang ringan (pekerjaan masih berjalan normal atau permintaan fitur baru).';
        }
    }    $batas = (int) (crfUrgencyConfig()['batas_banyak'] ?? 0);
    if ($batas > 0 && count($keys) >= $batas) {
        $total = count(crfImpactOptions());
        $catatan[] = 'Pemohon mencentang ' . count($keys) . ' dari ' . $total . ' pernyataan.';
    }
    return $catatan;
}

/** Pernyataan dampak dengan bobot tertinggi (disimpan sebagai kategori_dampak utama). */
function crfDampakUtama(array $keys): ?string
{
    $bobot = (array) (crfUrgencyConfig()['bobot'] ?? []);
    $utama = null;
    $tertinggi = -1.0;
    foreach (crfDampakDipilih($keys) as $key) {
        $nilai = (float) ($bobot[$key] ?? 0);
        if ($nilai > $tertinggi) {
            $tertinggi = $nilai;
            $utama = $key;
        }
    }

    return $utama;
}

/** Kunci dampak sebuah CRF: dari dampak_dipilih, atau pilihan tunggal lama. */
function crfDampakKunci(array $crf): array
{
    $keys = crfDampakDipilih($crf['dampak_dipilih'] ?? null);

    return $keys ?: crfDampakDipilih($crf['kategori_dampak'] ?? null);
}

/**
 * Urgensi menurut sistem (sebelum diubah CMO/Forum). Baris baru memakai skor
 * berbobot; baris lama (pilihan tunggal) memakai pemetaan lama.
 */
function crfUrgensiSistem(array $crf): ?string
{
    $keys = crfDampakDipilih($crf['dampak_dipilih'] ?? null);
    if ($keys) {
        return crfHitungUrgensi($keys, null, (string) ($crf['jenis_pengajuan'] ?? ''))['level'];
    }

    return crfUrgencyForImpact($crf['kategori_dampak'] ?? null);
}

/** Teks pernyataan dampak yang dipilih sebuah CRF. */
function crfDampakLabels(array $crf): array
{
    $options = crfImpactOptions();

    return array_values(array_map(
        static fn(string $key): string => $options[$key],
        crfDampakKunci($crf)
    ));
}

/**
 * Matriks SLA satu kategori CRF per level urgensi.
 * Kolom sla_nilai / sla_satuan = SLA Normal; Tinggi/Rendah kosong ikut Normal.
 *
 * @return array<string,?array{value:float,unit:string}>
 */
function crfCategorySlaMatrix(array $category): array
{
    $pick = static function ($value, $unit): ?array {
        return ($value !== null && $value !== '' && (float) $value > 0 && in_array($unit, ['Menit', 'Jam', 'Hari'], true))
            ? ['value' => (float) $value, 'unit' => (string) $unit]
            : null;
    };
    $normal = $pick($category['sla_nilai'] ?? null, $category['sla_satuan'] ?? null);

    return [
        'Tinggi' => $pick($category['sla_tinggi_nilai'] ?? null, $category['sla_tinggi_satuan'] ?? null) ?? $normal,
        'Normal' => $normal,
        'Rendah' => $pick($category['sla_rendah_nilai'] ?? null, $category['sla_rendah_satuan'] ?? null) ?? $normal,
    ];
}

/**
 * Level urgensi yang berlaku untuk CRF: final Forum > level tersimpan > otomatis dari dampak.
 */
function crfEffectiveUrgency(array $crf): ?string
{
    return ($crf['level_urgensi_final'] ?? null)
        ?: ($crf['level'] ?? null)
        ?: crfUrgensiSistem($crf);
}

/**
 * SLA standar CRF dari matriks Kategori x Urgensi, atau null bila belum diatur.
 *
 * @return ?array{value:float,unit:string}
 */
function crfStandardSla(PDO $pdo, ?int $categoryId, ?string $urgency): ?array
{
    if (empty($categoryId) || !in_array($urgency, ['Tinggi', 'Normal', 'Rendah'], true)) {
        return null;
    }

    $category = findCrfCategory($pdo, $categoryId);

    return $category ? crfCategorySlaMatrix($category)[$urgency] : null;
}

/**
 * Matriks target respon PIC satu kategori per level urgensi.
 * Kolom sla_respon_nilai / sla_respon_satuan = urgensi Normal; Tinggi/Rendah
 * kosong ikut Normal. Normal kosong berarti kategori tanpa target respon.
 *
 * @return array<string,?array{value:float,unit:string}>
 */
function crfCategoryResponMatrix(array $category): array
{
    $pick = static function ($value, $unit): ?array {
        return ($value !== null && $value !== '' && (float) $value > 0 && in_array($unit, ['Menit', 'Jam', 'Hari'], true))
            ? ['value' => (float) $value, 'unit' => (string) $unit]
            : null;
    };
    $normal = $pick($category['sla_respon_nilai'] ?? null, $category['sla_respon_satuan'] ?? null);

    return [
        'Tinggi' => $pick($category['sla_respon_tinggi_nilai'] ?? null, $category['sla_respon_tinggi_satuan'] ?? null) ?? $normal,
        'Normal' => $normal,
        'Rendah' => $pick($category['sla_respon_rendah_nilai'] ?? null, $category['sla_respon_rendah_satuan'] ?? null) ?? $normal,
    ];
}

/**
 * Status Response Time PIC dari kolom sla_respon_* (dipakai daftar Otomasi dan halaman yang belum
 * membaca crf_sla_respon). Aturan dan istilah memakai crfRespRowStatus() agar sama di semua tempat.
 * Null bila tidak ada yang perlu ditampilkan (CRF belum disetujui, dibatalkan, atau tanpa target respon).
 */
function getCrfResponStatus(array $crf, ?DateTimeImmutable $now = null): ?array
{
    if (($crf['status'] ?? '') === 'Cancel' || empty($crf['sla_mulai'])) {
        return null;
    }
    if (empty($crf['sla_respon_tenggat']) && empty($crf['sla_respon_at'])) {
        return null;
    }

    return crfRespRowStatus([
        'mulai' => $crf['sla_mulai'],
        'tenggat' => $crf['sla_respon_tenggat'] ?? null,
        'respon_at' => $crf['sla_respon_at'] ?? null,
        'hasil' => null,
    ], $now);
}

/**
 * Persentase waktu SLA yang sudah terpakai (0-100), dalam jam kerja.
 * $end kosong = sekarang. Null bila mulai atau batas tidak tersedia.
 */
function crfSlaProgressPercent(?string $startedAt, ?string $dueAt, ?string $end = null, ?DateTimeImmutable $now = null): ?int
{
    if (empty($startedAt) || empty($dueAt)) {
        return null;
    }

    $start = new DateTimeImmutable($startedAt);
    $total = slaWorkingSecondsBetween($start, new DateTimeImmutable($dueAt));
    if ($total <= 0) {
        return null;
    }

    $stop = !empty($end) ? new DateTimeImmutable($end) : ($now ?? new DateTimeImmutable('now'));
    $used = slaWorkingSecondsBetween($start, $stop);

    return (int) max(0, min(100, round($used / $total * 100)));
}

/**
 * Catat respon PIC (sekali saja): isi sla_respon_at dan hasilnya. Aman dipanggil
 * berulang; tidak menimpa respon yang sudah tercatat.
 */
function recordCrfResponse(PDO $pdo, int $crfId, string $respondedAt): void
{
    $pdo->prepare("
        UPDATE crf_pengajuan
        SET sla_respon_at = :at,
            sla_respon_hasil = CASE
                WHEN sla_respon_tenggat IS NULL THEN NULL
                WHEN :at2 <= sla_respon_tenggat THEN 'Sesuai SLA'
                ELSE 'Melebihi SLA'
            END
        WHERE id = :id AND sla_respon_at IS NULL
    ")->execute(['at' => $respondedAt, 'at2' => $respondedAt, 'id' => $crfId]);
}

/* =====================================================================
 * Response Time per tahap (CMO verifikasi, Kadep, CMO UAT, CMO finalisasi)
 * ---------------------------------------------------------------------
 * Satu baris crf_sla_respon per "putaran" tahap. Jam dibuka saat CRF masuk
 * antrean sebuah peran dan ditutup pada tindakan pertama peran itu. Response
 * Time PIC tetap memakai kolom sla_respon_* (lihat getCrfResponStatus()).
 *
 * crfRespSync() mencocokkan baris terbuka dengan keadaan CRF saat ini dan
 * idempoten; dipanggil dari logCrfActivity() sehingga semua aksi tercakup.
 * Aturan jeda: pembahasan Forum terbuka dan CRF di tangan Pemohon / PIC tidak
 * punya baris terbuka. Dibatalkan menutup baris tanpa penilaian.
 * ===================================================================== */

/** Tahap dan labelnya. */
function crfRespStages(): array
{
    return [
        'CMO_VERIFIKASI' => ['label' => 'Verifikasi CMO', 'peran' => 'CMO', 'icon' => 'bi-person-check'],
        'KADEP'          => ['label' => 'Persetujuan Kepala Departemen Operasional', 'peran' => 'Kepala Departemen Operasional', 'icon' => 'bi-patch-check'],
        'PIC_MULAI'      => ['label' => 'Mulai dikerjakan PIC Otomasi', 'peran' => 'PIC Otomasi', 'icon' => 'bi-play-circle'],
        'CMO_UAT'        => ['label' => 'UAT oleh CMO', 'peran' => 'CMO', 'icon' => 'bi-clipboard-check'],
        'CMO_FINAL'      => ['label' => 'Finalisasi CMO', 'peran' => 'CMO', 'icon' => 'bi-flag'],
    ];
}

/** True bila tabel crf_sla_respon ada (migrasi 028 sudah dijalankan). */
function crfRespTableReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready === null) {
        try {
            $ready = (bool) $pdo->query("SHOW TABLES LIKE 'crf_sla_respon'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }

    return $ready;
}

/** Tahap Response Time yang seharusnya berjalan untuk CRF ini; null bila tidak ada. */
function crfRespDesiredStage(array $crf): ?string
{
    if (in_array($crf['status'] ?? '', ['Draft', 'Solve', 'Cancel'], true)) {
        return null;
    }

    switch ($crf['tahap_alur'] ?? '') {
        case 'CMO_FILTER':
            // Selama pembahasan Forum terbuka, jam CMO berhenti (CMO sudah merespons dengan mengajukannya).
            return empty($crf['pembahasan_forum_terbuka']) ? 'CMO_VERIFIKASI' : null;
        case 'kadep_operasional':
            return 'KADEP';
        case 'OTOMASI':
            // Fase eksekusi, belum diambil PIC, dan CRF punya target respon (dibekukan saat Kadep menyetujui).
            return empty($crf['pic_id']) && empty($crf['otomasi_selesai']) && !empty($crf['sla_respon_tenggat'])
                ? 'PIC_MULAI'
                : null;
        case 'UAT':
            return 'CMO_UAT';
        case 'CMO_FINAL':
            return 'CMO_FINAL';
        default:
            return null;
    }
}

/** Target tahap x urgensi (Normal bila urgensi kosong), atau null bila belum diatur. */
function crfRespTarget(PDO $pdo, string $stage, ?string $urgency): ?array
{
    $urgency = in_array($urgency, ['Tinggi', 'Normal', 'Rendah'], true) ? $urgency : 'Normal';
    $stmt = $pdo->prepare('SELECT nilai, satuan FROM crf_sla_respon_target WHERE tahap = :t AND urgensi = :u LIMIT 1');
    $stmt->execute(['t' => $stage, 'u' => $urgency]);
    $row = $stmt->fetch();

    return $row && (float) $row['nilai'] > 0 ? ['value' => (float) $row['nilai'], 'unit' => (string) $row['satuan'], 'urgency' => $urgency] : null;
}

/**
 * Cocokkan baris Response Time dengan keadaan CRF: tutup baris terbuka yang
 * tahapnya sudah lewat, buka baris baru bila CRF masuk tahap yang diukur.
 * Tidak pernah melempar exception (tidak boleh menggagalkan proses utama).
 */
function crfRespSync(PDO $pdo, int $crfId, ?string $now = null, ?string $actor = null): void
{
    try {
        if (!crfRespTableReady($pdo)) {
            return;
        }

        $now = $now ?? date('Y-m-d H:i:s');
        $crfStmt = $pdo->prepare('
            SELECT status, tahap_alur, pembahasan_forum_terbuka, level, level_urgensi_final,
                   pic_id, pic_nama, otomasi_selesai, sla_mulai,
                   sla_respon_nilai, sla_respon_satuan, sla_respon_tenggat, sla_respon_at, sla_respon_hasil
            FROM crf_pengajuan WHERE id = :id
        ');
        $crfStmt->execute(['id' => $crfId]);
        $crf = $crfStmt->fetch();
        if (!$crf) {
            return;
        }

        $desired = crfRespDesiredStage($crf);
        $openStmt = $pdo->prepare('SELECT * FROM crf_sla_respon WHERE pengajuan_id = :id AND respon_at IS NULL ORDER BY id DESC LIMIT 1');
        $openStmt->execute(['id' => $crfId]);
        $open = $openStmt->fetch();

        if ($open && $open['tahap'] === $desired) {
            return;
        }

        if ($open) {
            $closedAt = $now;
            $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $actorName = null;
            if ($actorId !== null && function_exists('getCurrentUser') && function_exists('crfActorName')) {
                $actorName = crfActorName(getCurrentUser());
            }

            // PIC: waktu dan pelaku respon sudah dicatat saat "Mulai kerjakan" (recordCrfResponse).
            if ($open['tahap'] === 'PIC_MULAI' && !empty($crf['pic_id'])) {
                if (!empty($crf['sla_respon_at'])) {
                    $closedAt = (string) $crf['sla_respon_at'];
                }
                $actorId = (int) $crf['pic_id'];
                $actorName = (string) ($crf['pic_nama'] ?? '');
            }

            if (($crf['status'] ?? '') === 'Cancel') {
                $result = 'Dibatalkan';
            } elseif (empty($open['tenggat'])) {
                $result = 'Tanpa target';
            } else {
                $result = $closedAt <= $open['tenggat'] ? 'Sesuai SLA' : 'Melebihi SLA';
            }

            // Tahap ditutup oleh sistem (mis. Forum dibuka otomatis saat SLA standar kosong): bukan respon
            // peran itu, jadi tidak dinilai dan tidak dicatat atas nama pengguna yang sedang login.
            if ($actor === 'Sistem') {
                $result = 'Dibatalkan';
                $actorId = null;
                $actorName = 'Sistem';
            }

            $pdo->prepare('UPDATE crf_sla_respon SET respon_at = :at, respon_oleh = :uid, respon_oleh_nama = :nama, hasil = :hasil WHERE id = :id AND respon_at IS NULL')
                ->execute(['at' => $closedAt, 'uid' => $actorId, 'nama' => $actorName !== null && $actorName !== '' ? mb_substr($actorName, 0, 150) : null, 'hasil' => $result, 'id' => $open['id']]);
        }

        if ($desired !== null) {
            $urgency = $crf['level_urgensi_final'] ?: ($crf['level'] ?: 'Normal');
            $startAt = $now;
            if ($desired === 'PIC_MULAI') {
                // Target PIC mengikuti matriks kategori dan sudah dibekukan di CRF saat Kadep menyetujui.
                $target = ['value' => (float) $crf['sla_respon_nilai'], 'unit' => (string) $crf['sla_respon_satuan'], 'urgency' => $urgency];
                $picDue = (string) $crf['sla_respon_tenggat'];
                $startAt = !empty($crf['sla_mulai']) ? (string) $crf['sla_mulai'] : $now;
            } else {
                $target = crfRespTarget($pdo, $desired, $urgency);
            }
            $roundStmt = $pdo->prepare('SELECT COUNT(*) FROM crf_sla_respon WHERE pengajuan_id = :id AND tahap = :t');
            $roundStmt->execute(['id' => $crfId, 't' => $desired]);

            $pdo->prepare('
                INSERT INTO crf_sla_respon (pengajuan_id, tahap, putaran, urgensi, target_nilai, target_satuan, mulai, tenggat)
                VALUES (:id, :tahap, :putaran, :urgensi, :nilai, :satuan, :mulai, :tenggat)
            ')->execute([
                'id' => $crfId,
                'tahap' => $desired,
                'putaran' => (int) $roundStmt->fetchColumn() + 1,
                'urgensi' => $target['urgency'] ?? (in_array($urgency, ['Tinggi', 'Normal', 'Rendah'], true) ? $urgency : 'Normal'),
                'nilai' => $target['value'] ?? null,
                'satuan' => $target['unit'] ?? null,
                'mulai' => $startAt,
                'tenggat' => $desired === 'PIC_MULAI'
                    ? $picDue
                    : ($target ? slaDueAt($now, $target['value'], $target['unit']) : null),
            ]);
        }
    } catch (Throwable $e) {
        error_log('crfRespSync: ' . $e->getMessage());
    }
}

/** Semua putaran Response Time sebuah CRF, terlama dulu. */
function crfRespRows(PDO $pdo, int $crfId): array
{
    if (!crfRespTableReady($pdo)) {
        return [];
    }

    // Urut menurut waktu mulai (baris PIC hasil salinan migrasi punya id lebih besar dari tahap sesudahnya).
    $stmt = $pdo->prepare('SELECT * FROM crf_sla_respon WHERE pengajuan_id = :id ORDER BY mulai ASC, id ASC');
    $stmt->execute(['id' => $crfId]);

    return $stmt->fetchAll();
}

/**
 * Baris Response Time yang sedang berjalan untuk sebuah CRF (null bila tidak ada).
 * Semua baris terbuka dimuat sekali per permintaan (jumlahnya kecil), jadi aman
 * dipakai per baris di daftar tanpa query tambahan.
 */
function crfRespOpenRow(PDO $pdo, int $crfId): ?array
{
    static $map = null;

    if ($map === null) {
        $map = [];
        if (crfRespTableReady($pdo)) {
            try {
                foreach ($pdo->query('SELECT * FROM crf_sla_respon WHERE respon_at IS NULL ORDER BY id ASC')->fetchAll() as $row) {
                    $map[(int) $row['pengajuan_id']] = $row;
                }
            } catch (Throwable $e) {
                error_log('crfRespOpenRow: ' . $e->getMessage());
            }
        }
    }

    return $map[$crfId] ?? null;
}

/**
 * Status satu baris Response Time untuk tampilan: label, detail, class, alert
 * ('approaching' | 'overdue' | null), remaining, live. Pola sama dengan getCrfResponStatus().
 */
function crfRespRowStatus(array $row, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $startedAt = new DateTimeImmutable($row['mulai']);
    $dueAt = !empty($row['tenggat']) ? new DateTimeImmutable($row['tenggat']) : null;
    $base = ['alert' => null, 'remaining' => null, 'live' => false, 'elapsed' => null];

    if (!empty($row['respon_at'])) {
        $respondedAt = new DateTimeImmutable($row['respon_at']);
        $seconds = slaWorkingSecondsBetween($startedAt, $respondedAt);
        $elapsed = $seconds < 60 ? 'kurang dari 1 menit' : formatSlaDuration($seconds);
        $base['elapsed'] = $elapsed;

        if (($row['hasil'] ?? '') === 'Dibatalkan') {
            return $base + ['label' => 'Dibatalkan', 'class' => 'secondary', 'detail' => 'CRF dibatalkan sebelum direspons.'];
        }
        if ($dueAt === null) {
            return $base + ['label' => 'Sudah direspons', 'class' => 'secondary', 'detail' => 'Response time ' . $elapsed . ' (tanpa target).'];
        }
        if ($respondedAt <= $dueAt) {
            return $base + ['label' => 'Sesuai target', 'class' => 'success', 'detail' => 'Response time ' . $elapsed . ', sesuai target.'];
        }

        return $base + ['label' => 'Terlambat', 'class' => 'danger',
            'detail' => 'Response time ' . $elapsed . ', melebihi target ' . formatSlaDuration(slaWorkingSecondsBetween($dueAt, $respondedAt)) . '.'];
    }

    if ($dueAt === null) {
        return ['label' => 'Menunggu respon', 'class' => 'primary', 'detail' => 'Tanpa target response time.', 'alert' => null, 'remaining' => null, 'live' => true, 'elapsed' => null];
    }

    if ($now <= $dueAt) {
        $remaining = slaWorkingSecondsBetween($now, $dueAt);
        $approaching = $remaining <= crfSlaUrgentSeconds(slaWorkingSecondsBetween($startedAt, $dueAt));

        return [
            'label' => 'Masih dalam target',
            'detail' => 'Sisa response time ' . formatSlaDuration($remaining) . '.',
            'class' => $approaching ? 'warning' : 'success',
            'alert' => $approaching ? 'approaching' : null,
            'remaining' => $remaining,
            'live' => true,
            'elapsed' => null,
        ];
    }

    return [
        'label' => 'Melewati target',
        'detail' => 'Terlambat ' . formatSlaDuration(slaWorkingSecondsBetween($dueAt, $now)) . ' dari batas response time.',
        'class' => 'danger',
        'alert' => 'overdue',
        'remaining' => null,
        'overdue' => slaWorkingSecondsBetween($dueAt, $now),
        'live' => true,
        'elapsed' => null,
    ];
}

function crfSlaEquals(?array $sla, $value, ?string $unit): bool
{
    return $sla !== null
        && is_numeric($value)
        && abs((float) $value - $sla['value']) < 0.001
        && $unit === $sla['unit'];
}

function crfImpactLabel(?string $impact): string
{
    $options = crfImpactOptions();
    if (isset($options[$impact ?? ''])) {
        return $options[$impact];
    }

    $legacyOptions = [
        'high' => 'Fungsi Aplikasi SIAP berhenti total, tanpa ada solusi alternatif, dan dapat menimbulkan risiko likuiditas dan hukum.',
        'normal' => 'Operasional terganggu berat, aplikasi masih bisa diakses sebagian atau ada solusi alternatif sementara yang merepotkan, serta dapat menimbulkan risiko operasional.',
        'low' => 'Masalah atau permintaan fitur baru, tidak mempengaruhi efisiensi kerja, tidak menghentikan operasional harian, dan tidak menimbulkan risiko.',
    ];

    return $legacyOptions[$impact ?? ''] ?? '';
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
 * mengunggah file ke Wasabi (private) dan mencatatnya ke tabel crf_lampiran.
 *
 * @return string[] daftar pesan error (kosong jika semua berhasil / tidak ada file)
 */
function handleAttachmentUploads(PDO $pdo, int $crfId, array $filesInput): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO crf_lampiran
        (pengajuan_id, nama_asli, nama_tersimpan, path_file, tipe_file, ukuran_file)
        VALUES
        (:owner_id, :nama_asli, :nama_tersimpan, :path_file, :tipe_file, :ukuran_file)'
    );

    return uploadAttachmentsToStorage($filesInput, 'crf_' . $crfId . '_', $stmt, $crfId);
}

/**
 * Dokumen hasil UAT: validasi dan penyimpanan sama dengan lampiran CRF,
 * dicatat dengan category 'uat' agar terpisah dari lampiran pengajuan.
 *
 * @return string[] daftar pesan error (kosong jika semua berhasil / tidak ada file)
 */
function handleUatDocumentUploads(PDO $pdo, int $crfId, array $filesInput): array
{
    $stmt = $pdo->prepare(
        "INSERT INTO crf_lampiran
        (pengajuan_id, nama_asli, nama_tersimpan, path_file, tipe_file, ukuran_file, kategori)
        VALUES
        (:owner_id, :nama_asli, :nama_tersimpan, :path_file, :tipe_file, :ukuran_file, 'uat')"
    );

    return uploadAttachmentsToStorage($filesInput, 'crf_' . $crfId . '_uat_', $stmt, $crfId);
}

/**
 * Konfigurasi Wasabi (config/wasabi.php).
 */
function crfWasabiConfig(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/wasabi.php';
    }

    return $config;
}

function crfWasabiClient(): S3Client
{
    static $client = null;

    if ($client === null) {
        $config = crfWasabiConfig();
        $client = new S3Client([
            'version' => $config['version'],
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'credentials' => $config['credentials'],
            'use_path_style_endpoint' => $config['use_path_style_endpoint'],
        ]);
    }

    return $client;
}

/**
 * Key objek Wasabi untuk sebuah lampiran (upload_path + nama tersimpan).
 */
function crfAttachmentKey(string $storedName): string
{
    return rtrim(crfWasabiConfig()['upload_path'], '/') . '/' . basename($storedName);
}

/**
 * Validasi lalu upload file ke Wasabi, dan catat lewat $insertStmt
 * (parameter: owner_id, nama_asli, nama_tersimpan, path_file, tipe_file, ukuran_file).
 *
 * @return string[] daftar pesan error
 */
function uploadAttachmentsToStorage(array $filesInput, string $storedPrefix, PDOStatement $insertStmt, int $ownerId): array
{
    $errors = [];

    if (empty($filesInput['name']) || empty($filesInput['name'][0])) {
        return $errors;
    }

    $wasabiConfig = crfWasabiConfig();
    $s3Client = crfWasabiClient();

    $bucket = $wasabiConfig['bucket'];

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
        $storedName = uniqid($storedPrefix, true) . '.' . $ext;

        // Path file di Wasabi
        $key = crfAttachmentKey($storedName);

        try {

            // Upload ke Wasabi sebagai objek private: file hanya dapat dibuka
            // lewat actions/download_attachment.php (setelah cek hak akses).
            $result = $s3Client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'SourceFile' => $tmpPath,
                'ACL' => 'private',
                'ContentType' => $mimeType ?: 'application/octet-stream',
            ]);

            // URL file di Wasabi
            $fileUrl = $result['ObjectURL'];

            // Simpan informasi file ke database
            $insertStmt->execute([
                'owner_id' => $ownerId,
                'nama_asli' => $originalName,
                'nama_tersimpan' => $storedName,
                'path_file' => $fileUrl,
                'tipe_file' => $mimeType ?: null,
                'ukuran_file' => $size,
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
