<?php
/**
 * actions/forum_proposal.php
 * Aksi usulan Urgensi & SLA di Forum (logika di includes/forum_proposals.php).
 *   op=create  : ajukan usulan (CMO, Admin, Petugas Otomasi kategori).
 *   op=decide  : setujui / tolak usulan (penentu: forumDeciderRoles()).
 *   op=cancel  : batalkan usulan yang menunggu (pengusul atau penentu).
 *   op=direct  : penentu menetapkan langsung tanpa usulan (tetap tercatat).
 */
require_once __DIR__ . '/../includes/forum_proposals.php';
requireForumAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

verifyCsrf();

$pdo = getConnection();
$user = getCurrentUser();
$op = is_string($_POST['op'] ?? null) ? $_POST['op'] : '';
$crfId = filter_input(INPUT_POST, 'crf_id', FILTER_VALIDATE_INT) ?: 0;
$proposalId = filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT) ?: 0;

$back = static function (int $id): string {
    return '../forum/index.php' . ($id > 0 ? '?crf_id=' . $id . '#forum-proposal' : '');
};

try {
    switch ($op) {
        case 'create':
            [$value, $unit] = forumParseSla($_POST['sla_value'] ?? null, $_POST['sla_unit'] ?? null);
            $reason = forumParseText($_POST['reason'] ?? null, 1000, true, 'Alasan');
            $urgency = is_string($_POST['urgency'] ?? null) ? $_POST['urgency'] : null;
            forumSubmitProposal($pdo, $crfId, $user, $urgency, $value, $unit, $reason);
            $message = 'Usulan berhasil dikirim dan menunggu keputusan.';
            break;

        case 'decide':
            $decision = ($_POST['decision'] ?? '') === 'reject' ? 'reject' : 'approve';
            if (!canDecideForumProposal()) {
                throw new DomainException('Hanya penentu (' . implode(', ', array_map('crfRoleLabel', forumDeciderRoles())) . ') yang dapat memutuskan usulan.');
            }
            $note = forumParseText($_POST['note'] ?? null, 1000, $decision === 'reject', 'Catatan');
            $result = forumDecideProposal(
                $pdo,
                $proposalId,
                $user,
                $decision,
                is_string($_POST['urgency'] ?? null) ? $_POST['urgency'] : null,
                $_POST['sla_value'] ?? null,
                $_POST['sla_unit'] ?? null,
                $note
            );
            $crfId = $result['crf_id'];
            $message = $result['message'];
            break;

        case 'cancel':
            $crfId = forumCancelProposal($pdo, $proposalId, $user);
            $message = 'Usulan dibatalkan.';
            break;

        case 'direct':
            if (!canDecideForumProposal()) {
                throw new DomainException('Hanya penentu (' . implode(', ', array_map('crfRoleLabel', forumDeciderRoles())) . ') yang dapat menetapkan nilai langsung.');
            }
            [$value, $unit] = forumParseSla($_POST['sla_value'] ?? null, $_POST['sla_unit'] ?? null);
            $reason = forumParseText($_POST['reason'] ?? null, 1000, true, 'Alasan');
            forumDirectSet(
                $pdo,
                $crfId,
                $user,
                is_string($_POST['urgency'] ?? null) ? $_POST['urgency'] : null,
                $value,
                $unit,
                $reason
            );
            $message = 'Urgensi dan SLA berhasil ditetapkan.';
            break;

        default:
            throw new DomainException('Aksi tidak dikenal.');
    }

    dispatchPendingNotificationEmails($pdo);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
} catch (DomainException $e) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('forum_proposal [' . $op . '] error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Aksi gagal diproses. Silakan coba lagi atau hubungi administrator.'];
}

header('Location: ' . $back($crfId));
exit;
