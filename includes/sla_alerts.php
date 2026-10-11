<?php
/**
 * includes/sla_alerts.php
 * ---------------------------------------------------------------
 * Peringatan SLA untuk PIC CRF (hanya PIC CRF, tidak ke atasan).
 * Dihitung dalam waktu kerja (sama dengan batas SLA), sejak Kepala
 * Departemen Operasional menyetujui sampai PIC CRF menekan Selesaikan.
 *
 *   pengingat : 50% waktu SLA terpakai (dilewati bila SLA < 1 jam)
 *   mendesak  : sisa waktu <= min(25% SLA, 1 jam)
 *   terlambat : melewati batas SLA, lalu diulang tiap hari kerja
 *
 * Yang dikirim hanya tahap tertinggi yang sedang berlaku. Penanda anti
 * kirim ganda ada di tabel crf_peringatan_sla. Dijalankan berkala oleh
 * database/maintenance/kirim_peringatan_sla.php.
 * ---------------------------------------------------------------
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

/** Jarak pengingat "terlambat" berulang (detik). */
const CRF_SLA_OVERDUE_REPEAT_SECONDS = 86400;

/** Batas bawah SLA (detik kerja) agar peringatan "pengingat" 50% dikirim. */
const CRF_SLA_REMINDER_MIN_SECONDS = 3600;

/**
 * Tahap peringatan yang berlaku untuk sebuah CRF saat ini, atau null.
 *
 * @return array{type:string,remaining:int,total:int}|null
 */
function crfSlaAlertStage(array $crf, ?DateTimeImmutable $now = null): ?array
{
    if (empty($crf['sla_mulai']) || empty($crf['sla_tenggat'])) {
        return null;
    }

    $now = $now ?? new DateTimeImmutable('now');
    $started = new DateTimeImmutable($crf['sla_mulai']);
    $due = new DateTimeImmutable($crf['sla_tenggat']);

    $total = slaWorkingSecondsBetween($started, $due);
    if ($total <= 0) {
        return null;
    }

    if ($now > $due) {
        return ['type' => 'terlambat', 'remaining' => -slaWorkingSecondsBetween($due, $now), 'total' => $total];
    }

    $elapsed = slaWorkingSecondsBetween($started, $now);
    $remaining = max(0, $total - $elapsed);

    if ($remaining <= crfSlaUrgentSeconds($total)) {
        return ['type' => 'mendesak', 'remaining' => $remaining, 'total' => $total];
    }
    if ($total >= CRF_SLA_REMINDER_MIN_SECONDS && $elapsed >= $total * 0.5) {
        return ['type' => 'pengingat', 'remaining' => $remaining, 'total' => $total];
    }

    return null;
}

/** PIC CRF penerima: pemegang CRF, atau seluruh PIC kategori. */
function crfSlaAlertRecipients(PDO $pdo, array $crf): array
{
    if (!empty($crf['pic_id'])) {
        return [(int) $crf['pic_id']];
    }

    $ids = !empty($crf['kategori_id'])
        ? crfCategoryHandlerIds($pdo, (int) $crf['kategori_id'])
        : [];

    return $ids ?: crfUserIdsForRole($pdo, 'otomasi');
}

/**
 * Kirim peringatan SLA yang jatuh tempo. Aman dipanggil berulang.
 *
 * @return int jumlah peringatan yang dikirim
 */
function crfSendSlaAlerts(PDO $pdo, ?DateTimeImmutable $now = null): int
{
    $now = $now ?? new DateTimeImmutable('now');

    $rows = $pdo->query("
        SELECT id, nomor_register, kategori_id, pic_id,
               sla_mulai, sla_tenggat
        FROM crf_pengajuan
        WHERE status = 'Dalam Proses'
          AND tahap_alur = 'OTOMASI'
          AND tgl_disetujui_kadep IS NOT NULL
          AND otomasi_selesai IS NULL
          AND sla_mulai IS NOT NULL
          AND sla_tenggat IS NOT NULL
    ")->fetchAll();

    $lastStmt = $pdo->prepare('
        SELECT MAX(urutan_peringatan) AS seq, MAX(tgl_dikirim) AS last_at
        FROM crf_peringatan_sla
        WHERE pengajuan_id = :id AND jenis_peringatan = :type
    ');
    $insert = $pdo->prepare('
        INSERT INTO crf_peringatan_sla (pengajuan_id, jenis_peringatan, urutan_peringatan, tgl_dikirim)
        VALUES (:id, :type, :seq, :tgl_dikirim)
    ');

    $sent = 0;
    foreach ($rows as $crf) {
        $stage = crfSlaAlertStage($crf, $now);
        if ($stage === null) {
            continue;
        }

        $crfId = (int) $crf['id'];
        $type = $stage['type'];

        $lastStmt->execute(['id' => $crfId, 'type' => $type]);
        $last = $lastStmt->fetch() ?: ['seq' => null, 'last_at' => null];
        $seq = (int) ($last['seq'] ?? 0) + 1;

        if ($seq > 1) {
            // Hanya "terlambat" yang diulang, tiap hari kerja.
            if ($type !== 'terlambat'
                || !slaIsWorkingDay($now)
                || $now->getTimestamp() - strtotime((string) $last['last_at']) < CRF_SLA_OVERDUE_REPEAT_SECONDS
            ) {
                continue;
            }
        }

        $recipients = crfSlaAlertRecipients($pdo, $crf);
        if (!$recipients) {
            continue;
        }

        $number = (string) ($crf['nomor_register'] ?: 'CRF #' . $crfId);
        $due = date('d-m-Y H:i', strtotime($crf['sla_tenggat']));

        if ($type === 'terlambat') {
            $title = 'SLA terlewati: ' . $number;
            $message = 'CRF ' . $number . ' sudah melewati batas SLA (' . $due . ') sejak '
                . formatSlaDuration(abs($stage['remaining'])) . ' yang lalu. Segera selesaikan eksekusi.';
        } elseif ($type === 'mendesak') {
            $title = 'SLA mendesak: ' . $number;
            $message = 'CRF ' . $number . ' hampir melewati SLA. Sisa waktu ' . formatSlaDuration($stage['remaining'])
                . ', batas ' . $due . '.';
        } else {
            $title = 'Pengingat SLA: ' . $number;
            $message = 'CRF ' . $number . ' sudah memakai separuh waktu SLA. Sisa waktu '
                . formatSlaDuration($stage['remaining']) . ', batas ' . $due . '.';
        }

        try {
            $pdo->beginTransaction();
            // Penanda lebih dulu: UNIQUE mencegah kirim ganda bila skrip jalan bersamaan.
            $insert->execute(['id' => $crfId, 'type' => $type, 'seq' => $seq, 'tgl_dikirim' => $now->format('Y-m-d H:i:s')]);
            notifyUsers($pdo, $recipients, $title, $message, 'crf/open.php?id=' . $crfId, $crfId);
            logCrfActivity($pdo, $crfId, 'Peringatan SLA', $title . '. ' . $message, 'Sistem');
            $pdo->commit();
            $sent++;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() !== '23000') {
                error_log('crfSendSlaAlerts: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('crfSendSlaAlerts: ' . $e->getMessage());
        }
    }

    if ($sent > 0) {
        dispatchPendingNotificationEmails($pdo);
    }

    return $sent;
}
