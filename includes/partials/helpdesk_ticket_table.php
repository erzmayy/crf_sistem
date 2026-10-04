<?php
/**
 * Tabel ticket Helpdesk (dipakai Tiket Saya, Dashboard Helpdesk, Daftar per Kategori).
 *
 * Variabel: $tickets (array), $ticketOffset (int), $ticketEmptyText (string),
 *           $ticketShowRequester (bool), $ticketShowFollowUp (bool).
 * Baris boleh memuat kolom crf_id & crf_number (CRF terkait pertama).
 */
$ticketShowRequester = $ticketShowRequester ?? true;
$ticketShowFollowUp = $ticketShowFollowUp ?? true;
?>
<div class="table-responsive crf-table-responsive-cards crf-helpdesk-table-wrap">
    <table class="table crf-table crf-helpdesk-table align-middle">
        <thead>
            <tr>
                <th>No</th>
                <th>Keterangan Helpdesk</th>
                <th>Isi Helpdesk</th>
                <?php if ($ticketShowFollowUp): ?>
                    <th>Tindak Lanjut</th>
                <?php endif; ?>
                <th>Level</th>
                <th>SLA</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$tickets): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox d-block fs-3 mb-1"></i>
                    <?= h($ticketEmptyText ?? 'Belum ada ticket.') ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php foreach ($tickets as $i => $ticketRow): ?>
            <?php $ticketSla = helpdeskTicketSla($ticketRow); ?>
            <tr>
                <td data-label="No"><?= ($ticketOffset ?? 0) + $i + 1 ?></td>
                <td data-label="Keterangan Helpdesk">
                    <div class="crf-request-meta">
                        <?php if ($ticketShowRequester): ?>
                            <span class="crf-request-caption">Oleh</span>
                            <strong><?= h($ticketRow['full_name']) ?></strong>
                        <?php endif; ?>
                        <span class="crf-request-register"><?= h($ticketRow['ticket_number']) ?></span>
                        <div class="crf-request-date-card">
                            <span class="crf-request-caption">Waktu Permintaan</span>
                            <span><?= h(date('d-m-Y H:i', strtotime($ticketRow['created_at']))) ?></span>
                        </div>
                    </div>
                </td>
                <td data-label="Isi Helpdesk">
                    <div class="crf-request-content">
                        <span class="crf-request-category-chip"><i class="bi bi-tag"></i> <?= h($ticketRow['category_name']) ?></span>
                        <div class="crf-request-description"><?= h(mb_strimwidth((string) $ticketRow['message'], 0, 220, '…')) ?></div>
                        <?php if (!empty($ticketRow['crf_id'])): ?>
                            <a class="crf-link small" href="../crf/open.php?id=<?= (int) $ticketRow['crf_id'] ?>">
                                <i class="bi bi-link-45deg"></i> <?= h($ticketRow['crf_number'] ?: 'Draft CRF') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </td>
                <?php if ($ticketShowFollowUp): ?>
                    <td data-label="Tindak Lanjut">
                        <?php if (!empty($ticketRow['follow_up'])): ?>
                            <div class="small text-muted"><i class="bi bi-clock"></i> <?= h(date('d-m-Y H:i', strtotime($ticketRow['handled_at'] ?? $ticketRow['updated_at']))) ?></div>
                            <div class="small"><?= h(mb_strimwidth((string) $ticketRow['follow_up'], 0, 160, '…')) ?></div>
                            <?php if (!empty($ticketRow['handled_by_name'])): ?>
                                <div class="small text-muted">oleh <?= h($ticketRow['handled_by_name']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted small fst-italic">Belum ada tindak lanjut</span>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td data-label="Level">
                    <?php if (!empty($ticketRow['level'])): ?>
                        <span class="crf-badge <?= helpdeskLevelBadgeClass($ticketRow['level']) ?>"><?= h($ticketRow['level']) ?></span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td data-label="SLA">
                    <?php if ($ticketSla['duration'] !== null): ?>
                        <div class="small"><i class="bi bi-stopwatch"></i> <?= h($ticketSla['duration']) ?></div>
                    <?php endif; ?>
                    <span class="badge text-bg-<?= h($ticketSla['class']) ?>"><?= h($ticketSla['label']) ?></span>
                </td>
                <td data-label="Status">
                    <span class="crf-badge <?= helpdeskStatusBadgeClass($ticketRow['status']) ?>"><?= h($ticketRow['status']) ?></span>
                </td>
                <td data-label="Aksi">
                    <a href="detail.php?id=<?= (int) $ticketRow['id'] ?>" class="btn btn-sm btn-crf-outline">
                        <i class="bi bi-eye"></i> Detail
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
unset($ticketShowRequester, $ticketShowFollowUp);
