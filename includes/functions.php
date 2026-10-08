<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/../config/sla.php';

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
            return 'Menunggu Pemeriksaan';
        case 'CMO_FILTER':
            return 'Verifikasi CMO';
        case 'OTOMASI':
            return 'Tindak Lanjut Divisi Otomasi';
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
 * Tambah durasi SLA yang hanya berjalan di hari kerja.
 * Contoh: Jumat 16:00 + 1 Hari = Senin 16:00.
 */
function slaAddWorkingSeconds(DateTimeInterface $start, int $seconds): DateTimeImmutable
{
    $cursor = DateTimeImmutable::createFromInterface($start);

    while (true) {
        if (!slaIsWorkingDay($cursor)) {
            $cursor = $cursor->modify('tomorrow');
            continue;
        }

        $dayEnd = $cursor->modify('tomorrow');
        $available = $dayEnd->getTimestamp() - $cursor->getTimestamp();

        if ($seconds <= $available) {
            return $cursor->modify('+' . $seconds . ' seconds');
        }

        $seconds -= $available;
        $cursor = $dayEnd;
    }
}

/**
 * Durasi (detik) di antara dua waktu yang jatuh pada hari kerja saja.
 */
function slaWorkingSecondsBetween(DateTimeInterface $start, DateTimeInterface $end): int
{
    $cursor = DateTimeImmutable::createFromInterface($start);
    $end = DateTimeImmutable::createFromInterface($end);
    $total = 0;

    while ($cursor < $end) {
        $dayEnd = $cursor->modify('tomorrow');
        $segmentEnd = $dayEnd < $end ? $dayEnd : $end;

        if (slaIsWorkingDay($cursor)) {
            $total += $segmentEnd->getTimestamp() - $cursor->getTimestamp();
        }

        $cursor = $segmentEnd;
    }

    return $total;
}

