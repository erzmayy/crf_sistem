<?php
/**
 * includes/helpdesk.php
 * ---------------------------------------------------------------
 * Modul Helpdesk: ticket, status, SLA, dan jembatan ke CRF.
 *
 * Seluruh akses tabel helpdesk_* dibungkus di file ini agar saat
 * dipindahkan ke SIAP cukup menyesuaikan query di sini.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

const HELPDESK_STATUSES = [
    'Belum Ditindaklanjuti',
    'Dalam Proses',
    'Diteruskan ke CRF',
    'Selesai',
    'Dibatalkan',
];

/**
 * Status yang boleh dipilih PIC saat tindak lanjut ticket non-CRF.
 */
function helpdeskStatusTransitions(): array
{
    return [
        'Belum Ditindaklanjuti' => ['Belum Ditindaklanjuti', 'Dalam Proses', 'Selesai', 'Dibatalkan'],
        'Dalam Proses'          => ['Dalam Proses', 'Selesai', 'Dibatalkan'],
        'Diteruskan ke CRF'     => [],
        'Selesai'               => [],
        'Dibatalkan'            => [],
    ];
}

function helpdeskStatusBadgeClass(string $status): string
{
    switch ($status) {
        case 'Dalam Proses':
            return 'badge-status-proses';
        case 'Diteruskan ke CRF':
            return 'badge-stage-otomasi';
        case 'Selesai':
            return 'badge-status-solve';
        case 'Dibatalkan':
            return 'badge-status-cancel';
        default:
            return 'badge-status-revisi';
    }
}

function helpdeskRequestKinds(): array
{
    return [
        'maintenance' => ['label' => 'Maintenance / Kegiatan Rutin', 'hint' => 'Perawatan & pekerjaan berkala', 'icon' => 'bi-wrench'],
        'request'     => ['label' => 'Request / Permintaan', 'hint' => 'Peminjaman, akses, atau kebutuhan baru', 'icon' => 'bi-hand-index'],
        'komplain'    => ['label' => 'Komplain', 'hint' => 'Kendala atau gangguan yang dialami', 'icon' => 'bi-exclamation-circle'],
    ];
}

function helpdeskLevelBadgeClass(?string $level): string
{
    switch ($level) {
        case 'Tinggi':
            return 'badge-level-tinggi';
        case 'Sedang':
            return 'badge-level-sedang';
        case 'Rendah':
            return 'badge-level-kecil';
        default:
            return 'badge-level-none';
    }
}


/**
 * Request/Permintaan pada kategori "via CRF" diajukan langsung lewat Form CRF
 * (tidak menjadi ticket Helpdesk). Maintenance & Komplain pada kategori yang
 * sama tetap menjadi ticket biasa yang ditindaklanjuti PIC.
 */
function helpdeskRoutesToCrf(array $source, string $requestKind): bool
{
    return !empty($source['requires_crf']) && $requestKind === 'request';
}

/**
 * Ticket lama (sebelum Request diarahkan langsung ke Form CRF) yang sudah
 * diteruskan ke CRF: status mengikuti CRF, tidak ditindaklanjuti PIC.
 */
function helpdeskTicketViaCrf(array $ticket): bool
{
    return ($ticket['status'] ?? '') === 'Diteruskan ke CRF' || !empty($ticket['crf_id']);
}

/**
 * Buat ticket baru (dipanggil di dalam transaksi). Mengembalikan id ticket.
 */
