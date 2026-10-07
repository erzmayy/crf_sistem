<?php
/**
 * includes/forum_discussions.php
 * ---------------------------------------------------------------
 * Pembahasan Level Urgensi & SLA di Forum.
 *
 *   1. SLA default terisi sejak pemohon submit (matriks Kategori x Urgensi).
 *   2. CMO screening: teruskan ke Kepala Departemen, ATAU "Ajukan Pembahasan
 *      Forum". Selama pembahasan terbuka CRF bertanda "Menunggu Pembahasan
 *      Forum" dan CMO tidak dapat meneruskannya.
 *   3. Pengguna Forum hanya berkomentar. Level/SLA tidak diubah dari form diskusi.
 *   4. Hasil pembahasan: TETAP (nilai sistem dipakai) atau DIUBAH (nilai kesepakatan).
 *   5. Setelah hasil dicatat, CRF langsung diteruskan ke Kepala Departemen
 *      Operasional untuk persetujuan (tanpa kembali ke verifikasi CMO).
 *   6. Hanya penentu (forumResultRoles(), saat ini CMO dan Admin) yang mencatat hasil dan
 *      menerapkannya ke data CRF, lengkap nilai sebelum/sesudah, alasan, waktu, actor.
 *
 * Satu CRF hanya punya satu pembahasan terbuka (UNIQUE open_crf_id).
 * ---------------------------------------------------------------
 */
require_once __DIR__ . '/forum.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/helpdesk.php';

/** Target waktu pembahasan (hari kerja). Hanya target proses, bukan SLA CRF. */
const FORUM_DISCUSSION_DECISION_DAYS = 2;

const FORUM_URGENCIES = ['Tinggi', 'Normal', 'Rendah'];
const FORUM_SLA_UNITS = ['Menit', 'Jam', 'Hari'];

/* =================================================================
 * Label & format
 * ================================================================= */

/** Nama peran untuk kalimat riwayat (akun demo disingkat agar tidak bertingkat kurung). */
function forumRoleName(string $role): string
{
    return $role === 'demo' ? 'Demo' : crfRoleLabel($role);
}

/**
 * @return array{label:string,class:string}
 */
function forumOutcomeMeta(?string $outcome): array
{
    switch ($outcome) {
        case 'tetap':
            return ['label' => 'Tetap', 'class' => 'success'];
        case 'diubah':
            return ['label' => 'Diubah', 'class' => 'warning'];
        default:
            return ['label' => 'Dibatalkan', 'class' => 'secondary'];
    }
}

/** "Tinggi · 2 Hari" / "2 Hari" / "-" */
function forumValuesText(?string $urgency, $value, ?string $unit): string
{
    $parts = [];
    if ($urgency) {
        $parts[] = $urgency;
    }
    if ($value !== null && $value !== '' && $unit) {
        $parts[] = slaLabel($value, $unit);
    }

    return $parts ? implode(' · ', $parts) : '-';
}

/* =================================================================
 * Validasi input (melempar DomainException berpesan ramah)
 * ================================================================= */

function forumParseUrgency($value): string
{
    if (!is_string($value) || !in_array($value, FORUM_URGENCIES, true)) {
        throw new DomainException('Level urgensi wajib dipilih.');
    }

    return $value;
}

/**
 * @return array{0:float,1:string}
 */
function forumParseSla($value, $unit): array
{
    if (
        !is_scalar($value)
        || !is_numeric($value)
        || !is_finite((float) $value)
        || (float) $value < 0.01
        || (float) $value > 99999999.99
    ) {
        throw new DomainException('Nilai SLA wajib diisi dengan angka yang benar.');
    }
    if (!is_string($unit) || !in_array($unit, FORUM_SLA_UNITS, true)) {
        throw new DomainException('Satuan SLA wajib dipilih.');
    }

    return [round((float) $value, 2), $unit];
}

