<?php
/**
 * Halaman lama: master data kini digabung di admin/master_data.php.
 * Dipertahankan agar tautan/bookmark lama tetap berfungsi.
 */
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();

header('Location: master_data.php?tab=helpdesk');
exit;