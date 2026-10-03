<?php
/**
 * Shared header/layout for CRF.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/forum.php';
require_once __DIR__ . '/notifications.php';

if (!isset($pageTitle)) {
    $pageTitle = 'CRF';
}

$currentUser = getCurrentUser();
$crfRole = getCrfRole();
$isAdminUser = $crfRole === 'admin';
$currentPath = basename($_SERVER['PHP_SELF'] ?? '');
$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$currentFolder = basename(dirname($scriptPath));

$isNav = static function (string $folder, ?string $file = null) use ($currentFolder, $currentPath): bool {
    return $currentFolder === $folder && ($file === null || $currentPath === $file);
};

$isForum = $currentFolder === 'forum';
$forumUnreadTotal = in_array($crfRole, forumRoles(), true)
    ? forumUnreadTotal(getConnection(), (int) ($currentUser['id'] ?? 0))
    : 0;

$navUserId = (int) ($currentUser['id'] ?? 0);
$isPicUser = $isAdminUser || isHelpdeskPic(getConnection(), $navUserId);
$notificationUnread = unreadNotificationCount(getConnection(), $navUserId);
$notificationItems = recentNotifications(getConnection(), $navUserId, 6);

$appBasePath = preg_replace('#/(?:admin|user|cmo|otomasi|pak_joko|forum|helpdesk|crf|notifications)/[^/]+$#', '', $scriptPath) ?: '';
$appBasePath = rtrim($appBasePath, '/');

$homePath = $isAdminUser
    ? '/admin/dashboard.php'
    : '/index.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> · Helpdesk & CRF PPU</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= h($appBasePath) ?>/assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body data-app-base="<?= h($appBasePath) ?>">
<div class="crf-app-shell">
    <div class="crf-sidebar-overlay"></div>

  <?php
  /*
   * Menu sidebar gaya SIAP: grup "Menu Saya" dengan submenu melayang
   * (flyout) dan link utama tebal di bagian bawah. Item difilter per role.
   */
  $navGroups = [
      [
          'label' => 'Dashboard',
          'icon' => 'bi-grid',
          'items' => [
              // Satu halaman master Kategori & Handling (tab Helpdesk / CRF).
              ['label' => 'Kategori & Handling', 'url' => '/admin/master_data.php', 'show' => $isAdminUser, 'active' => $isNav('admin', 'master_data.php')],
              // Satu dashboard kategori untuk Helpdesk & CRF (tab per jenis).
              ['label' => 'Dashboard Handling Kategori', 'url' => '/helpdesk/handling.php', 'show' => $isPicUser || in_array($crfRole, ['cmo', 'otomasi', 'kadep_operasional'], true), 'active' => $isNav('helpdesk', 'handling.php') || $isNav('helpdesk', 'kategori.php')],
          ],
      ],
      [
          'label' => 'Help Desk',
          'icon' => 'bi-headset',
          'items' => [
              ['label' => 'Dashboard Help Desk', 'url' => '/helpdesk/dashboard.php', 'show' => $isPicUser, 'active' => $isNav('helpdesk', 'dashboard.php')],
              ['label' => 'Formulir Help Desk', 'url' => '/helpdesk/form.php', 'show' => true, 'active' => $isNav('helpdesk', 'form.php')],
              ['label' => 'Tiket Saya', 'url' => '/helpdesk/saya.php', 'show' => true, 'active' => $isNav('helpdesk', 'saya.php') || $isNav('helpdesk', 'detail.php')],
          ],
      ],
      [
          'label' => 'Change Request (CRF)',
          'icon' => 'bi-file-earmark-diff',
          'items' => [
              ['label' => 'Dashboard CRF', 'url' => '/admin/dashboard.php', 'show' => $isAdminUser, 'active' => $isNav('admin', 'dashboard.php') || $isNav('admin', 'detail.php') || $isNav('admin', 'edit.php')],
              ['label' => 'Review CMO', 'url' => '/cmo/index.php', 'show' => in_array($crfRole, ['admin', 'cmo'], true), 'active' => $currentFolder === 'cmo'],
              ['label' => 'Handler CRF', 'url' => '/otomasi/index.php', 'show' => in_array($crfRole, ['admin', 'otomasi'], true), 'active' => $currentFolder === 'otomasi'],
              ['label' => 'Approval Kadep Operasional', 'url' => '/pak_joko/index.php', 'show' => in_array($crfRole, ['admin', 'kadep_operasional'], true), 'active' => $currentFolder === 'pak_joko'],
              ['label' => 'Form CRF', 'url' => '/user/form_crf.php', 'show' => true, 'active' => $isNav('user', 'form_crf.php')],
              ['label' => 'Pengajuan CRF Saya', 'url' => '/user/pengajuan_saya.php', 'show' => !$isAdminUser, 'active' => $isNav('user', 'pengajuan_saya.php') || $isNav('user', 'detail.php')],
              ['label' => 'Forum', 'url' => '/forum/index.php', 'show' => in_array($crfRole, forumRoles(), true), 'active' => $isForum, 'badge' => $forumUnreadTotal],
          ],
      ],
  ];
  ?>
  <aside class="crf-sidebar siap-sidebar">
    <a class="crf-sidebar-brand" href="<?= h($appBasePath . $homePath) ?>">
      <span class="crf-sidebar-mark"><i class="bi bi-journal-bookmark-fill"></i></span>
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
                    <span class="crf-nav-unread" aria-label="<?= (int) $navItem['badge'] ?> baru"><?= $navItem['badge'] > 99 ? '99+' : (int) $navItem['badge'] ?></span>
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
      <a href="<?= h($appBasePath) ?>/actions/logout.php">Keluar</a>
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
        <div class="crf-user">
          <strong><?= h($currentUser['nama'] ?? '-') ?></strong>
          <span class="crf-avatar">
            <?= h(strtoupper(substr($currentUser['nama'] ?? 'U', 0, 2))) ?>
          </span>
        </div>
      </div>
    </header>
