<?php
/**
 * database/maintenance/hitung_tenggat_sla.php
 * ---------------------------------------------------------------
 * Hitung tenggat SLA (sla_due_at) untuk CRF yang SLA-nya sudah dimulai tetapi
 * tenggatnya masih kosong, misalnya CRF yang sempat "antrean" dan dimulai oleh
 * migrasi 018. Tenggat dihitung dari sla_started_at dengan aturan hari kerja
 * yang sama dengan aplikasi (config/sla.php). Aman dijalankan ulang.
 *
 * Jalankan dari terminal (bukan dari browser):
 *   php database/maintenance/hitung_tenggat_sla.php
 * ---------------------------------------------------------------
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari terminal.');
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$pdo = getConnection();
$rows = $pdo->query("
    SELECT id, request_number, sla_value, sla_unit, sla_started_at
    FROM change_requests
    WHERE sla_started_at IS NOT NULL
      AND sla_due_at IS NULL
      AND sla_value IS NOT NULL
      AND status = 'Dalam Proses'
")->fetchAll();

$update = $pdo->prepare('UPDATE change_requests SET sla_due_at = :due WHERE id = :id');
$done = 0;
foreach ($rows as $row) {
    $due = slaDueAt($row['sla_started_at'], $row['sla_value'], $row['sla_unit']);
    if ($due === null) {
        echo "Dilewati (SLA tidak valid): " . ($row['request_number'] ?: '#' . $row['id']) . "\n";
        continue;
    }
    $update->execute(['due' => $due, 'id' => (int) $row['id']]);
    echo ($row['request_number'] ?: '#' . $row['id']) . ' -> tenggat ' . $due . "\n";
    $done++;
}

echo $done . " CRF diperbarui.\n";