function forumParseText($value, int $max, bool $required, string $label): string
{
    $text = is_string($value) ? trim($value) : '';
    if ($required && $text === '') {
        throw new DomainException($label . ' wajib diisi.');
    }
    if (mb_strlen($text) > $max) {
        throw new DomainException($label . ' maksimal ' . number_format($max, 0, ',', '.') . ' karakter.');
    }

    return $text;
}

/* =================================================================
 * Pembacaan data
 * ================================================================= */

function forumOpenDiscussion(PDO $pdo, int $crfId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM forum_discussions
         WHERE change_request_id = :id AND status = 'menunggu'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['id' => $crfId]);

    return $stmt->fetch() ?: null;
}

/** Riwayat pembahasan yang sudah ditutup (terbaru dulu). */
function forumDiscussionHistory(PDO $pdo, int $crfId, int $limit = 15): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM forum_discussions
         WHERE change_request_id = :id AND status <> 'menunggu'
         ORDER BY id DESC
         LIMIT " . max(1, min(50, $limit))
    );
    $stmt->execute(['id' => $crfId]);

    return $stmt->fetchAll();
}

function forumOpenDiscussionTotal(PDO $pdo): int
{
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM forum_discussions d
             INNER JOIN change_requests cr ON cr.id = d.change_request_id
             WHERE d.status = 'menunggu' AND " . forumActiveCrfCondition('cr')
        )->fetchColumn();
    } catch (Throwable $e) {
        // Tabel belum dimigrasi (018): jangan sampai dashboard ikut error.
        error_log('forumOpenDiscussionTotal: ' . $e->getMessage());
        return 0;
    }
}

function forumDiscussionIsOverdue(array $discussion): bool
{
    return $discussion['status'] === 'menunggu'
        && !empty($discussion['due_at'])
        && strtotime($discussion['due_at']) < time();
}

/** CRF punya SLA yang valid (nilai > 0 dan satuan benar)? */
function crfHasValidSla(array $crf): bool
{
    return isset($crf['sla_value'], $crf['sla_unit'])
        && is_numeric($crf['sla_value'])
        && (float) $crf['sla_value'] > 0
        && in_array($crf['sla_unit'], FORUM_SLA_UNITS, true);
}

/**
 * Nilai yang berlaku saat ini untuk sebuah CRF.
 *
 * @return array{urgency:?string,sla_value:mixed,sla_unit:?string}
 */
function forumCurrentAgreement(array $crf): array
{
    return [
        'urgency' => ($crf['final_urgency_level'] ?? null) ?: crfEffectiveUrgency($crf),
        'sla_value' => $crf['sla_value'] ?? null,
        'sla_unit' => $crf['sla_unit'] ?? null,
    ];
}

/* =================================================================
 * Hak akses
 * ================================================================= */

/** Hanya CMO (dan akun demo untuk presentasi) yang dapat mengajukan pembahasan. */
function canOpenForumDiscussion(): bool
{
    return in_array(getCrfRole(), ['cmo', 'demo'], true);
}

function forumResultUserIds(PDO $pdo): array
{
    $ids = [];
    foreach (forumResultRoles() as $role) {
        $ids = array_merge($ids, crfUserIdsForRole($pdo, $role));
    }

    return array_values(array_unique(array_map('intval', $ids)));
}

/* =================================================================
 * Notifikasi, komentar sistem, kunci CRF
 * ================================================================= */

function forumNotify(PDO $pdo, array $userIds, string $title, string $message, int $crfId, int $actorId): void
{
    try {
        notifyUsers(
            $pdo,
            $userIds,
            $title,
            $message,
            'forum/index.php?crf_id=' . $crfId . '#forum-pembahasan',
            $crfId,
            null,
            $actorId
        );
    } catch (Throwable $e) {
        error_log('forumNotify: ' . $e->getMessage());
    }
}

