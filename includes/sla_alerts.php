<?php
/**
 * includes/sla_alerts.php
 * ---------------------------------------------------------------
 * Peringatan Resolution Time untuk PIC Otomasi, dan (crfSendTahapAlerts) peringatan Response Time
 * per tahap untuk CMO, Kadep, dan PIC Otomasi; terlambat pertama dieskalasi ke Admin.
 * Dihitung dalam waktu kerja (sama dengan batas SLA). Resolution Time berjalan sejak Kepala
 * Departemen Operasional menyetujui sampai PIC Otomasi menekan Selesai Pengerjaan.
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
            $title = 'Resolution time terlewati: ' . $number;
            $message = 'CRF ' . $number . ' sudah melewati batas resolution time (' . $due . ') sejak '
                . formatSlaDuration(abs($stage['remaining'])) . ' yang lalu. Segera selesaikan pengerjaan.';
        } elseif ($type === 'mendesak') {
            $title = 'Resolution time mendesak: ' . $number;
            $message = 'CRF ' . $number . ' hampir melewati batas resolution time. Sisa waktu ' . formatSlaDuration($stage['remaining'])
                . ', batas ' . $due . '.';
        } else {
            $title = 'Pengingat resolution time: ' . $number;
            $message = 'CRF ' . $number . ' sudah memakai separuh waktu resolution time. Sisa waktu '
                . formatSlaDuration($stage['remaining']) . ', batas ' . $due . '.';
        }

        try {
            $pdo->beginTransaction();
            // Penanda lebih dulu: UNIQUE mencegah kirim ganda bila skrip jalan bersamaan.
            $insert->execute(['id' => $crfId, 'type' => $type, 'seq' => $seq, 'tgl_dikirim' => $now->format('Y-m-d H:i:s')]);
            // WhatsApp hanya untuk SLA mendesak dan terlambat (pengingat 50% cukup in-app dan email).
            notifyUsers($pdo, $recipients, $title, $message, 'crf/open.php?id=' . $crfId, $crfId, null, in_array($type, ['mendesak', 'terlambat'], true));
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

    $sent += crfSendTahapAlerts($pdo, $now);

    if ($sent > 0) {
        dispatchPendingNotificationEmails($pdo);
    }

    return $sent;
}

/**
 * Peringatan Response Time per tahap (CMO verifikasi / UAT / finalisasi, Kadep, dan PIC mulai
 * dikerjakan; menggantikan crfSendResponAlerts lama). Dikirim ke peran yang sedang memegang CRF
 * dengan jenjang yang sama seperti Resolution Time (lihat crfSlaAlertStage):
 *
 *   pengingat : 50% waktu response terpakai (dilewati bila target di bawah 1 jam), sekali
 *   mendesak  : sisa waktu <= min(25% target, 1 jam), sekali
 *   terlambat : melewati batas, lalu diulang tiap hari kerja; Admin menerima eskalasi pada yang pertama
 *
 * Yang dikirim hanya jenjang tertinggi yang berlaku. Penanda anti ganda ada di kolom
 * peringatan_*_at dan peringatan_terlambat_urutan pada crf_sla_respon.
 *
 * @return int jumlah peringatan yang dikirim
 */
