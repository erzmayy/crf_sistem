<?php
/**
 * Kartu penanganan CRF: kategori, handler yang memegang, tombol
 * "Ambil CRF" dan (Admin) tugaskan ke handler lain.
 *
 * Variabel: $crf, $pdo, opsional $categoryHandlers (array anggota kategori).
 */
$assignmentCategory = !empty($crf['crf_category_id']) ? findCrfCategory($pdo, (int) $crf['crf_category_id']) : null;
$canTakeCrf = $crf['workflow_stage'] === 'OTOMASI'
    && empty($crf['assigned_handler_id'])
    && canHandleCrf($pdo, $crf);
$categoryHandlers = $categoryHandlers ?? [];
?>
<div class="crf-assignment-card">
    <div class="crf-assignment-item">
        <span class="crf-request-caption">Kategori CRF</span>
        <strong><?= h($assignmentCategory['name'] ?? ($crf['change_category'] ?? '-')) ?></strong>
        <?php if ($assignmentCategory && $assignmentCategory['sla_value'] !== null): ?>
            <small class="text-muted">Target SLA kategori <?= h(slaLabel($assignmentCategory['sla_value'], $assignmentCategory['sla_unit'])) ?></small>
        <?php endif; ?>
    </div>
    <div class="crf-assignment-item">
        <span class="crf-request-caption">Handler</span>
        <?php if (!empty($crf['assigned_handler_id'])): ?>
            <strong><i class="bi bi-person-check"></i> <?= h($crf['assigned_handler_name'] ?? '-') ?></strong>
            <?php if (!empty($crf['assigned_at'])): ?>
                <small class="text-muted">sejak <?= h(date('d-m-Y H:i', strtotime($crf['assigned_at']))) ?></small>
            <?php endif; ?>
        <?php else: ?>
            <strong class="text-muted"><i class="bi bi-person-dash"></i> Belum ada handler</strong>
        <?php endif; ?>
    </div>
    <div class="crf-assignment-actions">
        <?php if ($canTakeCrf): ?>
            <form method="POST" action="../actions/crf_assign.php" data-confirm="Ambil CRF <?= h($crf['request_number'] ?? '') ?> untuk Anda tangani?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">
                <input type="hidden" name="op" value="take">
                <button type="submit" class="btn btn-crf-primary btn-sm"><i class="bi bi-hand-index-thumb"></i> Ambil CRF</button>
            </form>
        <?php endif; ?>
        <?php if (isAdmin() && $crf['workflow_stage'] === 'OTOMASI' && $categoryHandlers): ?>
            <form method="POST" action="../actions/crf_assign.php" class="d-flex gap-1" data-confirm="Tugaskan CRF ini ke handler terpilih?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $crf['id'] ?>">
                <input type="hidden" name="op" value="assign">
                <select name="user_id" class="form-select form-select-sm" required aria-label="Pilih handler">
                    <option value="">Tugaskan ke…</option>
                    <?php foreach ($categoryHandlers as $handler): ?>
                        <option value="<?= (int) $handler['user_id'] ?>" <?= (int) ($crf['assigned_handler_id'] ?? 0) === (int) $handler['user_id'] ? 'selected' : '' ?>><?= h($handler['user_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-crf-outline btn-sm">Tugaskan</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php
unset($assignmentCategory, $canTakeCrf);
