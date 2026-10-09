<?php
/**
 * Daftar PIC CRF sebuah kategori + form tambah (pencarian user SIAP).
 *
 * Variabel: $memberCategory (array), $memberList (array),
 *           $memberReturn (nama halaman admin), $memberLabel ('PIC CRF').
 */
?>
<div class="crf-member-grid">
    <?php if (!$memberList): ?>
        <div class="crf-member-empty">
            <i class="bi bi-person-dash"></i>
            Belum ada <?= h($memberLabel) ?>. Selama kosong, CRF kategori ini ditangani tim Otomasi.
        </div>
    <?php endif; ?>

    <?php foreach ($memberList as $index => $member): ?>
        <div class="crf-member-card">
            <span class="crf-member-index"><?= $index + 1 ?></span>
            <div class="crf-member-info">
                <strong><?= h($member['user_name'] ?? '-') ?></strong>
                <?php if (!empty($member['no_wa'])): ?>
                    <small><i class="bi bi-telephone"></i> <?= h($member['no_wa']) ?></small>
                <?php endif; ?>
                <?php if (!empty($member['email'])): ?>
                    <small><i class="bi bi-envelope"></i> <?= h($member['email']) ?></small>
                <?php endif; ?>
            </div>
            <form method="POST" action="../actions/category_master.php"
                  data-confirm="Hapus <?= h($member['user_name'] ?? '') ?> dari <?= h($memberLabel) ?> kategori <?= h($memberCategory['name']) ?>?">
                <?= csrfField() ?>
                <input type="hidden" name="op" value="remove_member">
                <input type="hidden" name="return" value="<?= h($memberReturn) ?>">
                <input type="hidden" name="id" value="<?= (int) $memberCategory['id'] ?>">
                <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                <button type="submit" class="crf-member-remove" aria-label="Hapus <?= h($memberLabel) ?>">
                    <i class="bi bi-x-lg"></i>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<form method="POST" action="../actions/category_master.php" class="crf-member-add" data-user-picker>
    <?= csrfField() ?>
    <input type="hidden" name="op" value="add_member">
    <input type="hidden" name="return" value="<?= h($memberReturn) ?>">
    <input type="hidden" name="id" value="<?= (int) $memberCategory['id'] ?>">
    <input type="hidden" name="user_id" value="" data-user-picker-id>
    <div class="crf-member-search">
        <i class="bi bi-search"></i>
        <input
            type="search"
            class="form-control form-control-sm"
            placeholder="Cari nama / user ID untuk menambah <?= h($memberLabel) ?>..."
            autocomplete="off"
            data-user-picker-input
            aria-label="Cari user untuk ditambahkan sebagai <?= h($memberLabel) ?>"
        >
        <div class="crf-member-results d-none" data-user-picker-results role="listbox"></div>
    </div>
    <button type="submit" class="btn btn-sm btn-crf-primary" data-user-picker-submit disabled>
        <i class="bi bi-person-plus"></i> Tambah
    </button>
</form>