function forumAddSystemComment(PDO $pdo, int $crfId, array $user, string $text): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO forum_comments
            (change_request_id, user_id, user_name, user_role, comment, is_system)
         VALUES
            (:crf_id, :user_id, :user_name, :user_role, :comment, 1)'
    );
    $stmt->execute([
        'crf_id' => $crfId,
        'user_id' => (int) $user['id'],
        'user_name' => crfActorName($user),
        'user_role' => getCrfRole(),
        'comment' => $text,
    ]);

    return (int) $pdo->lastInsertId();
}

function forumDiscussionDueAt(): string
{
    return slaDueAt(date('Y-m-d H:i:s'), FORUM_DISCUSSION_DECISION_DAYS, 'Hari')
        ?? date('Y-m-d H:i:s', strtotime('+' . FORUM_DISCUSSION_DECISION_DAYS . ' days'));
}

/** Kunci baris CRF dan ambil kolom yang dibutuhkan fitur pembahasan. */
function forumLockCrf(PDO $pdo, int $crfId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, user_id, request_number, crf_category_id, assigned_handler_id,
                status, workflow_stage, level, impact_category, final_urgency_level,
                sla_value, sla_unit, kadep_operasional_approved_at, forum_discussion_open
         FROM change_requests cr
         WHERE cr.id = :id AND ' . forumActiveCrfCondition('cr') . '
         FOR UPDATE'
    );
    $stmt->execute(['id' => $crfId]);

    return $stmt->fetch() ?: null;
}

function forumSetOpenFlag(PDO $pdo, int $crfId, bool $open): void
{
    $pdo->prepare('UPDATE change_requests SET forum_discussion_open = :flag WHERE id = :id')
        ->execute(['flag' => $open ? 1 : 0, 'id' => $crfId]);
}

/** Penerima notifikasi: penentu hasil + PIC CRF kategori (sesama peserta Forum). */
function forumDiscussionAudience(PDO $pdo, array $crf): array
{
    $ids = forumResultUserIds($pdo);
    if (!empty($crf['crf_category_id'])) {
        $ids = array_merge($ids, crfCategoryHandlerIds($pdo, (int) $crf['crf_category_id']));
    }

    return $ids;
}

/* =================================================================
 * Membuka pembahasan
 * ================================================================= */

/**
 * CMO mengajukan pembahasan Forum saat screening. Menjalankan transaksi sendiri.
 *
 * @return int id pembahasan
 */