function createHelpdeskTicket(PDO $pdo, array $user, array $category, array $data): int
{
    $status = 'Belum Ditindaklanjuti';

    $stmt = $pdo->prepare('
        INSERT INTO helpdesk_tickets (
            user_id, full_name, phone, email, department, division,
            helpdesk_category_id, request_kind, report_time, message, status,
            sla_value, sla_unit
        ) VALUES (
            :user_id, :full_name, :phone, :email, :department, :division,
            :category_id, :request_kind, :report_time, :message, :status,
            :sla_value, :sla_unit
        )
    ');
    $stmt->execute([
        'user_id'       => (int) $user['id'],
        'full_name'     => crfActorName($user),
        'phone'         => $data['phone'] ?? ($user['no_wa'] ?? null),
        'email'         => $user['email'] ?? null,
        'department'    => $user['dept'] ?? null,
        'division'      => $user['divisi'] ?? null,
        'category_id'   => (int) $category['id'],
        'request_kind'  => $data['request_kind'],
        'report_time'   => $data['report_time'] ?: null,
        'message'       => $data['message'],
        'status'        => $status,
        'sla_value'     => $category['sla_value'],
        'sla_unit'      => $category['sla_unit'],
    ]);

    $ticketId = (int) $pdo->lastInsertId();

    logHelpdeskActivity(
        $pdo,
        $ticketId,
        'Ticket Dibuat',
        'Ticket dibuat pada kategori ' . $category['name'] . '.',
        crfActorName($user),
        null,
        $status
    );

    return $ticketId;
}

function logHelpdeskActivity(
    PDO $pdo,
    int $ticketId,
    string $activity,
    string $description,
    string $actor,
    ?string $oldStatus = null,
    ?string $newStatus = null
): void {
    $pdo->prepare('
        INSERT INTO helpdesk_activity_logs
            (helpdesk_ticket_id, user_id, actor, activity, old_status, new_status, description)
        VALUES
            (:ticket_id, :user_id, :actor, :activity, :old_status, :new_status, :description)
    ')->execute([
        'ticket_id'   => $ticketId,
        'user_id'     => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'actor'       => $actor,
        'activity'    => $activity,
        'old_status'  => $oldStatus,
        'new_status'  => $newStatus,
        'description' => $description,
    ]);
}

