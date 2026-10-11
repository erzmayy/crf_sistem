<?php
/**
 * tools/uji_urgensi.php  (khusus CLI)
 * Uji penilaian urgensi berbobot (config/urgensi.php + fungsi crfHitungUrgensi()).
 *
 * Pakai:  php tools/uji_urgensi.php
 * Keluar dengan kode 1 bila ada yang gagal.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/functions.php';

$gagal = 0;
$total = 0;
$cek = static function (string $nama, $aktual, $harapan) use (&$gagal, &$total): void {
    $total++;
    if ($aktual === $harapan) {
        echo "  ok    $nama\n";
        return;
    }
    $gagal++;
    echo "  GAGAL $nama\n        harapan: " . json_encode($harapan) . "\n        aktual : " . json_encode($aktual) . "\n";
};

echo "Penilaian urgensi berbobot\n";

$h = crfHitungUrgensi([]);
$cek('tanpa pilihan: level kosong', $h['level'], null);
$cek('tanpa pilihan: skor 0', $h['skor'], 0.0);

$h = crfHitungUrgensi(['low_new_issue_or_feature']);
$cek('fitur baru saja: skor 5', $h['skor'], 5.0);
$cek('fitur baru saja: Rendah', $h['level'], 'Rendah');

$h = crfHitungUrgensi(['normal_partial_access']);
$cek('akses sebagian: skor 10', $h['skor'], 10.0);
$cek('akses sebagian: Normal', $h['level'], 'Normal');

$h = crfHitungUrgensi(['normal_operations_disrupted', 'normal_temporary_workaround']);
$cek('terganggu + solusi merepotkan: skor 30', $h['skor'], 30.0);
$cek('terganggu + solusi merepotkan: Normal', $h['level'], 'Normal');

$h = crfHitungUrgensi(['normal_operations_disrupted', 'high_no_alternative']);
$cek('terganggu + tanpa alternatif: skor 45', $h['skor'], 45.0);
$cek('terganggu + tanpa alternatif: Tinggi (kombinasi menaikkan)', $h['level'], 'Tinggi');

$h = crfHitungUrgensi(['high_liquidity_legal_risk']);
$cek('risiko hukum saja: skor 35', $h['skor'], 35.0);
$cek('risiko hukum saja: Tinggi (tepat di ambang)', $h['level'], 'Tinggi');

$h = crfHitungUrgensi(['high_no_alternative']);
$cek('tanpa alternatif saja: skor 25', $h['skor'], 25.0);
$cek('tanpa alternatif saja: Normal', $h['level'], 'Normal');

$h = crfHitungUrgensi(['high_system_stopped', 'high_liquidity_legal_risk', 'high_no_alternative', 'normal_operations_disrupted']);
$cek('skor dibatasi maksimum 100', $h['skor'], 100.0);

$h = crfHitungUrgensi(['high_system_stopped'], ['ambang' => ['Tinggi' => 90, 'Normal' => 50]]);
$cek('pagar pengaman: layanan berhenti tetap Tinggi walau ambang 90', $h['level'], 'Tinggi');
$cek('pagar pengaman: ditandai', $h['dikunci'], true);

$h = crfHitungUrgensi(['normal_operations_disrupted'], ['ambang' => ['Tinggi' => 90, 'Normal' => 50]]);
$cek('tanpa pagar: ambang tinggi menurunkan hasil', $h['level'], 'Rendah');

$cek('kunci tidak dikenal diabaikan', crfDampakDipilih(['bukan_kunci', 'low_no_risk', 'low_no_risk']), ['low_no_risk']);
$cek('nilai lama (high/normal/low) diabaikan', crfDampakDipilih(['high']), []);
$cek('terima string koma', crfDampakDipilih('normal_partial_access, high_no_alternative'), ['normal_partial_access', 'high_no_alternative']);
$cek('terima string tunggal lama', crfDampakDipilih('low_no_risk'), ['low_no_risk']);
$cek('input kosong', crfDampakDipilih(null), []);

$cek('dampak utama: bobot tertinggi', crfDampakUtama(['normal_operations_disrupted', 'high_system_stopped']), 'high_system_stopped');
$cek('dampak utama: kosong', crfDampakUtama([]), null);

$cek('urgensi sistem: baris baru memakai skor',
    crfUrgensiSistem(['dampak_dipilih' => 'normal_operations_disrupted,high_no_alternative', 'kategori_dampak' => 'high_no_alternative']), 'Tinggi');
$cek('urgensi sistem: baris lama memakai pemetaan tunggal',
    crfUrgensiSistem(['dampak_dipilih' => null, 'kategori_dampak' => 'normal_partial_access']), 'Normal');
$cek('urgensi sistem: tanpa data', crfUrgensiSistem([]), null);

$labels = crfDampakLabels(['dampak_dipilih' => 'high_no_alternative,normal_partial_access', 'kategori_dampak' => 'high_no_alternative']);
$cek('label dampak: dua pernyataan', count($labels), 2);
$cek('label dampak: baris lama satu pernyataan', count(crfDampakLabels(['dampak_dipilih' => null, 'kategori_dampak' => 'low_no_risk'])), 1);

$cek('konflik: tanpa pernyataan "tidak ada" = aman', crfDampakBertentangan(['high_system_stopped', 'normal_operational_risk']), []);
$cek('konflik: berhenti total vs tidak mengganggu harian',
    count(crfDampakBertentangan(['high_system_stopped', 'low_no_daily_interruption'])), 1);
$cek('konflik: tidak ada risiko vs risiko hukum',
    count(crfDampakBertentangan(['low_no_risk', 'high_liquidity_legal_risk'])), 1);
$cek('konflik: "tidak ada" saja tidak bentrok', crfDampakBertentangan(['low_no_risk', 'low_no_efficiency_impact']), []);
$cek('konflik: fitur baru + berhenti total tidak dianggap bentrok', crfDampakBertentangan(['low_new_issue_or_feature', 'high_system_stopped']), []);
$semua = array_keys(crfImpactOptions());
$cek('catatan: centang semua -> ada catatan', count(crfDampakCatatan($semua)) >= 1, true);
$cek('catatan: pilihan wajar -> kosong', crfDampakCatatan(['normal_operations_disrupted', 'high_no_alternative']), []);
$cek('catatan: 7 centang tanpa konflik -> 1 catatan',
    count(crfDampakCatatan(['high_system_stopped', 'high_liquidity_legal_risk', 'high_no_alternative', 'normal_operations_disrupted', 'normal_operational_risk', 'normal_partial_access', 'normal_temporary_workaround'])), 1);
echo "\n$total pemeriksaan, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
