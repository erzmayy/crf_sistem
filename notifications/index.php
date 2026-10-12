<?php
/**
 * notifications/index.php
 * Daftar notifikasi user + tandai semua dibaca.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$pdo = getConnection();
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pdo->prepare('UPDATE crf_notifikasi SET tgl_dibaca = NOW() WHERE pengguna_id = :pengguna_id AND tgl_dibaca IS NULL')
        ->execute(['pengguna_id' => $userId]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Semua notifikasi ditandai sudah dibaca.'];
    header('Location: index.php');
    exit;
}

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM crf_notifikasi WHERE pengguna_id = :pengguna_id');
$countStmt->execute(['pengguna_id' => $userId]);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT * FROM crf_notifikasi
    WHERE pengguna_id = :pengguna_id
    ORDER BY id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute(['pengguna_id' => $userId]);
$items = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Notifikasi';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="crf-page crf-portal-page">
    <div class="container">
        <div class="crf-portal-banner crf-banner-with-actions">
            <div>
                <span class="crf-portal-eyebrow">CRF</span>
                <h1>Notifikasi</h1>
                <p>Pemberitahuan terkait CRF Anda.</p>
            </div>
            <?php if ($notificationUnread > 0): ?>
                <form method="POST" class="crf-banner-actions">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-light"><i class="bi bi-check2-all"></i> Tandai semua dibaca</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> crf-alert"><?= h($flash['pesan']) ?></div>
        <?php endif; ?>

        <div class="crf-table-card">
            <?php if (!$items): ?>
                <div class="crf-empty-state">
                    <i class="bi bi-bell-slash"></i>
                    <p>Belum ada notifikasi.</p>
                </div>
            <?php else: ?>
                <div class="crf-notification-list">
                    <?php foreach ($items as $item): ?>
                        <a class="crf-notification-item <?= $item['tgl_dibaca'] === null ? 'unread' : '' ?>" href="open.php?id=<?= (int) $item['id'] ?>">
                            <strong><?= h($item['judul']) ?></strong>
                            <span><?= nl2br(h(getCrfRole() === 'pemohon' ? crfStripSlaText($item['pesan']) : (string) $item['pesan'])) ?></span>
                            <small><?= h(date('d M Y H:i', strtotime($item['tgl_dibuat']))) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="d-flex justify-content-end gap-1 mt-3" aria-label="Halaman notifikasi">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="?page=<?= $p ?>" class="btn btn-sm <?= $p === $page ? 'btn-crf-primary' : 'btn-crf-outline' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
