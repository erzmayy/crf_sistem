<?php
/**
 * Shared header/layout for CRF.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/forum.php';

if (!isset($pageTitle)) {
    $pageTitle = 'CRF';
}

$currentUser = getCurrentUser();
$crfRole = getCrfRole();
$isAdminUser = $crfRole === 'admin';
$currentPath = basename($_SERVER['PHP_SELF'] ?? '');

$isDashboard = $currentPath === 'dashboard.php';
$isForm = $currentPath === 'form_crf.php';
$isPengajuanSaya = $currentPath === 'pengajuan_saya.php';
$isCmo = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/cmo/');
$isOtomasi = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/otomasi/');
$isPakJoko = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/pak_joko/');
$isForum = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/forum/');
$forumUnreadTotal = in_array($crfRole, forumRoles(), true)
    ? forumUnreadTotal(getConnection(), (int) ($currentUser['id'] ?? 0))
    : 0;

$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$appBasePath = preg_replace('#/(?:admin|user|cmo|otomasi|pak_joko|forum)/[^/]+$#', '', $scriptPath) ?: '';
$appBasePath = rtrim($appBasePath, '/');

$homePath = $isAdminUser
    ? '/admin/dashboard.php'
    : '/user/pengajuan_saya.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> · CRF PPU</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= h($appBasePath) ?>/assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="crf-app-shell">
    <div class="crf-sidebar-overlay"></div>

  <aside class="crf-sidebar">
    <a class="crf-sidebar-brand" href="<?= h($appBasePath . $homePath) ?>">
      <span class="crf-sidebar-mark"><i class="bi bi-house-door-fill"></i></span>
      <span class="ppu-brand-text">CRF</span>
    </a>

    <div class="crf-sidebar-section">Menu Utama</div>
    <nav class="crf-sidebar-nav" aria-label="Navigasi utama">
      <?php if ($isAdminUser): ?>
        <a class="<?= $isDashboard ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/admin/dashboard.php">
          <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
        </a>
        <a class="<?= $isCmo ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/cmo/index.php">
          <i class="bi bi-funnel-fill"></i><span>CMO</span>
        </a>
        <a class="<?= $isOtomasi ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/otomasi/index.php">
          <i class="bi bi-gear-fill"></i><span>Otomasi</span>
        </a>
        <a class="<?= $isPakJoko ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/pak_joko/index.php">
          <i class="bi bi-check2-square"></i><span>Kepala Departemen Operasional</span>
        </a>
        <a class="<?= $isForm ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/user/form_crf.php">
          <i class="bi bi-file-earmark-plus"></i><span>Form CRF</span>
        </a>
      <?php else: ?>

        <?php if ($crfRole === 'cmo'): ?>
          <a class="<?= $isCmo ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/cmo/index.php">
            <i class="bi bi-funnel-fill"></i><span>CMO</span>
          </a>
        <?php elseif ($crfRole === 'otomasi'): ?>
          <a class="<?= $isOtomasi ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/otomasi/index.php">
            <i class="bi bi-gear-fill"></i><span>Otomasi</span>
          </a>
        <?php elseif ($crfRole === 'kadep_operasional'): ?>
          <a class="<?= $isPakJoko ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/pak_joko/index.php">
            <i class="bi bi-check2-square"></i><span>Kepala Departemen Operasional</span>
          </a>
        <?php endif; ?>

        <a class="<?= $isForm ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/user/form_crf.php">
          <i class="bi bi-file-earmark-plus"></i><span>Form CRF</span>
        </a>
        <a class="<?= $isPengajuanSaya ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/user/pengajuan_saya.php">
          <i class="bi bi-file-earmark-check"></i><span>Pengajuan Saya</span>
        </a>
      <?php endif; ?>
      <?php if (in_array($crfRole, forumRoles(), true)): ?>
        <a class="<?= $isForum ? 'active' : '' ?>" href="<?= h($appBasePath) ?>/forum/index.php">
          <i class="bi bi-chat-square-text"></i><span>Forum</span>
          <?php if ($forumUnreadTotal > 0): ?>
            <span class="crf-nav-unread" aria-label="<?= $forumUnreadTotal ?> komentar baru">
              <?= $forumUnreadTotal > 99 ? '99+' : $forumUnreadTotal ?>
            </span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
    </nav>

    <div class="crf-sidebar-footer">
      <a href="<?= h($appBasePath) ?>/actions/logout.php">
        <i class="bi bi-box-arrow-left"></i> Keluar
      </a>
    </div>
  </aside>

  <div class="crf-content-shell">
    <header class="crf-topbar">
      <button type="button" class="crf-sidebar-toggle" aria-label="Buka menu">
        <i class="bi bi-list"></i>
    </button>
      <div class="crf-user">
        <strong><?= h($currentUser['nama'] ?? '-') ?></strong>
        <span class="crf-avatar">
          <?= h(strtoupper(substr($currentUser['nama'] ?? 'U', 0, 2))) ?>
        </span>
      </div>
    </header>