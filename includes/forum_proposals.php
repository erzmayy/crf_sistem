<?php
/**
 * includes/forum_proposals.php
 * ---------------------------------------------------------------
 * Usulan Urgensi & SLA di Forum (usulan -> keputusan, tanpa voting).
 *
 *   - urgensi_sla      : usulan sebelum Kepala Departemen Operasional menyetujui.
 *   - perpanjangan_sla : permintaan perpanjangan SLA setelah disetujui
 *                        (CRF di tahap Otomasi).
 *
 * Aturan:
 *   - Pengusul : CMO, Admin, Petugas Otomasi kategori CRF (akun demo untuk presentasi).
 *   - Penentu  : forumDeciderRoles() (forum.php) — hanya satu keputusan, bukan voting.
 *   - Satu CRF hanya punya satu usulan terbuka (dijaga UNIQUE open_crf_id).
 *   - Usulan tidak pernah menahan alur CRF; bila tidak diputuskan, nilai yang
 *     berlaku tetap SLA standar kategori dan usulan ditutup saat persetujuan.
 * ---------------------------------------------------------------
 */
require_once __DIR__ . '/forum.php';
require_once __DIR__ . '/functions.php';

/** Batas waktu keputusan (hari kerja). Hanya target proses, bukan SLA CRF. */
const FORUM_PROPOSAL_DECISION_DAYS = 2;

const FORUM_URGENCIES = ['Tinggi', 'Normal', 'Rendah'];
const FORUM_SLA_UNITS = ['Menit', 'Jam', 'Hari'];

/* =================================================================
 * Label & format
 * ================================================================= */

function forumProposalKindLabel(string $kind): string
{
    return $kind === 'perpanjangan_sla' ? 'Perpanjangan SLA' : 'Urgensi & SLA';
}

/**
 * @return array{label:string,class:string}
 */
function forumProposalStatusMeta(string $status): array
{
    switch ($status) {
        case 'menunggu':
            return ['label' => 'Menunggu keputusan', 'class' => 'warning'];
        case 'disetujui':
            return ['label' => 'Disetujui', 'class' => 'success'];
        case 'ditolak':
            return ['label' => 'Ditolak', 'class' => 'danger'];
        case 'dibatalkan':
            return ['label' => 'Dibatalkan', 'class' => 'secondary'];
        default:
            return ['label' => 'Ditutup', 'class' => 'secondary'];
    }
}

/** Nama peran untuk kalimat riwayat (akun demo disingkat agar tidak bertingkat kurung). */
function forumRoleName(string $role): string
{
    return $role === 'demo' ? 'Demo' : crfRoleLabel($role);
}

function forumSlaSeconds(float $value, string $unit): int
{
    $factor = ['Menit' => 60, 'Jam' => 3600, 'Hari' => 86400][$unit] ?? 0;

    return (int) round($value * $factor);
}

/** "Tinggi · 2 Hari" / "2 Hari" / "-" */
function forumValuesText(?string $urgency, $value, ?string $unit, bool $withUrgency = true): string
{
    $parts = [];
    if ($withUrgency && $urgency) {
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

function forumOpenProposal(PDO $pdo, int $crfId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM forum_proposals
         WHERE change_request_id = :id AND status = 'menunggu'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['id' => $crfId]);

    return $stmt->fetch() ?: null;
}

/**
 * Riwayat usulan yang sudah ditutup (terbaru dulu).
 */
function forumProposalHistory(PDO $pdo, int $crfId, int $limit = 15): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM forum_proposals
         WHERE change_request_id = :id AND status <> 'menunggu'
         ORDER BY id DESC
         LIMIT " . max(1, min(50, $limit))
    );
    $stmt->execute(['id' => $crfId]);

    return $stmt->fetchAll();
}

function forumOpenProposalTotal(PDO $pdo): int
{
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM forum_proposals p
             INNER JOIN change_requests cr ON cr.id = p.change_request_id
             WHERE p.status = 'menunggu' AND " . forumActiveCrfCondition('cr')
        )->fetchColumn();
    } catch (Throwable $e) {
        // Tabel belum dimigrasi (016): jangan sampai dashboard ikut error.
        error_log('forumOpenProposalTotal: ' . $e->getMessage());
        return 0;
    }
}

