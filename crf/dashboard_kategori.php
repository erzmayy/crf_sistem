<?php
/**
 * Halaman lama: Dashboard Kategori CRF kini digabung di
 * helpdesk/handling.php (tab CRF).
 * Dipertahankan agar tautan/bookmark lama tetap berfungsi.
 */
require_once __DIR__ . '/../includes/auth.php';

requireCrfRole(['cmo', 'otomasi', 'kadep_operasional']);

header('Location: ../helpdesk/handling.php?tab=crf');
exit;
