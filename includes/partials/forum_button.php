<?php
/**
 * Partial: tombol "Diskusi Forum" di halaman detail CRF.
 * Tampil hanya untuk peran Forum dan CRF yang masih aktif (aturan yang sama dengan
 * ruang Forum, lihat canViewForumCrf()). Menampilkan jumlah komentar dan yang belum dibaca.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil: PDO $pdo, array $crf.
 */
require_once __DIR__ . '/../forum.php';

$forumButtonCrfId = (int) ($crf['id'] ?? 0);
if ($forumButtonCrfId > 0 && canViewForumCrf($pdo, $forumButtonCrfId)):
    $forumInfo = forumCrfSummary($pdo, $forumButtonCrfId, (int) ($_SESSION['user_id'] ?? 0));
    $forumTitle = $forumInfo['comments'] . ' komentar'
        . ($forumInfo['unread'] > 0 ? ', ' . $forumInfo['unread'] . ' belum dibaca' : '')
        . ($forumInfo['open'] ? '. Pembahasan Level/SLA sedang terbuka' : '');
    ?>
    <a
        href="../forum/index.php?crf_id=<?= $forumButtonCrfId ?><?= $forumInfo['open'] ? '#forum-pembahasan' : '' ?>"
        class="btn btn-crf-outline crf-forum-button"
        title="<?= h($forumTitle) ?>"
    >
        <i class="bi bi-chat-square-text" aria-hidden="true"></i>
        Diskusi Forum
        <?php if ($forumInfo['comments'] > 0): ?>
            <span class="crf-forum-button-count"><?= (int) $forumInfo['comments'] ?></span>
        <?php endif; ?>
        <?php if ($forumInfo['unread'] > 0): ?>
            <span class="crf-forum-button-new"><?= (int) $forumInfo['unread'] ?> baru</span>
        <?php endif; ?>
        <?php if ($forumInfo['open']): ?>
            <i class="bi bi-flag-fill crf-forum-button-flag" aria-label="Pembahasan terbuka"></i>
        <?php endif; ?>
    </a>
<?php endif; ?>
<?php unset($forumButtonCrfId, $forumInfo, $forumTitle); ?>
