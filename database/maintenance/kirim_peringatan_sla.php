<?php
/**
 * database/maintenance/kirim_peringatan_sla.php
 * ---------------------------------------------------------------
 * Kirim peringatan SLA ke PIC CRF (pengingat 50%, mendesak, terlambat).
 * Jalankan berkala, disarankan tiap 5 menit, lewat Windows Task Scheduler
 * atau cron. Aman dijalankan berulang (anti kirim ganda lewat crf_sla_alerts).
 *
 *   php database/maintenance/kirim_peringatan_sla.php
 *
 * Perlu migrasi 019_peringatan_sla_migration.sql.
 * ---------------------------------------------------------------
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari terminal.');
}

require_once __DIR__ . '/../../includes/sla_alerts.php';

$sent = crfSendSlaAlerts(getConnection());

echo date('Y-m-d H:i:s') . ' - ' . $sent . " peringatan SLA dikirim.\n";