function findHelpdeskTicket(PDO $pdo, int $ticketId): ?array
{
    $stmt = $pdo->prepare('
        SELECT t.*, c.name AS category_name, c.icon AS category_icon, c.requires_crf,
            (SELECT cr.id FROM change_requests cr WHERE cr.helpdesk_ticket_id = t.id ORDER BY cr.id LIMIT 1) AS crf_id
        FROM helpdesk_tickets t
        JOIN helpdesk_categories c ON c.id = t.helpdesk_category_id
        WHERE t.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $ticketId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * CRF yang terhubung dengan ticket (1 ticket bisa punya >1 CRF).
 */
function ticketLinkedCrfs(PDO $pdo, int $ticketId): array
{
    $stmt = $pdo->prepare('
        SELECT cr.id, cr.request_number, cr.status, cr.workflow_stage, cr.kadep_operasional_approved_at,
               cr.user_id, cr.crf_category_id, cr.created_at, c.name AS category_name
        FROM change_requests cr
        LEFT JOIN crf_categories c ON c.id = cr.crf_category_id
        WHERE cr.helpdesk_ticket_id = :ticket_id
        ORDER BY cr.id
    ');
    $stmt->execute(['ticket_id' => $ticketId]);

    return $stmt->fetchAll();
}

/**
 * Pemilik, admin, PIC kategori, serta CMO/Kadep/Handler untuk ticket
 * yang sudah menjadi CRF.
 */
function canAccessTicket(PDO $pdo, array $ticket): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = getCrfRole();

    if ((int) $ticket['user_id'] === $userId || in_array($role, ['admin', 'demo'], true)) {
        return true;
    }

    if (in_array((int) $ticket['helpdesk_category_id'], picHelpdeskCategoryIds($pdo, $userId), true)) {
        return true;
    }

    foreach (ticketLinkedCrfs($pdo, (int) $ticket['id']) as $crf) {
        if ($crf['status'] !== 'Draft' && canAccessCrf($pdo, (int) $crf['id'])) {
            return true;
        }
    }

    return false;
}

/**
 * PIC kategori (atau akun demo) boleh menindaklanjuti ticket non-CRF.
 * Admin hanya memantau, kecuali ia juga terdaftar sebagai PIC kategori.
 * Ticket yang diteruskan ke CRF statusnya mengikuti workflow CRF.
 */
function canManageTicket(PDO $pdo, array $ticket): bool
{
    if (helpdeskTicketViaCrf($ticket)) {
        return false;
    }

    if (isDemoUser()) {
        return true;
    }

    return in_array(
        (int) $ticket['helpdesk_category_id'],
        picHelpdeskCategoryIds($pdo, (int) ($_SESSION['user_id'] ?? 0)),
        true
    );
}

/**
 * Sinkronkan status ticket dari seluruh CRF yang terhubung.
 * Dipanggil di dalam transaksi setiap kali status CRF berubah.
 */
function syncHelpdeskTicketFromCrf(PDO $pdo, int $crfId, string $actor): void
{
    $stmt = $pdo->prepare('SELECT helpdesk_ticket_id FROM change_requests WHERE id = :id');
    $stmt->execute(['id' => $crfId]);
    $ticketId = (int) $stmt->fetchColumn();

    if ($ticketId <= 0) {
        return;
    }

    $ticketStmt = $pdo->prepare('SELECT * FROM helpdesk_tickets WHERE id = :id FOR UPDATE');
    $ticketStmt->execute(['id' => $ticketId]);
    $ticket = $ticketStmt->fetch();

    if (!$ticket) {
        return;
    }

    $statuses = array_column(ticketLinkedCrfs($pdo, $ticketId), 'status');
    $active = array_values(array_diff($statuses, ['Draft']));

    if (!$active) {
        $newStatus = 'Diteruskan ke CRF';
    } elseif (in_array('Dalam Proses', $active, true)) {
        $newStatus = 'Dalam Proses';
    } elseif (array_diff($active, ['Solve', 'Cancel']) === []) {
        $newStatus = in_array('Solve', $active, true) ? 'Selesai' : 'Dibatalkan';
    } else {
        $newStatus = 'Diteruskan ke CRF';
    }

    if ($newStatus === $ticket['status']) {
        return;
    }

    $pdo->prepare('
        UPDATE helpdesk_tickets
        SET status = :status,
            completed_at = CASE WHEN :is_done = 1 THEN NOW() ELSE NULL END,
            cancelled_at = CASE WHEN :is_cancel = 1 THEN NOW() ELSE NULL END
        WHERE id = :id
    ')->execute([
        'status'    => $newStatus,
        'is_done'   => $newStatus === 'Selesai' ? 1 : 0,
        'is_cancel' => $newStatus === 'Dibatalkan' ? 1 : 0,
        'id'        => $ticketId,
    ]);

    logHelpdeskActivity(
        $pdo,
        $ticketId,
        'Status Mengikuti CRF',
        'Status ticket diperbarui otomatis dari progres CRF.',
        $actor,
        $ticket['status'],
        $newStatus
    );
}

/**
 * Status SLA ticket berdasarkan SLA kategori saat ticket dibuat.
 * Ticket yang diteruskan ke CRF tidak dinilai dengan SLA ticket: pekerjaannya
 * mengikuti alur & SLA CRF (dimulai setelah approval Kepala Departemen).
 *
 * @return array{target:string,duration:?string,label:string,class:string,via_crf?:bool}
 */
function helpdeskTicketSla(array $ticket): array
{
    if (helpdeskTicketViaCrf($ticket)) {
        return [
            'target'   => 'Mengikuti SLA CRF',
            'duration' => null,
            'label'    => 'Mengikuti SLA CRF',
            'class'    => 'secondary',
            'via_crf'  => true,
        ];
    }

    $target = slaLabel($ticket['sla_value'] ?? null, $ticket['sla_unit'] ?? null);
    $due = slaDueAt($ticket['created_at'] ?? null, $ticket['sla_value'] ?? null, $ticket['sla_unit'] ?? null);

    if ($ticket['status'] === 'Dibatalkan') {
        return ['target' => $target, 'duration' => null, 'label' => 'Tidak berlaku', 'class' => 'secondary'];
    }

    $start = strtotime((string) $ticket['created_at']);
    $end = !empty($ticket['completed_at']) ? strtotime($ticket['completed_at']) : time();
    $duration = formatSlaDuration(max(0, $end - $start));

    if ($due === null) {
        return ['target' => $target, 'duration' => $duration, 'label' => 'SLA belum diatur', 'class' => 'secondary'];
    }

    $onTime = $end <= strtotime($due);

    if (!empty($ticket['completed_at'])) {
        return [
            'target'   => $target,
            'duration' => $duration,
            'label'    => $onTime ? 'Sesuai SLA' : 'Melebihi SLA',
            'class'    => $onTime ? 'success' : 'danger',
        ];
    }

    return [
        'target'   => $target,
        'duration' => $duration,
        'label'    => $onTime ? 'Dalam SLA' : 'Melewati SLA',
        'class'    => $onTime ? 'info' : 'danger',
    ];
}

/**
 * Kondisi WHERE ticket dari filter. $categoryScope = null berarti semua
 * kategori; array berarti dibatasi ke kategori tersebut (PIC).
 */
function helpdeskTicketWhere(array $filters, ?array $categoryScope, array &$params): string
{
    $where = ['1 = 1'];

    if ($categoryScope !== null) {
        $where[] = $categoryScope
            ? 't.helpdesk_category_id IN (' . implode(',', array_map('intval', $categoryScope)) . ')'
            : '1 = 0';
    }
    if (!empty($filters['user_id'])) {
        $where[] = 't.user_id = :f_user_id';
        $params['f_user_id'] = (int) $filters['user_id'];
    }
    if (!empty($filters['category_id'])) {
        $where[] = 't.helpdesk_category_id = :f_category_id';
        $params['f_category_id'] = (int) $filters['category_id'];
    }
    if (!empty($filters['status']) && in_array($filters['status'], HELPDESK_STATUSES, true)) {
        $where[] = 't.status = :f_status';
        $params['f_status'] = $filters['status'];
    }
    if (!empty($filters['open_only'])) {
        $where[] = "t.status NOT IN ('Selesai', 'Dibatalkan')";
    }
    foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
        $value = (string) ($filters[$key] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date && $date->format('Y-m-d') === $value) {
            $where[] = "DATE(t.created_at) {$operator} :f_{$key}";
            $params['f_' . $key] = $value;
        }
    }
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(t.full_name LIKE :f_search_name OR t.message LIKE :f_search_message)';
        $params['f_search_name'] = $params['f_search_message'] = '%' . $search . '%';
    }

    return implode(' AND ', $where);
}