function forumRequestDiscussion(PDO $pdo, int $crfId, array $user, string $reason): int
{
    if (!canOpenForumDiscussion()) {
        throw new DomainException('Hanya CMO yang dapat mengajukan pembahasan Forum.');
    }

    try {
        $pdo->beginTransaction();

        $crf = forumLockCrf($pdo, $crfId);
        if (!$crf) {
            throw new DomainException('CRF tidak ditemukan atau sudah tidak dalam proses.');
        }
        if ($crf['workflow_stage'] !== 'CMO_FILTER') {
            throw new DomainException('Pembahasan Forum hanya dapat diajukan saat CRF masih di verifikasi CMO.');
        }
        if (forumOpenDiscussion($pdo, $crfId)) {
            throw new DomainException('CRF ini sudah menunggu pembahasan Forum.');
        }

        $before = forumCurrentAgreement($crf);
        $actor = crfActorName($user);
        $actorRole = getCrfRole();
        $dueAt = forumDiscussionDueAt();

        try {
            $pdo->prepare(
                "INSERT INTO forum_discussions
                    (change_request_id, status, trigger_source,
                     opened_by, opened_by_name, opened_by_role, reason,
                     before_urgency, before_sla_value, before_sla_unit, due_at, open_crf_id)
                 VALUES
                    (:crf_id, 'menunggu', 'cmo',
                     :by, :by_name, :by_role, :reason,
                     :b_urgency, :b_value, :b_unit, :due_at, :open_id)"
            )->execute([
                'crf_id' => $crfId,
                'by' => (int) $user['id'],
                'by_name' => $actor,
                'by_role' => $actorRole,
                'reason' => $reason,
                'b_urgency' => $before['urgency'],
                'b_value' => $before['sla_value'],
                'b_unit' => $before['sla_unit'],
                'due_at' => $dueAt,
                'open_id' => $crfId,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException('CRF ini sudah menunggu pembahasan Forum.');
            }
            throw $e;
        }
        $discussionId = (int) $pdo->lastInsertId();
        forumSetOpenFlag($pdo, $crfId, true);

        $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
        $current = forumValuesText($before['urgency'], $before['sla_value'], $before['sla_unit']);
        $description = sprintf(
            '%s (%s) mengajukan pembahasan Forum untuk Level Urgensi dan SLA (nilai sistem saat ini: %s). CRF menunggu hasil pembahasan, lalu langsung diteruskan ke Kepala Departemen Operasional. Alasan: %s',
            $actor,
            forumRoleName($actorRole),
            $current,
            $reason
        );
        logCrfActivity($pdo, $crfId, 'Pembahasan Forum Diajukan', $description, $actor, null, 'Menunggu Pembahasan Forum');
        forumAddSystemComment($pdo, $crfId, $user, $description);
        forumNotify(
            $pdo,
            forumDiscussionAudience($pdo, $crf),
            'Menunggu pembahasan Forum: ' . $crfNumber,
            $actor . ' mengajukan pembahasan Level Urgensi dan SLA (saat ini ' . $current . '). Berikan pertimbangan di Forum. Target hasil '
                . date('d-m-Y H:i', strtotime($dueAt)) . '.',
            $crfId,
            (int) $user['id']
        );

        $pdo->commit();

        return $discussionId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Pembahasan otomatis dari sistem saat SLA default tidak dapat diisi (kategori
 * belum punya SLA standar). Dipanggil DI DALAM transaksi pemanggil.
 */
function forumRaiseSystemDiscussion(PDO $pdo, array $crf, array $actingUser, string $reason): void
{
    $crfId = (int) $crf['id'];
    if (forumOpenDiscussion($pdo, $crfId)) {
        return;
    }

    $before = forumCurrentAgreement($crf);
    $dueAt = forumDiscussionDueAt();
    try {
        $pdo->prepare(
            "INSERT INTO forum_discussions
                (change_request_id, status, trigger_source,
                 opened_by, opened_by_name, opened_by_role, reason,
                 before_urgency, before_sla_value, before_sla_unit, due_at, open_crf_id)
             VALUES
                (:crf_id, 'menunggu', 'sistem',
                 NULL, 'Sistem', 'sistem', :reason,
                 :b_urgency, :b_value, :b_unit, :due_at, :open_id)"
        )->execute([
            'crf_id' => $crfId,
            'reason' => $reason,
            'b_urgency' => $before['urgency'],
            'b_value' => $before['sla_value'],
            'b_unit' => $before['sla_unit'],
            'due_at' => $dueAt,
            'open_id' => $crfId,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return;
        }
        throw $e;
    }
    forumSetOpenFlag($pdo, $crfId, true);

    $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
    $text = 'Sistem membuka pembahasan Forum karena SLA default belum tersedia. ' . $reason;
    logCrfActivity($pdo, $crfId, 'Pembahasan Forum Diajukan', $text, 'Sistem', null, 'Menunggu Pembahasan Forum');
    forumAddSystemComment($pdo, $crfId, $actingUser, $text);
    forumNotify(
        $pdo,
        forumDiscussionAudience($pdo, $crf),
        'SLA perlu ditetapkan: ' . $crfNumber,
        $reason . ' CMO atau Admin mencatat hasil pembahasan di Forum, lalu CRF langsung diteruskan ke Kepala Departemen. Target hasil '
            . date('d-m-Y H:i', strtotime($dueAt)) . '.',
        $crfId,
        (int) $actingUser['id']
    );
}

/* =================================================================
 * Mencatat hasil (hanya penentu) & menutup
 * ================================================================= */

/**
 * Catat hasil pembahasan dan terapkan ke data CRF. Menjalankan transaksi sendiri.
 *   outcome 'tetap'  : nilai sistem dipakai (data CRF tidak berubah).
 *   outcome 'diubah' : Level Urgensi dan SLA diganti nilai kesepakatan.
 * Mengembalikan ['crf_id' => int, 'message' => string].
 */
function forumRecordResult(PDO $pdo, int $discussionId, array $user, string $outcome, ?string $urgency, $value, $unit, string $note): array
{
    if (!canRecordForumResult()) {
        throw new DomainException('Hanya ' . implode(' / ', array_map('crfRoleLabel', forumResultRoles())) . ' yang dapat mencatat hasil pembahasan.');
    }
    if (!in_array($outcome, ['tetap', 'diubah'], true)) {
        throw new DomainException('Pilih hasil pembahasan: Tetap atau Diubah.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM forum_discussions WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $discussionId]);
        $discussion = $stmt->fetch();
        if (!$discussion || $discussion['status'] !== 'menunggu') {
            throw new DomainException('Pembahasan ini sudah selesai atau dibatalkan.');
        }

        $crfId = (int) $discussion['change_request_id'];
        $crf = forumLockCrf($pdo, $crfId);
        if (!$crf || !empty($crf['kadep_operasional_approved_at'])) {
            throw new DomainException('Hasil pembahasan tidak dapat diterapkan karena CRF sudah disetujui atau tidak lagi dalam proses.');
        }

        $before = forumCurrentAgreement($crf);
        $beforeText = forumValuesText($before['urgency'], $before['sla_value'], $before['sla_unit']);
        $actor = crfActorName($user);
        $roleLabel = forumRoleName(getCrfRole());

        if ($outcome === 'tetap') {
            if (!crfHasValidSla($crf) || !$before['urgency']) {
                throw new DomainException('Nilai sistem belum lengkap (SLA kosong). Pilih "Diubah" dan isi Level Urgensi serta SLA.');
            }
            $final = $before;
        } else {
            $newUrgency = forumParseUrgency($urgency);
            [$newValue, $newUnit] = forumParseSla($value, $unit);
            $same = $newUrgency === $before['urgency']
                && $before['sla_value'] !== null
                && abs((float) $before['sla_value'] - $newValue) < 0.001
                && $before['sla_unit'] === $newUnit;
            if ($same) {
                throw new DomainException('Nilai sama dengan nilai sistem. Pilih "Tetap" bila tidak ada perubahan.');
            }

            $pdo->prepare(
                'UPDATE change_requests
                 SET final_urgency_level = :final_urgency, level = :level_urgency,
                     sla_value = :value, sla_unit = :unit
                 WHERE id = :id'
            )->execute([
                'final_urgency' => $newUrgency,
                'level_urgency' => $newUrgency,
                'value' => $newValue,
                'unit' => $newUnit,
                'id' => $crfId,
            ]);
            $final = ['urgency' => $newUrgency, 'sla_value' => $newValue, 'sla_unit' => $newUnit];
        }
        $afterText = forumValuesText($final['urgency'], $final['sla_value'], $final['sla_unit']);

        $pdo->prepare(
            "UPDATE forum_discussions
             SET status = 'selesai', outcome = :outcome, open_crf_id = NULL,
                 before_urgency = :b_urgency, before_sla_value = :b_value, before_sla_unit = :b_unit,
                 final_urgency = :f_urgency, final_sla_value = :f_value, final_sla_unit = :f_unit,
                 decided_by = :by, decided_by_name = :by_name, decision_note = :note, decided_at = NOW()
             WHERE id = :id"
        )->execute([
            'outcome' => $outcome,
            'b_urgency' => $before['urgency'],
            'b_value' => $before['sla_value'],
            'b_unit' => $before['sla_unit'],
            'f_urgency' => $final['urgency'],
            'f_value' => $final['sla_value'],
            'f_unit' => $final['sla_unit'],
            'by' => (int) $user['id'],
            'by_name' => $actor,
            'note' => $note,
            'id' => $discussionId,
        ]);
        forumSetOpenFlag($pdo, $crfId, false);

        // Hasil sudah dicatat: langsung ke Kepala Departemen (SLA belum berjalan sampai disetujui).
        $forwarded = false;
        if ($crf['workflow_stage'] === 'CMO_FILTER') {
            $advance = $pdo->prepare("
                UPDATE change_requests
                SET status = 'Dalam Proses', workflow_stage = 'kadep_operasional',
                    automation_started_at = NULL, sla_started_at = NULL, sla_due_at = NULL
                WHERE id = :id AND workflow_stage = 'CMO_FILTER' AND forum_discussion_open = 0
            ");
            $advance->execute(['id' => $crfId]);
            $forwarded = $advance->rowCount() === 1;
        }

        $outcomeLabel = forumOutcomeMeta($outcome)['label'];
        $note = rtrim($note, ". 	
");
        $description = $outcome === 'tetap'
            ? sprintf(
                'Hasil pembahasan Forum: TETAP. Level Urgensi dan SLA tetap memakai nilai sistem (%s). Alasan: %s. Dicatat oleh %s (%s).',
                $afterText, $note, $actor, $roleLabel
            )
            : sprintf(
                'Hasil pembahasan Forum: DIUBAH. Sebelum: %s. Sesudah: %s. Alasan: %s. Diterapkan oleh %s (%s).',
                $beforeText, $afterText, $note, $actor, $roleLabel
            );
        logCrfActivity($pdo, $crfId, 'Hasil Pembahasan Forum', $description, $actor, 'Menunggu Pembahasan Forum', 'Pembahasan Forum selesai: ' . $outcomeLabel);
        forumAddSystemComment($pdo, $crfId, $user, $description);

        $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
        if ($forwarded) {
            logCrfActivity(
                $pdo,
                $crfId,
                'Diteruskan ke Kepala Departemen',
                'Setelah hasil pembahasan Forum dicatat, CRF langsung diteruskan ke Kepala Departemen Operasional untuk persetujuan.',
                $actor,
                'Pembahasan Forum selesai: ' . $outcomeLabel,
                'Menunggu Persetujuan'
            );
            notifyUsers(
                $pdo,
                crfUserIdsForRole($pdo, 'kadep_operasional'),
                'Persetujuan CRF: ' . $crfNumber,
                'CRF ' . $crfNumber . ' selesai dibahas di Forum (' . $outcomeLabel . ': ' . $afterText . ') dan menunggu persetujuan Anda.',
                'crf/open.php?id=' . $crfId,
                $crfId,
                null,
                (int) $user['id']
            );
            syncHelpdeskTicketFromCrf($pdo, $crfId, $actor);
        }
        // Hanya yang terlibat: pengaju (atau seluruh CMO bila dibuka sistem) dan pemberi komentar.
        $commenters = $pdo->prepare('SELECT DISTINCT user_id FROM forum_comments WHERE change_request_id = :id AND is_system = 0');
        $commenters->execute(['id' => $crfId]);
        $recipients = array_map('intval', $commenters->fetchAll(PDO::FETCH_COLUMN));
        if (!empty($discussion['opened_by'])) {
            $recipients[] = (int) $discussion['opened_by'];
        } else {
            $recipients = array_merge($recipients, crfUserIdsForRole($pdo, 'cmo'));
        }
        forumNotify(
            $pdo,
            $recipients,
            'Hasil pembahasan Forum: ' . $crfNumber,
            'Hasil: ' . $outcomeLabel . ' (' . $afterText . ')' . ($outcome === 'diubah' ? ', sebelumnya ' . $beforeText : '')
                . ($forwarded
                    ? '. CRF diteruskan ke Kepala Departemen Operasional untuk persetujuan.'
                    : '. CMO dapat meneruskan CRF ke Kepala Departemen Operasional.'),
            $crfId,
            (int) $user['id']
        );

        $pdo->commit();

        return [
            'crf_id' => $crfId,
            'message' => ($outcome === 'tetap'
                ? 'Hasil dicatat: Level Urgensi dan SLA tetap.'
                : 'Hasil dicatat: Level Urgensi dan SLA diubah dan diterapkan ke CRF.')
                . ($forwarded ? ' CRF langsung diteruskan ke Kepala Departemen Operasional untuk persetujuan.' : ' CMO dapat meneruskan CRF.'),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Batalkan pembahasan yang masih terbuka (CMO yang mengajukan, CMO lain, atau
 * penentu). Pembahasan dari sistem wajib diselesaikan selama SLA masih kosong.
 * Menjalankan transaksi sendiri. Mengembalikan id CRF.
 */
function forumCancelDiscussion(PDO $pdo, int $discussionId, array $user): int
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM forum_discussions WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $discussionId]);
        $discussion = $stmt->fetch();
        if (!$discussion || $discussion['status'] !== 'menunggu') {
            throw new DomainException('Pembahasan ini sudah selesai atau dibatalkan.');
        }
        if (!canOpenForumDiscussion() && !canRecordForumResult()) {
            throw new DomainException('Hanya CMO atau Admin yang dapat membatalkan pembahasan.');
        }

        $crfId = (int) $discussion['change_request_id'];
        $crf = forumLockCrf($pdo, $crfId);
        if ($discussion['trigger_source'] === 'sistem' && $crf && !crfHasValidSla($crf)) {
            throw new DomainException('Pembahasan ini tidak dapat dibatalkan karena SLA CRF belum tersedia. CMO atau Admin perlu mencatat hasilnya (Diubah) dengan nilai SLA.');
        }

        $actor = crfActorName($user);
        $pdo->prepare(
            "UPDATE forum_discussions
             SET status = 'dibatalkan', open_crf_id = NULL,
                 decided_by = :by, decided_by_name = :by_name,
                 decision_note = 'Pembahasan dibatalkan.', decided_at = NOW()
             WHERE id = :id"
        )->execute(['by' => (int) $user['id'], 'by_name' => $actor, 'id' => $discussionId]);
        forumSetOpenFlag($pdo, $crfId, false);

        $text = 'Pembahasan Forum dibatalkan oleh ' . $actor . ' (' . forumRoleName(getCrfRole())
            . '). Level Urgensi dan SLA tetap memakai nilai sistem.';
        logCrfActivity($pdo, $crfId, 'Pembahasan Forum Dibatalkan', $text, $actor, 'Menunggu Pembahasan Forum', 'Menunggu Verifikasi');
        forumAddSystemComment($pdo, $crfId, $user, $text);

        $pdo->commit();

        return $crfId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Tutup pembahasan terbuka karena CRF dibatalkan. Dipanggil DI DALAM transaksi
 * pemanggil; tidak pernah melempar error agar tidak menggagalkan aksi utama.
 */
function forumCloseOpenDiscussions(PDO $pdo, int $crfId, string $note): int
{
    try {
        $discussion = forumOpenDiscussion($pdo, $crfId);
        if (!$discussion) {
            return 0;
        }

        $pdo->prepare(
            "UPDATE forum_discussions
             SET status = 'dibatalkan', open_crf_id = NULL, decided_by_name = 'Sistem',
                 decision_note = :note, decided_at = NOW()
             WHERE id = :id AND status = 'menunggu'"
        )->execute(['note' => $note, 'id' => (int) $discussion['id']]);
        forumSetOpenFlag($pdo, $crfId, false);

        $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $text = 'Pembahasan Forum ditutup. ' . $note;
        logCrfActivity($pdo, $crfId, 'Pembahasan Forum Dibatalkan', $text, 'Sistem');
        if ($user) {
            forumAddSystemComment($pdo, $crfId, $user, $text);
        }

        return 1;
    } catch (Throwable $e) {
        error_log('forumCloseOpenDiscussions: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Pengingat ke penentu untuk pembahasan yang melewati target hasil.
 * Dijalankan "malas" saat halaman terkait dibuka; satu pengingat per pembahasan.
 */
function forumSendDueReminders(PDO $pdo): void
{
    try {
        $rows = $pdo->query(
            "SELECT d.id, d.change_request_id, d.opened_by_name, d.due_at, cr.request_number
             FROM forum_discussions d
             INNER JOIN change_requests cr ON cr.id = d.change_request_id
             WHERE d.status = 'menunggu' AND d.reminded_at IS NULL
               AND d.due_at IS NOT NULL AND d.due_at < NOW()
             LIMIT 20"
        )->fetchAll();

        $sent = false;
        foreach ($rows as $row) {
            $mark = $pdo->prepare('UPDATE forum_discussions SET reminded_at = NOW() WHERE id = :id AND reminded_at IS NULL');
            $mark->execute(['id' => (int) $row['id']]);
            if ($mark->rowCount() !== 1) {
                continue;
            }
            $crfId = (int) $row['change_request_id'];
            forumNotify(
                $pdo,
                forumResultUserIds($pdo),
                'Pembahasan Forum melewati target: ' . ($row['request_number'] ?: 'CRF #' . $crfId),
                'Pembahasan yang diajukan ' . $row['opened_by_name'] . ' belum dicatat hasilnya sejak target '
                    . date('d-m-Y H:i', strtotime($row['due_at']))
                    . '. CRF belum dapat diteruskan ke Kepala Departemen Operasional.',
                $crfId,
                0
            );
            $sent = true;
        }

        if ($sent) {
            dispatchPendingNotificationEmails($pdo);
        }
    } catch (Throwable $e) {
        error_log('forumSendDueReminders: ' . $e->getMessage());
    }
}

/* =================================================================
 * SLA default
 * ================================================================= */

/**
 * Isi Level/SLA default CRF dari matriks Kategori x Urgensi (saat pemohon submit,
 * atau saat CMO meneruskan bila matriks baru diisi belakangan). Bila tetap tidak
 * ada SLA standar, buka pembahasan Forum otomatis agar CMO atau Admin menetapkannya.
 * CRF yang sudah punya hasil Forum "Diubah" (final_urgency_level) tidak ditimpa.
 * Dipanggil DI DALAM transaksi pemanggil.
 */
function forumApplyDefaultSla(PDO $pdo, int $crfId, array $actingUser): void
{
    $stmt = $pdo->prepare('SELECT * FROM change_requests WHERE id = :id');
    $stmt->execute(['id' => $crfId]);
    $crf = $stmt->fetch();
    if (!$crf) {
        return;
    }

    if (empty($crf['final_urgency_level'])) {
        $urgency = crfEffectiveUrgency($crf);
        $standard = crfStandardSla($pdo, (int) ($crf['crf_category_id'] ?? 0), $urgency);
        if ($standard !== null && !crfSlaEquals($standard, $crf['sla_value'], $crf['sla_unit'])) {
            $pdo->prepare('UPDATE change_requests SET sla_value = :value, sla_unit = :unit WHERE id = :id')
                ->execute(['value' => $standard['value'], 'unit' => $standard['unit'], 'id' => $crfId]);
            $crf['sla_value'] = $standard['value'];
            $crf['sla_unit'] = $standard['unit'];
            logCrfActivity(
                $pdo,
                $crfId,
                'SLA Otomatis',
                'SLA default kategori untuk urgensi ' . $urgency . ': ' . slaLabel($standard['value'], $standard['unit']) . ' (hari kerja).',
                'Sistem'
            );
        }
    }

    if (!crfHasValidSla($crf)) {
        forumRaiseSystemDiscussion(
            $pdo,
            $crf,
            $actingUser,
            'SLA standar kategori belum diatur sehingga CRF belum memiliki SLA.'
        );
    }
}
