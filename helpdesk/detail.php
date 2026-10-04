<?php
/**
 * helpdesk/detail.php
 * Detail ticket Helpdesk: data pelapor, isi, lampiran, tindak lanjut,
 * SLA, CRF Terkait, dan timeline.
 */
require_once __DIR__ . '/../includes/helpdesk.php';

requireLogin();

$pdo = getConnection();
$ticketId = (int) ($_GET['id'] ?? 0);
$ticket = $ticketId > 0 ? findHelpdeskTicket($pdo, $ticketId) : null;

if (!$ticket || !canAccessTicket($pdo, $ticket)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Ticket tidak ditemukan atau Anda tidak memiliki akses.'];
    header('Location: saya.php');
    exit;
}

$canManage = canManageTicket($pdo, $ticket);
$isOwner = (int) $ticket['user_id'] === (int) $_SESSION['user_id'];
$linkedCrfs = ticketLinkedCrfs($pdo, $ticketId);
$timeline = helpdeskTicketTimeline($pdo, $ticketId);
$sla = helpdeskTicketSla($ticket);
$kinds = helpdeskRequestKinds();

$attachmentStmt = $pdo->prepare('SELECT * FROM helpdesk_attachments WHERE helpdesk_ticket_id = :id ORDER BY id');
$attachmentStmt->execute(['id' => $ticketId]);
$attachments = $attachmentStmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$backUrl = $isOwner ? 'saya.php' : 'kategori.php?id=' . (int) $ticket['helpdesk_category_id'];
$pageTitle = 'Ticket ' . helpdeskTicketLabel($ticket);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-helpdesk-page">
    <div class="container">
        <div class="crf-helpdesk-banner crf-banner-with-actions">
            <div>
                <span class="crf-helpdesk-eyebrow">DETAIL TICKET HELPDESK</span>
                <h1><?= h($ticket['category_name']) ?></h1>
                <p>Dibuat <?= h(formatTanggalIndonesia(new DateTime($ticket['created_at']))) ?>, <?= h(date('H:i', strtotime($ticket['created_at']))) ?></p>
            </div>
            <div class="crf-banner-actions">
                <span class="crf-badge <?= helpdeskStatusBadgeClass($ticket['status']) ?> crf-badge-lg"><?= h($ticket['status']) ?></span>
                <?php if ($canManage): ?>
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#followUpModal">
                        <i class="bi bi-pencil-square"></i> Tindak Lanjut
                    </button>
                <?php endif; ?>
                <a href="<?= h($backUrl) ?>" class="btn btn-light"><i class="bi bi-arrow-left"></i> Kembali</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert" style="white-space: pre-line;"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <section class="crf-section">
                    <div class="crf-section-header">
                        <span class="crf-section-number">1</span>
                        <h2>Data Pelapor</h2>
                    </div>
                    <div class="crf-section-body">
                        <dl class="crf-detail-grid">
                            <div><dt>Nama</dt><dd><?= h($ticket['full_name']) ?></dd></div>
                            <div><dt>No HP / WA</dt><dd><?= h($ticket['phone'] ?: '-') ?></dd></div>
                            <div><dt>Email</dt><dd><?= h($ticket['email'] ?: '-') ?></dd></div>
                            <div><dt>Departemen / Divisi</dt><dd><?= h(($ticket['department'] ?: '-') . ' / ' . ($ticket['division'] ?: '-')) ?></dd></div>
                        </dl>
                    </div>
                </section>

                <section class="crf-section">
                    <div class="crf-section-header">
                        <span class="crf-section-number">2</span>
                        <h2>Detail Permintaan</h2>
                    </div>
                    <div class="crf-section-body">
                        <dl class="crf-detail-grid">
                            <div><dt>Kategori</dt><dd><i class="bi <?= h($ticket['category_icon']) ?>"></i> <?= h($ticket['category_name']) ?></dd></div>
                            <div><dt>Kategori / Dampak</dt><dd><?= h($kinds[$ticket['request_kind']]['label'] ?? $ticket['request_kind']) ?></dd></div>
                            <div><dt>Jam Mulai Laporan</dt><dd><?= h($ticket['report_time'] ? substr($ticket['report_time'], 0, 5) : '-') ?></dd></div>
                            <div><dt>Level</dt><dd>
                                <?php if ($ticket['level']): ?>
                                    <span class="crf-badge <?= helpdeskLevelBadgeClass($ticket['level']) ?>"><?= h($ticket['level']) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </dd></div>
                        </dl>
                        <div class="crf-field-label mt-3">Isi Pesan</div>
                        <div class="crf-detail-text"><?= nl2br(h($ticket['message'])) ?></div>

                        <div class="crf-field-label mt-3">Lampiran</div>
                        <?php if (!$attachments): ?>
                            <div class="text-muted small">Tidak ada lampiran.</div>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($attachments as $attachment): ?>
                                    <li>
                                        <a href="../actions/download_helpdesk_attachment.php?id=<?= (int) $attachment['id'] ?>" class="crf-link">
                                            <i class="bi bi-paperclip"></i> <?= h($attachment['original_name']) ?>
                                        </a>
                                        <small class="text-muted">(<?= number_format(((int) $attachment['file_size']) / 1024, 0, ',', '.') ?> KB)</small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if (!helpdeskTicketViaCrf($ticket)): ?>
                    <section class="crf-section">
                        <div class="crf-section-header">
                            <span class="crf-section-number">3</span>
                            <h2>Tindak Lanjut</h2>
                        </div>
                        <div class="crf-section-body">
                            <?php if ($ticket['follow_up']): ?>
                                <div class="crf-detail-text"><?= nl2br(h($ticket['follow_up'])) ?></div>
                                <div class="small text-muted mt-2">
                                    oleh <?= h($ticket['handled_by_name'] ?: '-') ?>
                                    <?= $ticket['handled_at'] ? ' · ' . h(date('d-m-Y H:i', strtotime($ticket['handled_at']))) : '' ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted fst-italic">Belum ada tindak lanjut dari PIC.</div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="crf-section crf-detail-timeline">
                    <div class="crf-section-header">
                        <span class="crf-section-number"><i class="bi bi-clock-history"></i></span>
                        <h2>Riwayat Ticket</h2>
                    </div>
                    <div class="crf-section-body">
                        <?php if (!$timeline): ?>
                            <div class="text-muted">Belum ada riwayat.</div>
                        <?php else: ?>
                            <ol class="crf-timeline">
                                <?php foreach ($timeline as $item): ?>
                                    <li class="crf-timeline-item <?= $item['new_status'] === 'Dibatalkan' ? 'is-cancelled' : 'is-complete' ?>">
                                        <span class="crf-timeline-dot" aria-hidden="true"><i class="bi bi-check"></i></span>
                                        <div class="crf-timeline-content">
                                            <strong class="crf-timeline-title"><?= h($item['activity']) ?></strong>
                                            <time class="crf-timeline-date"><?= h(date('d M Y, H:i', strtotime($item['created_at']))) ?></time>
                                            <?php if ($item['description']): ?>
                                                <div class="crf-timeline-description"><?= nl2br(h($item['description'])) ?></div>
                                            <?php endif; ?>
                                            <?php if ($item['new_status'] && $item['old_status'] !== $item['new_status']): ?>
                                                <div class="crf-timeline-status">
                                                    <?php if ($item['old_status']): ?><span><?= h($item['old_status']) ?></span> <i class="bi bi-arrow-right"></i><?php endif; ?>
                                                    <strong><?= h($item['new_status']) ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($item['actor']): ?>
                                                <div class="crf-timeline-actor">Dilakukan oleh <?= h($item['actor']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="crf-section">
                    <div class="crf-section-header">
                        <span class="crf-section-number"><i class="bi bi-link-45deg"></i></span>
                        <h2>CRF Terkait</h2>
                    </div>
                    <div class="crf-section-body">
                        <?php if (!$linkedCrfs): ?>
                            <div class="text-muted small">Ticket ini tidak memerlukan CRF.</div>
                        <?php endif; ?>
                        <?php foreach ($linkedCrfs as $linkedCrf): ?>
                            <?php $linkedStatus = crfDisplayStatus($linkedCrf); ?>
                            <a class="crf-linked-crf" href="../crf/open.php?id=<?= (int) $linkedCrf['id'] ?>">
                                <strong><?= h($linkedCrf['request_number'] ?: 'Draft CRF') ?></strong>
                                <span class="small text-muted"><?= h($linkedCrf['category_name'] ?? 'Kategori belum dipilih') ?></span>
                                <span class="crf-badge <?= h($linkedStatus['class']) ?>"><?= h($linkedStatus['label']) ?></span>
                                <?php if ($isOwner && $linkedCrf['status'] === 'Draft'): ?>
                                    <span class="small crf-link"><i class="bi bi-pencil"></i> Lengkapi Form CRF</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="crf-section">
                    <div class="crf-section-header">
                        <span class="crf-section-number"><i class="bi bi-stopwatch"></i></span>
                        <h2>SLA</h2>
                    </div>
                    <div class="crf-section-body">
                        <dl class="crf-detail-grid crf-detail-grid--single">
                            <div><dt>Target SLA</dt><dd><?= h($sla['target']) ?></dd></div>
                            <?php if (!empty($sla['via_crf'])): ?>
                                <div><dt>Keterangan</dt><dd class="text-muted">Ticket ini diteruskan ke CRF. Target, durasi, dan status SLA dinilai pada CRF terkait (dimulai setelah persetujuan Kepala Departemen Operasional).</dd></div>
                            <?php else: ?>
                                <div><dt>Durasi</dt><dd><?= h($sla['duration'] ?? '-') ?></dd></div>
                            <?php endif; ?>
                            <div><dt>Status</dt><dd><span class="badge text-bg-<?= h($sla['class']) ?>">
                                <?= $sla['label'] === 'Sesuai SLA' ? '✓ ' : ($sla['label'] === 'Melebihi SLA' ? '⚠ ' : '') ?><?= h($sla['label']) ?>
                            </span></dd></div>
                        </dl>
                        <?php if ($linkedCrfs): ?>
                            <div class="crf-readonly-note mt-2">SLA pengerjaan perubahan dipantau pada detail CRF.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<?php if ($canManage): ?>
    <?php $allowedStatuses = helpdeskStatusTransitions()[$ticket['status']] ?? []; ?>
    <div class="modal fade" id="followUpModal" tabindex="-1" aria-labelledby="followUpModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="../actions/helpdesk_follow_up.php" data-confirm="Simpan tindak lanjut ticket <?= h(helpdeskTicketLabel($ticket)) ?>?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="followUpModalTitle">Tindak Lanjut · <?= h(helpdeskTicketLabel($ticket)) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="crf-field-label" for="fuStatus">Status<span class="text-danger">*</span></label>
                            <select class="form-select" id="fuStatus" name="status" required>
                                <?php foreach ($allowedStatuses as $statusOption): ?>
                                    <option value="<?= h($statusOption) ?>" <?= $statusOption === $ticket['status'] ? 'selected' : '' ?>><?= h($statusOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="crf-field-label" for="fuLevel">Level</label>
                            <select class="form-select" id="fuLevel" name="level">
                                <option value="">—</option>
                                <?php foreach (['Tinggi', 'Sedang', 'Rendah'] as $levelOption): ?>
                                    <option value="<?= h($levelOption) ?>" <?= $ticket['level'] === $levelOption ? 'selected' : '' ?>><?= h($levelOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <label class="crf-field-label" for="fuNote">Catatan Tindak Lanjut<span class="text-danger">*</span></label>
                    <textarea class="form-control" id="fuNote" name="follow_up" rows="4" maxlength="5000" required><?= h($ticket['follow_up'] ?? '') ?></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-crf-outline" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-crf-primary"><i class="bi bi-save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>
