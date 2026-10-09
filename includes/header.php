<?php
/**
 * Shared header/layout for CRF.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/forum.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/crf_nav.php';
require_once __DIR__ . '/../config/sla.php';

if (!isset($pageTitle)) {
    $pageTitle = 'CRF';
}

$currentUser = getCurrentUser();
$crfRole = getCrfRole();
$isAdminUser = isAdmin();          // admin atau akun demo
$isDemoUser = $crfRole === 'demo'; // akun demo: semua peran
$canRole = static fn(string $role): bool => $isDemoUser || $crfRole === $role;
$currentPath = basename($_SERVER['PHP_SELF'] ?? '');
$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$currentFolder = basename(dirname($scriptPath));

$crfEmbedded = crfLayoutIsModule();

$navUserId = (int) ($currentUser['id'] ?? 0);
$notificationUnread = unreadNotificationCount(getConnection(), $navUserId);
$notificationItems = $crfEmbedded ? [] : recentNotifications(getConnection(), $navUserId, 6);

$appBasePath = preg_replace('#/(?:admin|user|cmo|otomasi|pak_joko|forum|helpdesk|crf|notifications)/[^/]+$#', '', $scriptPath) ?: '';
$appBasePath = rtrim($appBasePath, '/');
// Di dalam SIAP, awalan URL modul diatur dari config/siap.php.
if (defined('CRF_BASE_URL') && CRF_BASE_URL !== '') {
    $appBasePath = rtrim((string) CRF_BASE_URL, '/');
}

$homePath = $isAdminUser
    ? '/admin/dashboard.php'
    : '/index.php';

$styleUrl = $appBasePath . '/assets/css/style.css?v=' . (int) filemtime(__DIR__ . '/../assets/css/style.css');

/*
 * Mode "module": SIAP yang memegang <html>, <head>, header, sidebar, dan
 * menu. CRF hanya mengeluarkan area kontennya (dibungkus .crf-module).
 * Penutupnya ada di includes/footer.php.
 */
