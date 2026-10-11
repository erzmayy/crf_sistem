<?php
/**
 * database/maintenance/hitung_tenggat_sla.php
 * ---------------------------------------------------------------
 * Hitung tenggat SLA (sla_tenggat) untuk CRF yang SLA-nya sudah dimulai tetapi
 * tenggatnya masih kosong, misalnya CRF yang sempat "antrean" dan dimulai oleh
 * migrasi 018. Tenggat dihitung dari sla_mulai dengan aturan hari kerja
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
    SELECT id, nomor_register, sla_nilai, sla_satuan, sla_mulai
    FROM crf_pengajuan
    WHERE sla_mulai IS NOT NULL
      AND sla_tenggat IS NULL
      AND sla_nilai IS NOT NULL
      AND status = 'Dalam Proses'
")->fetchAll();

$update = $pdo->prepare('UPDATE crf_pengajuan SET sla_tenggat = :due WHERE id = :id');
$done = 0;
foreach ($rows as $row) {
    $due = slaDueAt($row['sla_mulai'], $row['sla_nilai'], $row['sla_satuan']);
    if ($due === null) {
        echo "Dilewati (SLA tidak valid): " . ($row['nomor_register'] ?: '#' . $row['id']) . "\n";
        continue;
    }
    $update->execute(['due' => $due, 'id' => (int) $row['id']]);
    echo ($row['nomor_register'] ?: '#' . $row['id']) . ' -> tenggat ' . $due . "\n";
    $done++;
}

echo $done . " CRF diperbarui.\n";