function crfSendTahapAlerts(PDO $pdo, DateTimeImmutable $now): int
{
    if (!crfRespTableReady($pdo)) {
        return 0;
    }

    try {
        $rows = $pdo->query("
            SELECT r.id, r.pengajuan_id, r.tahap, r.mulai, r.tenggat,
                   r.peringatan_pengingat_at, r.peringatan_mendesak_at,
                   r.peringatan_terlambat_at, r.peringatan_terlambat_urutan,
                   cr.nomor_register, cr.kategori_id, cr.pic_id
            FROM crf_sla_respon r
            JOIN crf_pengajuan cr ON cr.id = r.pengajuan_id
            WHERE r.respon_at IS NULL
              AND r.tenggat IS NOT NULL
              AND cr.status NOT IN ('Draft','Solve','Cancel')
        ")->fetchAll();
    } catch (PDOException $e) {
        // Kolom peringatan baru belum ada (migrasi 028 versi lama): lewati.
        error_log('crfSendTahapAlerts: ' . $e->getMessage());
        return 0;
    }

    $stages = crfRespStages();
    $markReminder = $pdo->prepare('UPDATE crf_sla_respon SET peringatan_pengingat_at = :at WHERE id = :id AND peringatan_pengingat_at IS NULL');
    $markUrgent = $pdo->prepare('UPDATE crf_sla_respon SET peringatan_mendesak_at = :at WHERE id = :id AND peringatan_mendesak_at IS NULL');
    $markLate = $pdo->prepare('UPDATE crf_sla_respon SET peringatan_terlambat_at = :at, peringatan_terlambat_urutan = peringatan_terlambat_urutan + 1 WHERE id = :id AND peringatan_terlambat_urutan = :prev');

    $sent = 0;
    foreach ($rows as $row) {
        $started = new DateTimeImmutable($row['mulai']);
        $due = new DateTimeImmutable($row['tenggat']);
        $total = slaWorkingSecondsBetween($started, $due);
        if ($total <= 0) {
            continue;
        }

        $lateCount = (int) $row['peringatan_terlambat_urutan'];
        if ($now > $due) {
            $type = 'terlambat';
            $remaining = -slaWorkingSecondsBetween($due, $now);
            if ($lateCount > 0
                && (!slaIsWorkingDay($now)
                    || $now->getTimestamp() - strtotime((string) $row['peringatan_terlambat_at']) < CRF_SLA_OVERDUE_REPEAT_SECONDS)
            ) {
                continue;
            }
        } else {
            $elapsed = slaWorkingSecondsBetween($started, $now);
            $remaining = max(0, $total - $elapsed);
            if ($remaining <= crfSlaUrgentSeconds($total)) {
                if (!empty($row['peringatan_mendesak_at'])) {
                    continue;
                }
                $type = 'mendesak';
            } elseif ($total >= CRF_SLA_REMINDER_MIN_SECONDS && $elapsed >= $total * 0.5) {
                if (!empty($row['peringatan_pengingat_at'])) {
                    continue;
                }
                $type = 'pengingat';
            } else {
                continue;
            }
        }

        if ($row['tahap'] === 'PIC_MULAI') {
            $recipients = crfSlaAlertRecipients($pdo, $row);
        } else {
            $recipients = crfUserIdsForRole($pdo, $row['tahap'] === 'KADEP' ? 'kadep_operasional' : 'cmo');
        }
        if (!$recipients) {
            continue;
        }

        $crfId = (int) $row['pengajuan_id'];
        $number = (string) ($row['nomor_register'] ?: 'CRF #' . $crfId);
        $label = $stages[$row['tahap']]['label'] ?? $row['tahap'];
        $waiting = $row['tahap'] === 'PIC_MULAI' ? 'belum dikerjakan' : 'menunggu ' . $label;
        $dueText = date('d-m-Y H:i', strtotime($row['tenggat']));

        if ($type === 'terlambat') {
            $title = 'Response time terlewati: ' . $number;
            $message = 'CRF ' . $number . ' ' . $waiting . ' dan sudah melewati batas response time (' . $dueText . ') sejak '
                . formatSlaDuration(abs($remaining)) . ' yang lalu. '
                . ($row['tahap'] === 'PIC_MULAI' ? 'Segera tekan Mulai kerjakan.' : 'Mohon segera ditindaklanjuti.');
        } elseif ($type === 'mendesak') {
            $title = 'Response time mendesak: ' . $number;
            $message = 'CRF ' . $number . ' ' . $waiting . '. Sisa response time ' . formatSlaDuration($remaining)
                . ', batas ' . $dueText . '.';
        } else {
            $title = 'Pengingat response time: ' . $number;
            $message = 'CRF ' . $number . ' ' . $waiting . ' dan sudah memakai separuh waktu response time. Sisa '
                . formatSlaDuration($remaining) . ', batas ' . $dueText . '.';
        }

        try {
            $pdo->beginTransaction();
            $at = $now->format('Y-m-d H:i:s');
            if ($type === 'terlambat') {
                $markLate->execute(['at' => $at, 'id' => $row['id'], 'prev' => $lateCount]);
                $claimed = $markLate->rowCount() === 1;
            } elseif ($type === 'mendesak') {
                $markUrgent->execute(['at' => $at, 'id' => $row['id']]);
                $claimed = $markUrgent->rowCount() === 1;
            } else {
                $markReminder->execute(['at' => $at, 'id' => $row['id']]);
                $claimed = $markReminder->rowCount() === 1;
            }
            if (!$claimed) {
                $pdo->rollBack();
                continue;
            }

            // WhatsApp hanya untuk mendesak dan terlambat (pengingat 50% cukup di aplikasi dan email).
            $useWa = $type !== 'pengingat';
            notifyUsers($pdo, $recipients, $title, $message, 'crf/open.php?id=' . $crfId, $crfId, null, $useWa);

            if ($type === 'terlambat' && $lateCount === 0) {
                $adminIds = array_values(array_diff(crfUserIdsForRole($pdo, 'admin'), $recipients));
                if ($adminIds) {
                    notifyUsers($pdo, $adminIds, 'Eskalasi: ' . $title, $message, 'crf/open.php?id=' . $crfId, $crfId, null, true);
                }
            }

            logCrfActivity($pdo, $crfId, 'Peringatan SLA', $title . '. ' . $message, 'Sistem');
            $pdo->commit();
            $sent++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('crfSendTahapAlerts: ' . $e->getMessage());
        }
    }

    return $sent;
}