function forumProposalIsOverdue(array $proposal): bool
{
    return $proposal['status'] === 'menunggu'
        && !empty($proposal['due_at'])
        && strtotime($proposal['due_at']) < time();
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

/**
 * Jenis usulan yang boleh diajukan user ini pada CRF tersebut, atau null.
 * Ditentukan SERVER dari tahap CRF — bukan dari kiriman form.
 */
function forumProposalKindFor(PDO $pdo, array $crf): ?string
{
    $role = getCrfRole();
    $stage = (string) ($crf['workflow_stage'] ?? '');
    $approved = !empty($crf['kadep_operasional_approved_at']);
    $isHandler = $role === 'otomasi'
        && canHandleCrf($pdo, $crf)
        && (empty($crf['assigned_handler_id']) || isAssignedCrfHandler($crf));

    if (!$approved && in_array($stage, ['CMO_FILTER', 'kadep_operasional'], true)) {
        return in_array($role, ['cmo', 'admin', 'demo'], true) || $isHandler ? 'urgensi_sla' : null;
    }

    if ($approved && $stage === 'OTOMASI') {
        return in_array($role, ['admin', 'demo'], true) || $isHandler ? 'perpanjangan_sla' : null;
    }

    return null;
}

function forumDeciderUserIds(PDO $pdo): array
{
    $ids = [];
    foreach (forumDeciderRoles() as $role) {
        $ids = array_merge($ids, crfUserIdsForRole($pdo, $role));
    }

    return array_values(array_unique(array_map('intval', $ids)));
}

/* =================================================================
 * Notifikasi & komentar sistem
 * ================================================================= */

function forumNotify(PDO $pdo, array $userIds, string $title, string $message, int $crfId, int $actorId): void
{
    try {
        notifyUsers(
            $pdo,
            $userIds,
            $title,
            $message,
            'forum/index.php?crf_id=' . $crfId . '#forum-proposal',
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

function forumProposalDueAt(): string
{
    return slaDueAt(date('Y-m-d H:i:s'), FORUM_PROPOSAL_DECISION_DAYS, 'Hari')
        ?? date('Y-m-d H:i:s', strtotime('+' . FORUM_PROPOSAL_DECISION_DAYS . ' days'));
}

/** Kunci baris CRF dan ambil kolom yang dibutuhkan fitur usulan. */
function forumLockCrf(PDO $pdo, int $crfId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, user_id, request_number, crf_category_id, assigned_handler_id,
                status, workflow_stage, level, impact_category, final_urgency_level,
                sla_value, sla_unit, sla_started_at, sla_due_at,
                kadep_operasional_approved_at, automation_started_at
         FROM change_requests cr
         WHERE cr.id = :id AND ' . forumActiveCrfCondition('cr') . '
         FOR UPDATE'
    );
    $stmt->execute(['id' => $crfId]);

    return $stmt->fetch() ?: null;
}

/* =================================================================
 * Mengajukan usulan
 * ================================================================= */

/**
 * Ajukan usulan oleh user. Menjalankan transaksi sendiri.
 * $urgency diabaikan untuk perpanjangan SLA.
 *
 * @return int id usulan
 */
function forumSubmitProposal(PDO $pdo, int $crfId, array $user, ?string $urgency, float $value, string $unit, string $reason): int
{
    try {
        $pdo->beginTransaction();

        $crf = forumLockCrf($pdo, $crfId);
        if (!$crf) {
            throw new DomainException('CRF tidak ditemukan atau sudah tidak dalam proses.');
        }

        $kind = forumProposalKindFor($pdo, $crf);
        if ($kind === null) {
            throw new DomainException('Anda tidak dapat mengajukan usulan pada tahap CRF ini.');
        }
        if ($kind === 'urgensi_sla') {
            $urgency = forumParseUrgency($urgency);
        } else {
            $urgency = null;
            $currentSeconds = $crf['sla_value'] !== null && $crf['sla_unit']
                ? forumSlaSeconds((float) $crf['sla_value'], (string) $crf['sla_unit'])
                : 0;
            if (forumSlaSeconds($value, $unit) <= $currentSeconds) {
                throw new DomainException('SLA baru harus lebih lama dari SLA saat ini (' . slaLabel($crf['sla_value'], $crf['sla_unit']) . ').');
            }
        }

        if (forumOpenProposal($pdo, $crfId)) {
            throw new DomainException('Sudah ada usulan yang menunggu keputusan untuk CRF ini.');
        }

        $before = forumCurrentAgreement($crf);
        $actor = crfActorName($user);
        $actorRole = getCrfRole();
        $dueAt = forumProposalDueAt();

        try {
            $pdo->prepare(
                "INSERT INTO forum_proposals
                    (change_request_id, kind, status, trigger_source,
                     proposed_by, proposed_by_name, proposed_by_role,
                     proposed_urgency, proposed_sla_value, proposed_sla_unit, reason,
                     before_urgency, before_sla_value, before_sla_unit,
                     due_at, open_crf_id)
                 VALUES
                    (:crf_id, :kind, 'menunggu', 'manual',
                     :by, :by_name, :by_role,
                     :urgency, :value, :unit, :reason,
                     :b_urgency, :b_value, :b_unit,
                     :due_at, :open_id)"
            )->execute([
                'crf_id' => $crfId,
                'kind' => $kind,
                'by' => (int) $user['id'],
                'by_name' => $actor,
                'by_role' => $actorRole,
                'urgency' => $urgency,
                'value' => $value,
                'unit' => $unit,
                'reason' => $reason,
                'b_urgency' => $before['urgency'],
                'b_value' => $before['sla_value'],
                'b_unit' => $before['sla_unit'],
                'due_at' => $dueAt,
                'open_id' => $crfId,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException('Sudah ada usulan yang menunggu keputusan untuk CRF ini.');
            }
            throw $e;
        }
        $proposalId = (int) $pdo->lastInsertId();

        $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
        $proposedText = forumValuesText($urgency, $value, $unit, $kind === 'urgensi_sla');
        $beforeText = forumValuesText($before['urgency'], $before['sla_value'], $before['sla_unit'], $kind === 'urgensi_sla');
        $title = $kind === 'urgensi_sla' ? 'Usulan Urgensi dan SLA' : 'Usulan Perpanjangan SLA';
        $description = sprintf(
            '%s (%s) mengajukan %s: %s (sebelumnya %s). Alasan: %s',
            $actor,
            forumRoleName($actorRole),
            $kind === 'urgensi_sla' ? 'usulan urgensi dan SLA' : 'perpanjangan SLA',
            $proposedText,
            $beforeText,
            $reason
        );
        logCrfActivity($pdo, $crfId, $title, $description, $actor);
        forumAddSystemComment($pdo, $crfId, $user, $description);

        // Penentu, CMO (bila masih verifikasi), dan Petugas Otomasi (bila perpanjangan) perlu tahu.
        $recipients = forumDeciderUserIds($pdo);
        if ($crf['workflow_stage'] === 'CMO_FILTER') {
            $recipients = array_merge($recipients, crfUserIdsForRole($pdo, 'cmo'));
        }
        if ($kind === 'perpanjangan_sla' && !empty($crf['assigned_handler_id'])) {
            $recipients[] = (int) $crf['assigned_handler_id'];
        }
        forumNotify(
            $pdo,
            $recipients,
            ($kind === 'urgensi_sla' ? 'Usulan urgensi/SLA: ' : 'Usulan perpanjangan SLA: ') . $crfNumber,
            $actor . ' mengusulkan ' . $proposedText . ' (sebelumnya ' . $beforeText . '). Batas keputusan '
                . date('d-m-Y H:i', strtotime($dueAt)) . '.',
            $crfId,
            (int) $user['id']
        );

        $pdo->commit();

        return $proposalId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Usulan otomatis dari sistem (mis. SLA standar kategori kosong saat CMO
 * meneruskan CRF). Dipanggil DI DALAM transaksi pemanggil.
 */
function forumRaiseSystemProposal(PDO $pdo, array $crf, array $actingUser, string $reason): void
{
    $crfId = (int) $crf['id'];
    if (forumOpenProposal($pdo, $crfId)) {
        return;
    }

    $before = forumCurrentAgreement($crf);
    try {
        $pdo->prepare(
            "INSERT INTO forum_proposals
                (change_request_id, kind, status, trigger_source,
                 proposed_by, proposed_by_name, proposed_by_role, reason,
                 before_urgency, before_sla_value, before_sla_unit, due_at, open_crf_id)
             VALUES
                (:crf_id, 'urgensi_sla', 'menunggu', 'sistem',
                 NULL, 'Sistem', 'sistem', :reason,
                 :b_urgency, :b_value, :b_unit, :due_at, :open_id)"
        )->execute([
            'crf_id' => $crfId,
            'reason' => $reason,
            'b_urgency' => $before['urgency'],
            'b_value' => $before['sla_value'],
            'b_unit' => $before['sla_unit'],
            'due_at' => forumProposalDueAt(),
            'open_id' => $crfId,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return;
        }
        throw $e;
    }

    $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
    logCrfActivity($pdo, $crfId, 'Usulan Urgensi dan SLA', 'Sistem membuka pembahasan urgensi dan SLA. ' . $reason, 'Sistem');
    forumAddSystemComment($pdo, $crfId, $actingUser, 'Sistem membuka pembahasan urgensi dan SLA. ' . $reason);
    forumNotify(
        $pdo,
        forumDeciderUserIds($pdo),
        'SLA perlu ditetapkan: ' . $crfNumber,
        $reason . ' Tetapkan urgensi dan SLA di Forum. Batas keputusan '
            . date('d-m-Y H:i', strtotime(forumProposalDueAt())) . '.',
        $crfId,
        (int) $actingUser['id']
    );
}

/* =================================================================
 * Menetapkan nilai & keputusan
 * ================================================================= */

/**
 * Terapkan nilai kesepakatan ke CRF (di dalam transaksi).
 * urgensi_sla  : menetapkan urgensi final + SLA.
 * perpanjangan : hanya SLA; tenggat dihitung ulang dari waktu mulai SLA.
 */
function forumApplyAgreement(PDO $pdo, array $crf, string $kind, ?string $urgency, float $value, string $unit): void
{
    $dueAt = !empty($crf['sla_started_at'])
        ? slaDueAt($crf['sla_started_at'], $value, $unit)
        : null;
    if (!empty($crf['sla_started_at']) && $dueAt === null) {
        throw new RuntimeException('Tenggat SLA tidak dapat dihitung dari data yang tersimpan.');
    }

    if ($kind === 'urgensi_sla') {
        $pdo->prepare(
            'UPDATE change_requests
             SET final_urgency_level = :final_urgency, level = :level_urgency,
                 sla_value = :value, sla_unit = :unit, sla_due_at = :due_at
             WHERE id = :id'
        )->execute([
            'final_urgency' => $urgency,
            'level_urgency' => $urgency,
            'value' => $value,
            'unit' => $unit,
            'due_at' => $dueAt,
            'id' => (int) $crf['id'],
        ]);

        return;
    }

    $pdo->prepare(
        'UPDATE change_requests
         SET sla_value = :value, sla_unit = :unit, sla_due_at = :due_at
         WHERE id = :id'
    )->execute([
        'value' => $value,
        'unit' => $unit,
        'due_at' => $dueAt,
        'id' => (int) $crf['id'],
    ]);
}

/** Kunci status CRF agar keputusan hanya berlaku pada tahap yang sesuai. */
function forumProposalStageAllows(array $crf, string $kind): bool
{
    $approved = !empty($crf['kadep_operasional_approved_at']);
    $stage = (string) ($crf['workflow_stage'] ?? '');

    return $kind === 'urgensi_sla'
        ? !$approved && in_array($stage, ['CMO_FILTER', 'kadep_operasional', 'PEMOHON'], true)
        : $approved && $stage === 'OTOMASI';
}

/**
 * Putuskan usulan: 'approve' (boleh dengan penyesuaian nilai) atau 'reject'.
 * Menjalankan transaksi sendiri. Mengembalikan ['crf_id'=>, 'message'=>].
 */
function forumDecideProposal(PDO $pdo, int $proposalId, array $user, string $decision, ?string $urgency, $value, $unit, string $note): array
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM forum_proposals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $proposalId]);
        $proposal = $stmt->fetch();
        if (!$proposal || $proposal['status'] !== 'menunggu') {
            throw new DomainException('Usulan ini sudah diputuskan atau ditutup.');
        }

        $crfId = (int) $proposal['change_request_id'];
        $crf = forumLockCrf($pdo, $crfId);
        if (!$crf || !forumProposalStageAllows($crf, $proposal['kind'])) {
            throw new DomainException('Usulan tidak dapat diputuskan karena tahap CRF sudah berubah.');
        }

        $kind = $proposal['kind'];
        $actor = crfActorName($user);
        $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
        $proposerName = $proposal['proposed_by_name'];
        $now = date('Y-m-d H:i:s');
        $finalUrgency = null;
        $finalValue = null;
        $finalUnit = null;

        if ($decision === 'approve') {
            [$finalValue, $finalUnit] = forumParseSla($value, $unit);
            if ($kind === 'urgensi_sla') {
                $finalUrgency = forumParseUrgency($urgency);
            } else {
                $currentSeconds = $crf['sla_value'] !== null && $crf['sla_unit']
                    ? forumSlaSeconds((float) $crf['sla_value'], (string) $crf['sla_unit'])
                    : 0;
                if (forumSlaSeconds($finalValue, $finalUnit) <= $currentSeconds) {
                    throw new DomainException('SLA baru harus lebih lama dari SLA saat ini (' . slaLabel($crf['sla_value'], $crf['sla_unit']) . ').');
                }
            }
            forumApplyAgreement($pdo, $crf, $kind, $finalUrgency, $finalValue, $finalUnit);
            $status = 'disetujui';
        } else {
            if ($note === '') {
                throw new DomainException('Alasan penolakan wajib diisi.');
            }
            $status = 'ditolak';
        }

        $pdo->prepare(
            'UPDATE forum_proposals
             SET status = :status, open_crf_id = NULL,
                 final_urgency = :f_urgency, final_sla_value = :f_value, final_sla_unit = :f_unit,
                 decided_by = :by, decided_by_name = :by_name,
                 decision_note = :note, decided_at = :now
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'f_urgency' => $finalUrgency,
            'f_value' => $finalValue,
            'f_unit' => $finalUnit,
            'by' => (int) $user['id'],
            'by_name' => $actor,
            'note' => $note !== '' ? $note : null,
            'now' => $now,
            'id' => $proposalId,
        ]);

        $roleLabel = forumRoleName(getCrfRole());
        if ($decision === 'approve') {
            $finalText = forumValuesText($finalUrgency, $finalValue, $finalUnit, $kind === 'urgensi_sla');
            $proposedText = forumValuesText($proposal['proposed_urgency'], $proposal['proposed_sla_value'], $proposal['proposed_sla_unit'], $kind === 'urgensi_sla');
            $adjusted = $proposal['trigger_source'] !== 'sistem'
                && $proposedText !== '-'
                && $proposedText !== $finalText;

            if ($kind === 'urgensi_sla') {
                $description = forumSystemCommentPrefix() . $roleLabel . '. '
                    . ($proposal['trigger_source'] === 'sistem' ? 'Nilai ditetapkan: ' : 'Usulan ' . $proposerName . ($adjusted ? ' disetujui dengan penyesuaian' : ' disetujui') . ': ')
                    . $finalText . '.';
                $activity = 'Kesepakatan Urgensi dan SLA Forum';
            } else {
                $description = 'Perpanjangan SLA disetujui oleh ' . $roleLabel . ': SLA '
                    . slaLabel($crf['sla_value'], $crf['sla_unit']) . ' menjadi ' . $finalText
                    . ($crf['sla_due_at'] ? '. Batas SLA dihitung ulang dari waktu mulai.' : '.');
                $activity = 'Perpanjangan SLA';
            }
            if ($note !== '') {
                $description .= ' Catatan: ' . $note;
            }
            $message = $kind === 'urgensi_sla'
                ? 'Usulan disetujui dan urgensi serta SLA final ditetapkan.'
                : 'Perpanjangan SLA disetujui dan batas SLA dihitung ulang.';
            $notifyTitle = 'Usulan disetujui: ' . $crfNumber;
            $notifyText = $actor . ' menyetujui usulan ' . forumProposalKindLabel($kind) . ': ' . $finalText . '.';
        } else {
            $description = 'Usulan ' . forumProposalKindLabel($kind) . ' dari ' . $proposerName . ' ditolak oleh ' . $roleLabel . '. Alasan: ' . $note;
            $activity = 'Usulan Ditolak';
            $message = 'Usulan ditolak.';
            $notifyTitle = 'Usulan ditolak: ' . $crfNumber;
            $notifyText = $actor . ' menolak usulan ' . forumProposalKindLabel($kind) . '. Alasan: ' . $note;
        }
        logCrfActivity($pdo, $crfId, $activity, $description, $actor);
        forumAddSystemComment($pdo, $crfId, $user, $description);

        // Pengusul, peserta diskusi, handler, dan CMO (bila CRF masih di verifikasi CMO).
        $recipients = forumParticipantIds($pdo, $crfId, (int) $crf['assigned_handler_id'] ?: null);
        if (!empty($proposal['proposed_by'])) {
            $recipients[] = (int) $proposal['proposed_by'];
        }
        if ($crf['workflow_stage'] === 'CMO_FILTER') {
            $recipients = array_merge($recipients, crfUserIdsForRole($pdo, 'cmo'));
            if ($decision === 'approve') {
                $notifyText .= ' CMO dapat meneruskan CRF ke Kepala Departemen Operasional.';
            }
        }
        forumNotify($pdo, $recipients, $notifyTitle, $notifyText, $crfId, (int) $user['id']);

        $pdo->commit();

        return ['crf_id' => $crfId, 'message' => $message];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Admin menetapkan nilai langsung tanpa menunggu usulan. Tetap tercatat
 * sebagai usulan berstatus 'disetujui' (sumber 'langsung') agar ada jejak audit.
 * Menjalankan transaksi sendiri. Mengembalikan id CRF.
 */
function forumDirectSet(PDO $pdo, int $crfId, array $user, ?string $urgency, float $value, string $unit, string $reason): int
{
    try {
        $pdo->beginTransaction();

        $crf = forumLockCrf($pdo, $crfId);
        if (!$crf) {
            throw new DomainException('CRF tidak ditemukan atau sudah tidak dalam proses.');
        }

        $kind = forumProposalStageAllows($crf, 'urgensi_sla') ? 'urgensi_sla'
            : (forumProposalStageAllows($crf, 'perpanjangan_sla') ? 'perpanjangan_sla' : null);
        if ($kind === null) {
            throw new DomainException('Urgensi dan SLA tidak dapat diubah pada tahap CRF ini.');
        }
        if (forumOpenProposal($pdo, $crfId)) {
            throw new DomainException('Ada usulan yang menunggu keputusan. Putuskan usulan tersebut terlebih dahulu.');
        }
        if ($kind === 'urgensi_sla') {
            $urgency = forumParseUrgency($urgency);
        } else {
            $urgency = null;
            $currentSeconds = $crf['sla_value'] !== null && $crf['sla_unit']
                ? forumSlaSeconds((float) $crf['sla_value'], (string) $crf['sla_unit'])
                : 0;
            if (forumSlaSeconds($value, $unit) <= $currentSeconds) {
                throw new DomainException('SLA baru harus lebih lama dari SLA saat ini (' . slaLabel($crf['sla_value'], $crf['sla_unit']) . ').');
            }
        }

        $before = forumCurrentAgreement($crf);
        $actor = crfActorName($user);
        $actorRole = getCrfRole();
        $now = date('Y-m-d H:i:s');

        forumApplyAgreement($pdo, $crf, $kind, $urgency, $value, $unit);

        $pdo->prepare(
            "INSERT INTO forum_proposals
                (change_request_id, kind, status, trigger_source,
                 proposed_by, proposed_by_name, proposed_by_role,
                 proposed_urgency, proposed_sla_value, proposed_sla_unit, reason,
                 before_urgency, before_sla_value, before_sla_unit,
                 final_urgency, final_sla_value, final_sla_unit,
                 decided_by, decided_by_name, decision_note, decided_at, open_crf_id)
             VALUES
                (:crf_id, :kind, 'disetujui', 'langsung',
                 :by, :by_name, :by_role,
                 :urgency, :value, :unit, :reason,
                 :b_urgency, :b_value, :b_unit,
                 :f_urgency, :f_value, :f_unit,
                 :d_by, :d_by_name, :d_note, :now, NULL)"
        )->execute([
            'crf_id' => $crfId,
            'kind' => $kind,
            'by' => (int) $user['id'],
            'by_name' => $actor,
            'by_role' => $actorRole,
            'urgency' => $urgency,
            'value' => $value,
            'unit' => $unit,
            'reason' => $reason,
            'b_urgency' => $before['urgency'],
            'b_value' => $before['sla_value'],
            'b_unit' => $before['sla_unit'],
            'f_urgency' => $urgency,
            'f_value' => $value,
            'f_unit' => $unit,
            'd_by' => (int) $user['id'],
            'd_by_name' => $actor,
            'd_note' => $reason,
            'now' => $now,
        ]);

        $crfNumber = $crf['request_number'] ?: 'CRF #' . $crfId;
        $roleLabel = forumRoleName($actorRole);
        $withUrgency = $kind === 'urgensi_sla';
        $afterText = forumValuesText($urgency, $value, $unit, $withUrgency);
        $beforeText = forumValuesText($before['urgency'], $before['sla_value'], $before['sla_unit'], $withUrgency);
        if ($kind === 'urgensi_sla') {
            $description = forumSystemCommentPrefix() . $roleLabel . '. Nilai ditetapkan langsung: ' . $afterText
                . ' (sebelumnya ' . $beforeText . '). Alasan: ' . $reason;
            $activity = 'Kesepakatan Urgensi dan SLA Forum';
        } else {
            $description = 'Perpanjangan SLA ditetapkan langsung oleh ' . $roleLabel . ': ' . $beforeText . ' menjadi '
                . $afterText . '. Alasan: ' . $reason;
            $activity = 'Perpanjangan SLA';
        }
        logCrfActivity($pdo, $crfId, $activity, $description, $actor);
        forumAddSystemComment($pdo, $crfId, $user, $description);

        $recipients = forumParticipantIds($pdo, $crfId, (int) $crf['assigned_handler_id'] ?: null);
        if ($crf['workflow_stage'] === 'CMO_FILTER') {
            $recipients = array_merge($recipients, crfUserIdsForRole($pdo, 'cmo'));
        }
        forumNotify(
            $pdo,
            $recipients,
            'Kesepakatan Forum: ' . $crfNumber,
            $actor . ' menetapkan ' . $afterText . ' (sebelumnya ' . $beforeText . ').'
                . ($crf['workflow_stage'] === 'CMO_FILTER' ? ' CMO dapat meneruskan CRF ke Kepala Departemen Operasional.' : ''),
            $crfId,
            (int) $user['id']
        );

        $pdo->commit();

        return $crfId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* =================================================================
 * Membatalkan & menutup
 * ================================================================= */

/**
 * Pengusul (atau penentu) membatalkan usulan yang masih menunggu.
 * Menjalankan transaksi sendiri. Mengembalikan id CRF.
 */
function forumCancelProposal(PDO $pdo, int $proposalId, array $user): int
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM forum_proposals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $proposalId]);
        $proposal = $stmt->fetch();
        if (!$proposal || $proposal['status'] !== 'menunggu') {
            throw new DomainException('Usulan ini sudah diputuskan atau ditutup.');
        }

        $isProposer = !empty($proposal['proposed_by']) && (int) $proposal['proposed_by'] === (int) $user['id'];
        if (!$isProposer && !canDecideForumProposal()) {
            throw new DomainException('Hanya pengusul atau penentu yang dapat membatalkan usulan.');
        }

        $crfId = (int) $proposal['change_request_id'];
        $actor = crfActorName($user);
        $now = date('Y-m-d H:i:s');

        $pdo->prepare(
            "UPDATE forum_proposals
             SET status = 'dibatalkan', open_crf_id = NULL,
                 decided_by = :by, decided_by_name = :by_name,
                 decision_note = 'Dibatalkan.', decided_at = :now
             WHERE id = :id"
        )->execute(['by' => (int) $user['id'], 'by_name' => $actor, 'now' => $now, 'id' => $proposalId]);

        $text = 'Usulan ' . forumProposalKindLabel($proposal['kind']) . ' dari ' . $proposal['proposed_by_name']
            . ' dibatalkan oleh ' . $actor . '.';
        logCrfActivity($pdo, $crfId, 'Usulan Dibatalkan', $text, $actor);
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
 * Tutup usulan terbuka milik CRF karena tahap berubah (disetujui Kadep,
 * dibatalkan, selesai dikerjakan). Dipanggil DI DALAM transaksi pemanggil;
 * tidak pernah melempar error agar tidak menggagalkan aksi utama.
 */
function forumCloseOpenProposals(PDO $pdo, int $crfId, string $status, string $note): int
{
    try {
        $proposal = forumOpenProposal($pdo, $crfId);
        if (!$proposal) {
            return 0;
        }

        $pdo->prepare(
            "UPDATE forum_proposals
             SET status = :status, open_crf_id = NULL, decided_by_name = 'Sistem',
                 decision_note = :note, decided_at = NOW()
             WHERE id = :id AND status = 'menunggu'"
        )->execute(['status' => $status, 'note' => $note, 'id' => (int) $proposal['id']]);

        $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $text = 'Usulan ' . forumProposalKindLabel($proposal['kind']) . ' dari ' . $proposal['proposed_by_name'] . ' ditutup. ' . $note;
        logCrfActivity($pdo, $crfId, 'Usulan Ditutup', $text, 'Sistem');
        if ($user) {
            forumAddSystemComment($pdo, $crfId, $user, $text);
        }
        if (!empty($proposal['proposed_by'])) {
            forumNotify(
                $pdo,
                [(int) $proposal['proposed_by']],
                'Usulan ditutup: CRF #' . $crfId,
                $text,
                $crfId,
                (int) ($user['id'] ?? 0)
            );
        }

        return 1;
    } catch (Throwable $e) {
        error_log('forumCloseOpenProposals: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Pengingat ke penentu untuk usulan yang melewati batas keputusan.
 * Dijalankan "malas" saat halaman terkait dibuka; satu pengingat per usulan.
 */
function forumSendDueReminders(PDO $pdo): void
{
    try {
        $rows = $pdo->query(
            "SELECT p.id, p.change_request_id, p.kind, p.trigger_source, p.proposed_by_name, p.due_at, cr.request_number
             FROM forum_proposals p
             INNER JOIN change_requests cr ON cr.id = p.change_request_id
             WHERE p.status = 'menunggu' AND p.reminded_at IS NULL
               AND p.due_at IS NOT NULL AND p.due_at < NOW()
             LIMIT 20"
        )->fetchAll();

        $sent = false;
        foreach ($rows as $row) {
            $mark = $pdo->prepare('UPDATE forum_proposals SET reminded_at = NOW() WHERE id = :id AND reminded_at IS NULL');
            $mark->execute(['id' => (int) $row['id']]);
            if ($mark->rowCount() !== 1) {
                continue;
            }
            $crfId = (int) $row['change_request_id'];
            forumNotify(
                $pdo,
                forumDeciderUserIds($pdo),
                'Usulan melewati batas keputusan: ' . ($row['request_number'] ?: 'CRF #' . $crfId),
                'Usulan ' . forumProposalKindLabel($row['kind']) . ' dari ' . $row['proposed_by_name']
                    . ' belum diputuskan sejak batas ' . date('d-m-Y H:i', strtotime($row['due_at']))
                    . ($row['trigger_source'] === 'sistem'
                        ? '. CRF belum dapat disetujui Kepala Departemen Operasional sebelum SLA ditetapkan.'
                        : '. Selama belum diputuskan, nilai yang berlaku tidak berubah.'),
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