if ($crfEmbedded) {
    if (defined('CRF_LOAD_BOOTSTRAP') && CRF_LOAD_BOOTSTRAP) {
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n";
    }
    if (!defined('CRF_LOAD_ICONS') || CRF_LOAD_ICONS) {
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">' . "\n";
    }
    ?>
<link href="<?= h($styleUrl) ?>" rel="stylesheet">
<div class="crf-module crf-module--embedded" data-app-base="<?= h($appBasePath) ?>" data-sla-holidays="<?= h(json_encode(CRF_SLA_HOLIDAYS)) ?>">
<div class="crf-content-shell">
<?php
    return;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> · Helpdesk & CRF PPU</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= h($styleUrl) ?>" rel="stylesheet">
</head>
<body class="crf-module" data-app-base="<?= h($appBasePath) ?>" data-sla-holidays="<?= h(json_encode(CRF_SLA_HOLIDAYS)) ?>">
<div class="crf-app-shell">
    <div class="crf-sidebar-overlay"></div>

  <?php
  /* Menu sebagai data: lihat includes/crf_nav.php (juga dipakai SIAP pada mode module). */
  $navGroups = crfNavGroups();
  ?>
  <aside class="crf-sidebar siap-sidebar">
    <a class="crf-sidebar-brand" href="<?= h($appBasePath . $homePath) ?>">
      <span class="crf-sidebar-mark crf-sidebar-logo"><img src="<?= h($appBasePath) ?>/assets/img/logo-header.png" alt="Logo PT Persona Prima Utama"></span>
      <span class="ppu-brand-text">Home / Dashboard</span>
    </a>

    <div class="siap-menu-title">Menu Saya</div>
    <ul class="siap-menu" aria-label="Menu Saya">
      <?php foreach ($navGroups as $groupIndex => $navGroup): ?>
        <?php
        $visibleItems = array_values(array_filter($navGroup['items'], static fn($item) => $item['show']));
        if (!$visibleItems) {
            continue;
        }
        $groupActive = (bool) array_filter($visibleItems, static fn($item) => $item['active']);
        $groupBadge = array_sum(array_map(static fn($item) => (int) ($item['badge'] ?? 0), $visibleItems));
        ?>
        <li class="siap-menu-item <?= $groupActive ? 'is-active is-open' : '' ?>">
          <button type="button" class="siap-menu-link" aria-expanded="<?= $groupActive ? 'true' : 'false' ?>" aria-controls="siap-submenu-<?= $groupIndex ?>">
            <span><?= h($navGroup['label']) ?></span>
            <?php if ($groupBadge > 0): ?>
              <span class="crf-nav-unread"><?= $groupBadge > 99 ? '99+' : $groupBadge ?></span>
            <?php endif; ?>
            <i class="bi bi-caret-right-fill siap-menu-caret" aria-hidden="true"></i>
          </button>
          <ul class="siap-submenu" id="siap-submenu-<?= $groupIndex ?>">
            <?php foreach ($visibleItems as $navItem): ?>
              <li>
                <a class="<?= $navItem['active'] ? 'active' : '' ?>" href="<?= h($appBasePath . $navItem['url']) ?>">
                  <?= h($navItem['label']) ?>
                  <?php if (!empty($navItem['badge'])): ?>
                    <span class="crf-nav-unread" title="<?= h($navItem['badge_label'] ?? ((int) $navItem['badge'] . ' baru')) ?>" aria-label="<?= h($navItem['badge_label'] ?? ((int) $navItem['badge'] . ' baru')) ?>"><?= $navItem['badge'] > 99 ? '99+' : (int) $navItem['badge'] ?></span>
                  <?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>
      <?php endforeach; ?>
    </ul>

    <nav class="siap-menu-main" aria-label="Menu utama">
      <a class="<?= $currentFolder === 'notifications' ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/notifications/index.php">
        Notifikasi
        <?php if ($notificationUnread > 0): ?>
          <span class="crf-nav-unread"><?= $notificationUnread > 99 ? '99+' : $notificationUnread ?></span>
        <?php endif; ?>
      </a>
    </nav>
  </aside>

  <div class="crf-content-shell">
    <header class="crf-topbar">
      <button type="button" class="crf-sidebar-toggle" aria-label="Buka menu">
        <i class="bi bi-list"></i>
    </button>
      <div class="crf-topbar-right">
        <div class="dropdown crf-notification">
          <button type="button" class="crf-notification-bell" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifikasi<?= $notificationUnread > 0 ? ' (' . $notificationUnread . ' belum dibaca)' : '' ?>">
            <i class="bi bi-bell"></i>
            <?php if ($notificationUnread > 0): ?>
              <span class="crf-notification-count"><?= $notificationUnread > 99 ? '99+' : $notificationUnread ?></span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end crf-notification-menu">
            <div class="crf-notification-head">
              <strong>Notifikasi</strong>
              <a href="<?= h($appBasePath) ?>/notifications/index.php">Lihat semua</a>
            </div>
            <?php if (!$notificationItems): ?>
              <div class="crf-notification-empty"><i class="bi bi-bell-slash"></i> Belum ada notifikasi.</div>
            <?php endif; ?>
            <?php foreach ($notificationItems as $notificationItem): ?>
              <a class="crf-notification-item <?= $notificationItem['read_at'] === null ? 'unread' : '' ?>" href="<?= h($appBasePath) ?>/notifications/open.php?id=<?= (int) $notificationItem['id'] ?>">
                <strong><?= h($notificationItem['title']) ?></strong>
                <span><?= h(mb_strimwidth((string) $notificationItem['message'], 0, 110, '…')) ?></span>
                <small><?= h(date('d M Y H:i', strtotime($notificationItem['created_at']))) ?></small>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($isDemoUser): ?>
          <span class="crf-demo-badge" title="Akun demo presentasi: dapat menjalankan semua peran. Nonaktifkan lewat CRF_DEMO_MODE di config/siap.php.">
            <i class="bi bi-easel"></i> Mode Demo
          </span>
        <?php elseif ($isAdminUser): ?>
          <span class="crf-demo-badge is-admin" title="<?= h($crfRole === 'admin' ? 'Admin: pengelola & pemantau sistem' : 'Hak Admin tambahan di samping peran ' . crfRoleLabel($crfRole)) ?>">
            <i class="bi bi-shield-check"></i> Admin
          </span>
        <?php endif; ?>
        <div class="dropdown crf-user-menu">
          <button type="button" class="crf-user" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu akun">
            <strong><?= h($currentUser['nama'] ?? '-') ?></strong>
            <span class="crf-avatar">
              <?= h(strtoupper(substr($currentUser['nama'] ?? 'U', 0, 2))) ?>
            </span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="dropdown-header"><?= h($currentUser['nama'] ?? '-') ?></li>
            <li><a class="dropdown-item" href="<?= h($appBasePath) ?>/actions/logout.php"><i class="bi bi-box-arrow-right"></i> Keluar</a></li>
          </ul>
        </div>
      </div>
    </header>
