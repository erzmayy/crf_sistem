<?php
/**
 * actions/sla_respon_target.php
 * ---------------------------------------------------------------
 * Simpan target Response Time per tahap (CMO verifikasi, Kadep, PIC mulai dikerjakan, CMO UAT,
 * CMO finalisasi) x level urgensi ke crf_sla_respon_target. Hanya Admin. Kolom kosong = tanpa target
 * (barisnya dihapus; CRF yang masuk tahap itu tetap dicatat tetapi tanpa batas).
 * Target PIC harus lebih kecil dari Resolution Time terkecil di semua kategori aktif.
 * Perubahan hanya berlaku untuk CRF yang masuk tahap itu sesudahnya.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$redirect = crfUrl('admin.kategori') . '#crf-target-respon';

function slaResponTargetFinish(string $type, string $message, string $redirect): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    crfRedirect($redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crfRedirect($redirect);
    exit;
}

verifyCsrf();

$pdo = getConnection();

if (!crfRespTableReady($pdo)) {
    slaResponTargetFinish('danger', 'Tabel target Response Time belum ada. Jalankan migrasi 028 terlebih dulu.', $redirect);
}

$stageKeys = ['CMO_VERIFIKASI', 'KADEP', 'PIC_MULAI', 'CMO_UAT', 'CMO_FINAL'];
$levels = ['Tinggi', 'Normal', 'Rendah'];
$units = ['Menit', 'Jam', 'Hari'];
$input = $_POST['target'] ?? [];
$stages = crfRespStages();

$errors = [];
$save = [];
$remove = [];

foreach ($stageKeys as $stage) {
    foreach ($levels as $level) {
        $raw = trim((string) ($input[$stage][$level]['nilai'] ?? ''));
        $unit = (string) ($input[$stage][$level]['satuan'] ?? '');
        $name = ($stages[$stage]['label'] ?? $stage) . ' ' . $level;

        if ($raw === '') {
            $remove[] = [$stage, $level];
            continue;
        }
        if (!is_numeric($raw) || (float) $raw <= 0) {
            $errors[] = 'Target ' . $name . ' harus angka lebih dari 0.';
            continue;
        }
        if (!in_array($unit, $units, true)) {
            $errors[] = 'Satuan target ' . $name . ' tidak valid.';
            continue;
        }
        $save[] = [$stage, $level, (float) $raw, $unit];
    }
}

// Urgensi yang lebih mendesak tidak boleh diberi waktu lebih longgar daripada yang kurang mendesak.
foreach ($stageKeys as $stage) {
    $seconds = [];
    foreach ($save as [$s, $level, $value, $unit]) {
        if ($s === $stage) {
            $seconds[$level] = crfSlaSeconds($value, $unit);
        }
    }
    if (isset($seconds['Tinggi'], $seconds['Normal']) && $seconds['Tinggi'] > $seconds['Normal']) {
        $errors[] = 'Target ' . ($stages[$stage]['label'] ?? $stage) . ': Tinggi tidak boleh lebih lama dari Normal.';
    }
    if (isset($seconds['Normal'], $seconds['Rendah']) && $seconds['Normal'] > $seconds['Rendah']) {
        $errors[] = 'Target ' . ($stages[$stage]['label'] ?? $stage) . ': Normal tidak boleh lebih lama dari Rendah.';
    }
}

// Target PIC harus lebih kecil dari Resolution Time terkecil (semua kategori aktif) pada urgensi yang sama.
$minResolution = [];
foreach ($pdo->query('SELECT * FROM crf_kategori WHERE tgl_dihapus IS NULL AND aktif = 1')->fetchAll() as $category) {
    foreach (crfCategorySlaMatrix($category) as $level => $item) {
        if ($item !== null) {
            $secs = crfSlaSeconds($item['value'], $item['unit']);
            if (!isset($minResolution[$level]) || $secs < $minResolution[$level]['seconds']) {
                $minResolution[$level] = ['seconds' => $secs, 'label' => slaLabel($item['value'], $item['unit']), 'category' => (string) $category['nama']];
            }
        }
    }
}
foreach ($save as [$stage, $level, $value, $unit]) {
    if ($stage === 'PIC_MULAI' && isset($minResolution[$level]) && crfSlaSeconds($value, $unit) >= $minResolution[$level]['seconds']) {
        $errors[] = 'Target PIC ' . $level . ' (' . slaLabel($value, $unit) . ') harus lebih kecil dari Resolution Time terkecil ('
            . $minResolution[$level]['label'] . ', kategori ' . $minResolution[$level]['category'] . ').';
    }
}

if ($errors) {
    slaResponTargetFinish('danger', implode(' ', $errors), $redirect);
}

try {
    $pdo->beginTransaction();

    $upsert = $pdo->prepare('
        INSERT INTO crf_sla_respon_target (tahap, urgensi, nilai, satuan)
        VALUES (:tahap, :urgensi, :nilai, :satuan)
        ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), satuan = VALUES(satuan)
    ');
    foreach ($save as [$stage, $level, $value, $unit]) {
        $upsert->execute(['tahap' => $stage, 'urgensi' => $level, 'nilai' => $value, 'satuan' => $unit]);
    }

    $delete = $pdo->prepare('DELETE FROM crf_sla_respon_target WHERE tahap = :tahap AND urgensi = :urgensi');
    foreach ($remove as [$stage, $level]) {
        $delete->execute(['tahap' => $stage, 'urgensi' => $level]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('sla_respon_target error: ' . $e->getMessage());
    slaResponTargetFinish('danger', 'Gagal menyimpan target Response Time. Silakan coba lagi.', $redirect);
}

slaResponTargetFinish('success', 'Target Response Time disimpan. Berlaku untuk CRF yang masuk tahap tersebut mulai sekarang.', $redirect);