/**
 * @return array{rows:array,total:int,page:int,total_pages:int,offset:int}
 */
function listHelpdeskTickets(PDO $pdo, array $filters, ?array $categoryScope, int $perPage, int $page): array
{
    $params = [];
    $where = helpdeskTicketWhere($filters, $categoryScope, $params);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM helpdesk_tickets t WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT t.*, c.name AS category_name, c.requires_crf,
            (SELECT cr.id FROM change_requests cr WHERE cr.helpdesk_ticket_id = t.id ORDER BY cr.id LIMIT 1) AS crf_id,
            (SELECT cr.request_number FROM change_requests cr WHERE cr.helpdesk_ticket_id = t.id ORDER BY cr.id LIMIT 1) AS crf_number
        FROM helpdesk_tickets t
        JOIN helpdesk_categories c ON c.id = t.helpdesk_category_id
        WHERE {$where}
        ORDER BY t.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);

    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'offset' => $offset,
    ];
}

/**
 * Ringkasan jumlah ticket per status.
 */
function helpdeskTicketSummary(PDO $pdo, array $filters, ?array $categoryScope): array
{
    $params = [];
    $where = helpdeskTicketWhere(array_diff_key($filters, ['status' => 1]), $categoryScope, $params);
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(t.status = 'Selesai') AS selesai,
            SUM(t.status IN ('Dalam Proses', 'Diteruskan ke CRF')) AS proses,
            SUM(t.status = 'Belum Ditindaklanjuti') AS belum,
            SUM(t.status = 'Dibatalkan') AS batal
        FROM helpdesk_tickets t
        WHERE {$where}
    ");
    $stmt->execute($params);

    return array_map('intval', $stmt->fetch() ?: []);
}

/**
 * Kategori yang boleh dipantau user di dashboard Helpdesk:
 * null = semua (admin), array = kategori PIC.
 */
function helpdeskCategoryScopeForUser(PDO $pdo): ?array
{
    // Admin & demo memantau semua kategori.
    return isAdmin()
        ? null
        : picHelpdeskCategoryIds($pdo, (int) ($_SESSION['user_id'] ?? 0));
}

function helpdeskTicketTimeline(PDO $pdo, int $ticketId): array
{
    $stmt = $pdo->prepare('
        SELECT * FROM helpdesk_activity_logs
        WHERE helpdesk_ticket_id = :id
        ORDER BY created_at, id
    ');
    $stmt->execute(['id' => $ticketId]);

    return $stmt->fetchAll();
}