function formatSlaDuration(int $seconds): string
{
    $minutes = max(0, intdiv($seconds, 60));
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
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

function getCrfSlaStatus(array $crf, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $startedAt = !empty($crf['sla_started_at'])
        ? new DateTimeImmutable($crf['sla_started_at'])
        : null;
    $dueAt = !empty($crf['sla_due_at'])
        ? new DateTimeImmutable($crf['sla_due_at'])
        : null;
    $completedAt = !empty($crf['automation_completed_at'])
        ? new DateTimeImmutable($crf['automation_completed_at'])
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
            $remaining = $dueAt->getTimestamp() - $completedAt->getTimestamp();
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

        $overdue = $completedAt->getTimestamp() - $dueAt->getTimestamp();
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
            'label' => 'SLA belum dimulai',
            'detail' => 'SLA dimulai setelah persetujuan Kepala Departemen Operasional.',
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
 * user_id diambil dari session bila tersedia.
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
        INSERT INTO crf_activity_logs (
            change_request_id,
            user_id,
            activity,
            description,
            actor,
            old_status,
            new_status
        ) VALUES (
            :change_request_id,
            :user_id,
            :activity,
            :description,
            :actor,
            :old_status,
            :new_status
        )
    ");

    $stmt->execute([
        'change_request_id' => $crfId,
        'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'activity' => $activity,
        'description' => $description,
        'actor' => $actor,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
    ]);
}

/**
 * Status tampilan CRF (label sesuai alur Helpdesk/CRF) yang diturunkan
 * dari kombinasi status + workflow_stage. ENUM database tidak diubah.
 *
 * @return array{key:string,label:string,class:string}
 */
function crfDisplayStatus(array $crf): array
{
    $status = (string) ($crf['status'] ?? '');
    $stage = (string) ($crf['workflow_stage'] ?? '');
    $approved = !empty($crf['kadep_operasional_approved_at']);

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
        return ['key' => 'revisi', 'label' => 'Ditolak / Perlu Revisi', 'class' => 'badge-status-revisi'];
    }
    if ($stage === 'CMO_FILTER' && !empty($crf['forum_discussion_open'])) {
        return ['key' => 'pembahasan', 'label' => 'Menunggu Pembahasan Forum', 'class' => 'badge-status-pembahasan'];
    }
    if ($stage === 'CMO_FILTER') {
        return ['key' => 'review', 'label' => 'Menunggu Verifikasi', 'class' => 'badge-status-belum'];
    }
    if ($stage === 'kadep_operasional') {
        return ['key' => 'approval', 'label' => 'Menunggu Persetujuan', 'class' => 'badge-stage-joko'];
    }
    if ($stage === 'OTOMASI' && $approved) {
        return ['key' => 'disetujui', 'label' => 'Disetujui · Eksekusi', 'class' => 'badge-stage-otomasi'];
    }
    if ($stage === 'PEMOHON_PIR') {
        return ['key' => 'pir', 'label' => 'Menunggu PIR Pemohon', 'class' => 'badge-stage-pir'];
    }
    if ($stage === 'CMO_FINAL') {
        return ['key' => 'finalisasi', 'label' => 'Menunggu Finalisasi', 'class' => 'badge-stage-cmo-final'];
    }

    return ['key' => 'submitted', 'label' => 'Submitted', 'class' => 'badge-status-belum'];
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
        'review'     => ['label' => 'Menunggu Verifikasi', 'sql' => "{$a}status = 'Belum Ditindak Lanjuti' AND {$a}workflow_stage = 'CMO_FILTER' AND {$a}forum_discussion_open = 0"],
        'pembahasan' => ['label' => 'Menunggu Pembahasan Forum', 'sql' => "{$a}workflow_stage = 'CMO_FILTER' AND {$a}forum_discussion_open = 1 AND {$a}status NOT IN ('Draft','Solve','Cancel')"],
        'approval'   => ['label' => 'Menunggu Persetujuan', 'sql' => "{$a}status = 'Dalam Proses' AND {$a}workflow_stage = 'kadep_operasional'"],
        'disetujui'  => ['label' => 'Disetujui · Eksekusi', 'sql' => "{$a}status = 'Dalam Proses' AND {$a}workflow_stage IN ('OTOMASI','PEMOHON_PIR','CMO_FINAL') AND {$a}kadep_operasional_approved_at IS NOT NULL"],
        'revisi'     => ['label' => 'Ditolak / Perlu Revisi', 'sql' => "{$a}status = 'Perlu Revisi'"],
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
        SELECT sla_started_at, sla_due_at, automation_completed_at
        FROM change_requests
        WHERE id = :id
    ');
    $stmt->execute(['id' => $crfId]);
    $row = $stmt->fetch();

    if (!$row || empty($row['sla_started_at']) || empty($row['automation_completed_at'])) {
        return;
    }

    $started = new DateTimeImmutable($row['sla_started_at']);
    $completed = new DateTimeImmutable($row['automation_completed_at']);
    // Durasi aktual hanya menghitung hari kerja, sama seperti batas SLA.
    $minutes = (int) round(slaWorkingSecondsBetween($started, $completed) / 60);
    $result = null;

    if (!empty($row['sla_due_at'])) {
        $result = $completed->getTimestamp() <= strtotime($row['sla_due_at']) ? 'Sesuai SLA' : 'Melebihi SLA';
    }

    $update = $pdo->prepare('
        UPDATE change_requests
        SET sla_actual_minutes = :minutes, sla_result = :result
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
        SELECT COUNT(*) AS total, MAX(created_at) AS last_at
        FROM crf_activity_logs
        WHERE change_request_id = :id AND activity = 'Pengingat PIR'
    ");
    $stmt->execute(['id' => (int) $crf['id']]);
    $row = $stmt->fetch() ?: ['total' => 0, 'last_at' => null];

    $lastAt = $row['last_at'] ?: null;
    $nextAt = $lastAt !== null ? date('Y-m-d H:i:s', strtotime($lastAt) + CRF_PIR_REMINDER_INTERVAL) : null;
    $waitingDays = !empty($crf['automation_completed_at'])
        ? (int) floor(slaWorkingSecondsBetween(new DateTimeImmutable($crf['automation_completed_at']), new DateTimeImmutable()) / 86400)
        : null;

    return [
        'count' => (int) $row['total'],
        'last_at' => $lastAt,
        'can_send' => ($crf['workflow_stage'] ?? '') === 'PEMOHON_PIR' && ($nextAt === null || time() >= strtotime($nextAt)),
        'next_at' => $nextAt,
        'waiting_days' => $waitingDays,
    ];
}

/**
 * Label ticket untuk tampilan & notifikasi (tanpa nomor ticket, mengikuti
 * SIAP): "Kategori · dd-mm-YYYY HH:ii".
 * $ticket butuh category_name & created_at.
 */
function helpdeskTicketLabel(array $ticket): string
{
    $parts = [];
    if (!empty($ticket['category_name'])) {
        $parts[] = (string) $ticket['category_name'];
    }
    if (!empty($ticket['created_at'])) {
        $parts[] = date('d-m-Y H:i', strtotime((string) $ticket['created_at']));
    }

    return $parts ? implode(' · ', $parts) : 'Ticket Helpdesk';
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
            cr.request_number LIKE :list_search_request
            OR cr.full_name LIKE :list_search_name
            OR cr.change_description LIKE :list_search_description
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
        $where[] = 'cr.from_department = :list_department';
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
        $where[] = 'cr.submission_date >= :list_date_from';
        $params['list_date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'cr.submission_date <= :list_date_to';
        $params['list_date_to'] = $dateTo;
    }

    $categoryId = (int) $filterString($filters['category_id'] ?? '');
    if ($categoryId > 0) {
        $where[] = 'cr.crf_category_id = :list_category_id';
        $params['list_category_id'] = $categoryId;
    } else {
        $categoryId = 0;
    }

    $displayStatus = $filterString($filters['display_status'] ?? '');
    $displayConditions = crfDisplayStatusConditions('cr');
    if (isset($displayConditions[$displayStatus])) {
        $where[] = '(' . $displayConditions[$displayStatus]['sql'] . ')';
    } else {
        $displayStatus = '';
    }

    $departmentStmt = $pdo->query("
        SELECT DISTINCT from_department
        FROM change_requests
        WHERE status <> 'Draft'
          AND from_department IS NOT NULL
          AND from_department <> ''
        ORDER BY from_department
    ");

    $categories = [];
    try {
        $categories = $pdo->query('
            SELECT id, name FROM crf_categories
            WHERE deleted_at IS NULL
            ORDER BY sort_order, name
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

function crfImpactGroups(): array
{
    return [
        'Tinggi' => [
            'high_system_stopped' => 'Fungsi Aplikasi SIAP berhenti total.',
            'high_no_alternative' => 'Belum ada alternatif.',
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

/**
 * Matriks SLA satu kategori CRF per level urgensi.
 * Kolom sla_value / sla_unit = SLA Normal; Tinggi/Rendah kosong ikut Normal.
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
    $normal = $pick($category['sla_value'] ?? null, $category['sla_unit'] ?? null);

    return [
        'Tinggi' => $pick($category['sla_tinggi_value'] ?? null, $category['sla_tinggi_unit'] ?? null) ?? $normal,
        'Normal' => $normal,
        'Rendah' => $pick($category['sla_rendah_value'] ?? null, $category['sla_rendah_unit'] ?? null) ?? $normal,
    ];
}

/**
 * Level urgensi yang berlaku untuk CRF: final Forum > level tersimpan > otomatis dari dampak.
 */
function crfEffectiveUrgency(array $crf): ?string
{
    return ($crf['final_urgency_level'] ?? null)
        ?: ($crf['level'] ?? null)
        ?: crfUrgencyForImpact($crf['impact_category'] ?? null);
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
 * memindahkan file ke folder uploads/ dan mencatatnya ke tabel attachments.
 *
 * @return string[] daftar pesan error (kosong jika semua berhasil / tidak ada file)
 */
function handleAttachmentUploads(PDO $pdo, int $crfId, array $filesInput): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO attachments
        (change_request_id, original_name, stored_name, file_path, file_type, file_size)
        VALUES
        (:owner_id, :original_name, :stored_name, :file_path, :file_type, :file_size)'
    );

    return uploadAttachmentsToStorage($filesInput, 'crf_' . $crfId . '_', $stmt, $crfId);
}

/**
 * Lampiran ticket Helpdesk: validasi & penyimpanan sama dengan lampiran CRF.
 *
 * @return string[]
 */
function handleHelpdeskAttachmentUploads(PDO $pdo, int $ticketId, array $filesInput): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO helpdesk_attachments
        (helpdesk_ticket_id, original_name, stored_name, file_path, file_type, file_size)
        VALUES
        (:owner_id, :original_name, :stored_name, :file_path, :file_type, :file_size)'
    );

    return uploadAttachmentsToStorage($filesInput, 'hd_' . $ticketId . '_', $stmt, $ticketId);
}

/**
 * Validasi lalu upload file ke Wasabi, dan catat lewat $insertStmt
 * (parameter: owner_id, original_name, stored_name, file_path, file_type, file_size).
 *
 * @return string[] daftar pesan error
 */
function uploadAttachmentsToStorage(array $filesInput, string $storedPrefix, PDOStatement $insertStmt, int $ownerId): array
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
        $storedName = uniqid($storedPrefix, true) . '.' . $ext;

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
            $insertStmt->execute([
                'owner_id' => $ownerId,
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
