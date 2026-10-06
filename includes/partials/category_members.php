<?php
/**
 * Daftar PIC sebuah kategori + form tambah (pencarian user SIAP).
 *
 * Variabel: $memberType ('crf'|'helpdesk'), $memberCategory (array),
 *           $memberList (array), $memberReturn (nama halaman admin),
 *           $memberLabel ('PIC CRF'|'PIC').
 *
 * PIC CRF = PIC kategori Helpdesk bertanda "Butuh CRF" dengan kategori CRF ini
 * (migrasi 017), jadi tidak ditambah di sini. Isian manual lama masih bisa dihapus.
 */
$isCrfMember = $memberType === 'crf';
?>
<div class="crf-member-grid">
    <?php if (!$memberList): ?>
        <div class="crf-member-empty">
            <i class="bi bi-person-dash"></i>
            Belum ada <?= h($memberLabel) ?>.
            <?php if ($isCrfMember): ?>
                Tandai kategori Helpdesk sebagai <em>Butuh CRF</em> dengan kategori CRF ini. Selama kosong, CRF kategori ini ditangani tim Otomasi.
            <?php endif; ?>
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
                <?php if ($isCrfMember): ?>
                    <span class="crf-member-source">
                        <?php if (!empty($member['sources'])): ?>
                            <i class="bi bi-headset"></i> PIC <?= h($member['sources']) ?>
                        <?php endif; ?>
                        <?php if (!empty($member['is_manual'])): ?>
                            <span class="crf-member-manual" title="Diisi manual sebelum PIC CRF diambil dari kategori Helpdesk">isian manual lama</span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if (!$isCrfMember || !empty($member['is_manual'])): ?>
                <form method="POST" action="../actions/category_master.php"
                      data-confirm="<?= $isCrfMember
                          ? 'Hapus isian manual ' . h($member['user_name'] ?? '') . '?' . (!empty($member['sources']) ? ' Ia tetap PIC CRF karena PIC ' . h($member['sources']) . '.' : '')
                          : 'Hapus ' . h($member['user_name'] ?? '') . ' dari ' . h($memberLabel) . ' kategori ' . h($memberCategory['name']) . '?' ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="type" value="<?= h($memberType) ?>">
                    <input type="hidden" name="op" value="remove_member">
                    <input type="hidden" name="return" value="<?= h($memberReturn) ?>">
                    <input type="hidden" name="id" value="<?= (int) $memberCategory['id'] ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                    <button type="submit" class="crf-member-remove" aria-label="Hapus <?= $isCrfMember ? 'isian manual' : h($memberLabel) ?>">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($isCrfMember): ?>
    <p class="crf-member-hint">
        <i class="bi bi-info-circle"></i>
        PIC CRF mengikuti PIC kategori Helpdesk bertanda <em>Butuh CRF · <?= h($memberCategory['name']) ?></em>.
        Untuk menambah atau mengganti PIC CRF, ubah PIC di tab <a href="?tab=helpdesk">Kategori Helpdesk</a>.
    </p>
<?php else: ?>
    <form method="POST" action="../actions/category_master.php" class="crf-member-add" data-user-picker>
        <?= csrfField() ?>
        <input type="hidden" name="type" value="<?= h($memberType) ?>">
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
<?php endif; ?>
<?php
unset($isCrfMember);
