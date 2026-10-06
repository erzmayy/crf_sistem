<?php
/**
 * Partial: Informasi Pengajuan.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 *
 * Variabel OPSIONAL:
 *   bool $showStatusInGrid
 */

$showStatusInGrid = $showStatusInGrid ?? false;

// Ticket Helpdesk asal CRF (link dua arah Helpdesk <-> CRF).
$infoTicket = null;
if (!empty($crf['helpdesk_ticket_id'])) {
    $infoTicketStmt = getConnection()->prepare('
        SELECT t.id, t.created_at, c.name AS category_name
        FROM helpdesk_tickets t
        JOIN helpdesk_categories c ON c.id = t.helpdesk_category_id
        WHERE t.id = :id
    ');
    $infoTicketStmt->execute(['id' => $crf['helpdesk_ticket_id']]);
    $infoTicket = $infoTicketStmt->fetch() ?: null;
}
$infoDisplayStatus = crfDisplayStatus($crf);
?>
<div class="crf-section crf-detail-card mb-4">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-person-lines-fill"></i></span>
        <h2>Informasi Pengajuan</h2>
    </div>

    <div class="crf-section-body">
        <div class="crf-info-rows">
            <div class="crf-info-row">
                <span class="crf-info-label">Nama Lengkap</span>
                <span class="crf-info-value"><?= h($crf['full_name'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">No. Handphone / WA</span>
                <span class="crf-info-value"><?= h($crf['phone'] ?? '-') ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Email Pemohon</span>
                <span class="crf-info-value"><?= h($crf['email'] ?? '-') ?></span>
            </div>
            <?php if (!empty($crf['requester_position'])): ?>
                <div class="crf-info-row">
                    <span class="crf-info-label">Jabatan</span>
                    <span class="crf-info-value"><?= h($crf['requester_position']) ?></span>
                </div>
            <?php endif; ?>
            <div class="crf-info-row">
                <span class="crf-info-label">Nomor Register</span>
                <span class="crf-info-value crf-info-register"><?= h($crf['request_number'] ?? '-') ?></span>
            </div>
            <?php if ($infoTicket): /* hanya CRF lama yang dibuat dari ticket Helpdesk */ ?>
            <div class="crf-info-row">
                <span class="crf-info-label">Ticket Helpdesk</span>
                <span class="crf-info-value">
                    <a href="../helpdesk/detail.php?id=<?= (int) $infoTicket['id'] ?>" class="crf-link">
                        <i class="bi bi-ticket-detailed"></i> <?= h(helpdeskTicketLabel($infoTicket)) ?>
                    </a>
                </span>
            </div>
            <?php endif; ?>
            <div class="crf-info-row">
                <span class="crf-info-label">Kategori CRF</span>
                <span class="crf-info-value"><?= h(crfCategoryName($crf)) ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">PIC CRF</span>
                <span class="crf-info-value"><?= h($crf['assigned_handler_name'] ?? '') ?: '<span class="text-muted">Belum ada</span>' ?></span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Tanggal Pengajuan</span>
                <span class="crf-info-value">
                    <?php if (!empty($crf['submission_date'])): ?>
                        <?= h(formatTanggalIndonesia(new DateTime($crf['submission_date']))) ?>
                    <?php else: ?>
                        <span class="text-muted">Belum diajukan</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Kepada</span>
                <span class="crf-info-value">
                    <?= h($crf['to_department'] ?? '-') ?>
                    <?php if (!empty($crf['to_division'])): ?>
                        <span class="crf-info-sub"><?= h($crf['to_division']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="crf-info-row">
                <span class="crf-info-label">Dari</span>
                <span class="crf-info-value">
                    <?= h($crf['from_department'] ?? '-') ?>
                    <?php if (!empty($crf['from_division'])): ?>
                        <span class="crf-info-sub"><?= h($crf['from_division']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($showStatusInGrid): ?>
                <div class="crf-info-row">
                    <span class="crf-info-label">Status</span>
                    <span class="crf-info-value">
                        <span class="crf-badge crf-info-status <?= h($infoDisplayStatus['class']) ?>">
                            <?= h($infoDisplayStatus['label']) ?>
                        </span>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
unset($showStatusInGrid, $infoTicket, $infoTicketStmt, $infoDisplayStatus);
