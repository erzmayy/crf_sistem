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
$cek('terganggu + solusi merepotkan: skor 25', $h['skor'], 25.0);
$cek('terganggu + solusi merepotkan: Normal', $h['level'], 'Normal');

$h = crfHitungUrgensi(['normal_operations_disrupted', 'high_no_alternative']);
$cek('terganggu + tanpa alternatif: skor 40', $h['skor'], 40.0);
$cek('terganggu + tanpa alternatif: Tinggi (kombinasi menaikkan)', $h['level'], 'Tinggi');

$h = crfHitungUrgensi(['high_liquidity_legal_risk']);
$cek('risiko hukum saja: skor 35', $h['skor'], 35.0);
$cek('risiko hukum saja: Tinggi (tepat di ambang)', $h['level'], 'Tinggi');

$h = crfHitungUrgensi(['high_no_alternative']);
$cek('tanpa alternatif saja: skor 20', $h['skor'], 20.0);
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
$cek('tipe: Tambahan + berhenti total ditolak', crfTipeBertentangan('Permohonan Tambahan', ['high_system_stopped']), true);
$cek('tipe: Problem + berhenti total boleh', crfTipeBertentangan('Problem', ['high_system_stopped']), false);
$cek('tipe: tanpa tipe tidak menolak', crfTipeBertentangan('', ['high_system_stopped']), false);
$cek('tipe: Tambahan + hanya tanpa alternatif (Normal) tidak ditandai',
    crfDampakCatatan(['high_no_alternative'], 'Permohonan Tambahan'), []);
$cek('tipe: Tambahan + Tinggi tanpa jalur uang/hukum ditandai',
    count(crfDampakCatatan(['high_no_alternative', 'normal_operations_disrupted'], 'Permohonan Tambahan')), 1);
$cek('tipe: Tambahan + risiko uang/hukum saja (jalur sah) tidak ditandai',
    crfDampakCatatan(['high_liquidity_legal_risk'], 'Permohonan Tambahan'), []);
$cek('tipe: Tambahan + risiko uang/hukum + tanpa alternatif saja tidak ditandai',
    crfDampakCatatan(['high_liquidity_legal_risk', 'high_no_alternative'], 'Permohonan Tambahan'), []);
$cek('tipe: Tambahan + uang/hukum + tersendat + tanpa alternatif ditandai',
    count(crfDampakCatatan(['high_liquidity_legal_risk', 'high_no_alternative', 'normal_operations_disrupted'], 'Permohonan Tambahan')), 1);
$cek('tipe: Tambahan + dampak normal wajar', crfDampakCatatan(['normal_partial_access'], 'Permohonan Tambahan'), []);
$cek('tipe: Problem hanya fitur baru ditandai', count(crfDampakCatatan(['low_new_issue_or_feature'], 'Problem')), 1);
$cek('tipe: Problem fitur baru + operasional terganggu wajar',
    crfDampakCatatan(['low_new_issue_or_feature', 'normal_operations_disrupted'], 'Problem'), []);
$cek('tipe: tidak mengubah skor',
    crfHitungUrgensi(['high_no_alternative'])['skor'], 20.0);
echo "\nPertanyaan dampak (4 pertanyaan -> kunci dampak)\n";

$tipeSemua = crfRequestTypeOptions();
$kombinasi = 0;
$bentrokKombinasi = [];
$roundTripSalah = [];
foreach ($tipeSemua as $tipe) {
    $sembunyi = crfDampakOpsiTersembunyi($tipe);
    foreach (['a', 'b', 'c', 'd'] as $q1) {
        if (in_array($q1, $sembunyi['q1'] ?? [], true)) {
            continue;
        }
        // Pertanyaan 2 tidak ditanyakan bila pekerjaan tetap berjalan (q1 = d).
        foreach ($q1 === 'd' ? [''] : ['a', 'b'] as $q2) {
            foreach (['ya', 'tidak'] as $q3) {
                foreach (['ya', 'tidak'] as $q4) {
                    $jawaban = ['q1' => $q1, 'q2' => $q2, 'q3' => $q3, 'q4' => $q4];
                    $err = crfDampakValidasi($jawaban, crfDampakDariJawaban($jawaban, $tipe), $tipe);
                    $kombinasi++;
                    $kunci = crfDampakDariJawaban($jawaban, $tipe);
                    if ($err !== null || crfDampakBertentangan($kunci)) {
                        $bentrokKombinasi[] = "$tipe " . json_encode($jawaban) . ' ' . (string) $err;
                    }
                    $balik = crfJawabanDariDampak($kunci);
                    // q3/q4 "tidak" yang berpasangan dengan "ya" memang tidak tersimpan.
                    foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
                        $dikosongkanSah = in_array($q, ['q3', 'q4'], true) && $jawaban[$q] === 'tidak' && $balik[$q] === '';
                        if ($balik[$q] !== $jawaban[$q] && !$dikosongkanSah) {
                            $roundTripSalah[] = "$tipe $q " . json_encode($jawaban) . ' -> ' . json_encode($balik);
                        }
                    }
                }
            }
        }
    }
}
$cek("semua $kombinasi kombinasi jawaban sah dan tanpa pertentangan", $bentrokKombinasi, []);
$cek('isi ulang jawaban dari kunci konsisten', $roundTripSalah, []);

$cek('opsi tersembunyi: Tambahan menyembunyikan q1 a', crfDampakOpsiTersembunyi('Permohonan Tambahan'), ['q1' => ['a']]);
$cek('opsi tersembunyi: Problem tidak menyembunyikan apa pun', crfDampakOpsiTersembunyi('Problem'), []);
$cek('judul q1 per tipe berbeda', crfDampakJudul('q1', 'Permohonan Tambahan') !== crfDampakJudul('q1', 'Problem'), true);

$cek('level: q1 a (tidak bisa dipakai) -> Tinggi',
    crfHitungUrgensi(crfDampakDariJawaban(['q1' => 'a', 'q2' => 'b', 'q3' => 'tidak', 'q4' => 'tidak'], 'Problem'))['level'], 'Tinggi');
$cek('level: tersendat + cara lama -> Normal',
    crfHitungUrgensi(crfDampakDariJawaban(['q1' => 'b', 'q2' => 'b', 'q3' => 'tidak', 'q4' => 'tidak'], 'Problem'))['level'], 'Normal');
$cek('level: normal semua -> Rendah',
    crfHitungUrgensi(crfDampakDariJawaban(['q1' => 'd', 'q2' => '', 'q3' => 'tidak', 'q4' => 'tidak'], 'Problem'))['level'], 'Rendah');
$cek('level: rugi uang/hukum -> Tinggi',
    crfHitungUrgensi(crfDampakDariJawaban(['q1' => 'd', 'q2' => '', 'q3' => 'ya', 'q4' => 'tidak'], 'Problem'))['level'], 'Tinggi');
$cek('kunci: bukan Problem + berjalan normal menambah permintaan fitur baru',
    in_array('low_new_issue_or_feature', crfDampakDariJawaban(['q1' => 'd', 'q2' => '', 'q3' => 'tidak', 'q4' => 'tidak'], 'Permohonan Perubahan'), true), true);
$cek('kunci: Problem tidak menambah permintaan fitur baru',
    in_array('low_new_issue_or_feature', crfDampakDariJawaban(['q1' => 'd', 'q2' => '', 'q3' => 'tidak', 'q4' => 'tidak'], 'Problem'), true), false);
$cek('kunci: tersendat tidak menambah "tidak ada beban kerja tambahan"',
    in_array('low_no_efficiency_impact', crfDampakDariJawaban(['q1' => 'b', 'q2' => 'b'], 'Problem'), true), false);
echo "\nPagar Permohonan Tambahan (Tinggi hanya dengan risiko uang/hukum)\n";
$tersendatTanpaCara = ['q1' => 'b', 'q2' => 'a', 'q3' => 'tidak', 'q4' => 'ya'];
$cek('Tambahan: tersendat + tanpa cara lain (skor 50) dibatasi Normal',
    crfHitungUrgensi(crfDampakDariJawaban($tersendatTanpaCara, 'Permohonan Tambahan'), null, 'Permohonan Tambahan')['level'], 'Normal');
$cek('Tambahan: pembatasan ditandai dibatasi=true',
    crfHitungUrgensi(crfDampakDariJawaban($tersendatTanpaCara, 'Permohonan Tambahan'), null, 'Permohonan Tambahan')['dibatasi'], true);
$cek('Perubahan: jawaban sama tetap Tinggi',
    crfHitungUrgensi(crfDampakDariJawaban($tersendatTanpaCara, 'Permohonan Perubahan'), null, 'Permohonan Perubahan')['level'], 'Tinggi');
$cek('Problem: jawaban sama tetap Tinggi',
    crfHitungUrgensi(crfDampakDariJawaban($tersendatTanpaCara, 'Problem'), null, 'Problem')['level'], 'Tinggi');
$cek('Tambahan tanpa tipe diberikan tidak dibatasi (kompatibel)',
    crfHitungUrgensi(crfDampakDariJawaban($tersendatTanpaCara, 'Permohonan Tambahan'))['level'], 'Tinggi');
$cek('Tambahan + risiko uang/hukum tetap Tinggi',
    crfHitungUrgensi(crfDampakDariJawaban(['q1' => 'd', 'q2' => '', 'q3' => 'ya', 'q4' => 'tidak'], 'Permohonan Tambahan'), null, 'Permohonan Tambahan')['level'], 'Tinggi');
$cek('Tambahan: normal semua tetap Rendah',
    crfHitungUrgensi(crfDampakDariJawaban(['q1' => 'd', 'q2' => '', 'q3' => 'tidak', 'q4' => 'tidak'], 'Permohonan Tambahan'), null, 'Permohonan Tambahan')['level'], 'Rendah');
$cek('Tambahan: CMO dapat catatan pembatasan',
    count(array_filter(crfDampakCatatan(crfDampakDariJawaban($tersendatTanpaCara, 'Permohonan Tambahan'), 'Permohonan Tambahan'), static fn($c) => str_contains($c, 'dibatasi Normal'))), 1);
$cek('urgensi sistem CRF Tambahan mengikuti pagar',
    crfUrgensiSistem(['dampak_dipilih' => implode(',', crfDampakDariJawaban($tersendatTanpaCara, 'Permohonan Tambahan')), 'jenis_pengajuan' => 'Permohonan Tambahan']), 'Normal');
$cek('urgensi sistem CRF Problem tidak dibatasi',
    crfUrgensiSistem(['dampak_dipilih' => implode(',', crfDampakDariJawaban($tersendatTanpaCara, 'Problem')), 'jenis_pengajuan' => 'Problem']), 'Tinggi');
echo "\n";
$cek('q1 = d: jawaban q2 a/b diabaikan, kunci "tanpa beban tambahan" otomatis',
    crfDampakDariJawaban(['q1' => 'd', 'q2' => 'a', 'q3' => 'tidak', 'q4' => 'ya'], 'Problem'),
    ['normal_operational_risk', 'low_no_efficiency_impact', 'low_no_daily_interruption']);
$cek('q1 = d: pekerjaan salah saja -> Normal (bukan Tinggi)',
    crfHitungUrgensi(crfDampakDariJawaban(['q1' => 'd', 'q2' => 'a', 'q3' => 'tidak', 'q4' => 'ya'], 'Problem'))['level'], 'Normal');
$cek('validasi: q1 = d tanpa jawaban q2 sah',
    crfDampakValidasi(['q1' => 'd', 'q2' => '', 'q3' => 'tidak', 'q4' => 'tidak'], [], 'Problem'), null);
$cek('isi ulang: q1 = d mengosongkan q2',
    crfJawabanDariDampak(['low_no_daily_interruption', 'high_no_alternative'])['q2'], '');
$cek('isi ulang: CRF versi 3 pilihan (q2 c lama) dikosongkan, bukan error',
    crfJawabanDariDampak(['normal_partial_access', 'low_no_efficiency_impact'])['q2'], '');
$cek('post: kode lama q2 = c dibuang',
    crfDampakJawabanDariPost(['dampak_q1' => 'c', 'dampak_q2' => 'c'])['q2'], '');
$cek('pertanyaan 2 hanya 2 pilihan', array_keys(crfDampakQuestions()['q2']['opsi']), ['a', 'b']);
$cek('pertanyaan 3 dan 4 punya contoh "misalnya"',
    [str_starts_with((string) (crfDampakQuestions()['q3']['petunjuk'] ?? ''), '(misalnya'), str_starts_with((string) (crfDampakQuestions()['q4']['petunjuk'] ?? ''), '(misalnya')], [true, true]);
$cek('draft sebagian: hanya q1 menghasilkan satu kunci', crfDampakDariJawaban(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => ''], 'Problem'), ['normal_partial_access']);

$cek('validasi: jawaban belum lengkap ditolak',
    crfDampakValidasi(['q1' => 'a', 'q2' => '', 'q3' => 'ya', 'q4' => 'ya'], ['high_system_stopped'], 'Problem'), 'Jawab keempat pertanyaan dampak.');
$cek('validasi: Tambahan + q1 a dipaksa kirim ditolak',
    crfDampakValidasi(['q1' => 'a', 'q2' => 'b', 'q3' => 'ya', 'q4' => 'ya'], crfDampakDariJawaban(['q1' => 'a', 'q2' => 'b', 'q3' => 'ya', 'q4' => 'ya'], 'Permohonan Tambahan'), 'Permohonan Tambahan') !== null, true);
$cek('validasi: formulir lama kosong ditolak', crfDampakValidasi(null, [], 'Problem'), 'Pilih minimal satu pernyataan dampak.');
$cek('validasi: formulir lama sah', crfDampakValidasi(null, ['normal_partial_access'], 'Problem'), null);
$cek('post: tanpa jawaban baru -> null', crfDampakJawabanDariPost(['dampak_dipilih' => ['low_no_risk']]), null);
$cek('post: kode tak dikenal dikosongkan',
    crfDampakJawabanDariPost(['dampak_q1' => 'z', 'dampak_q2' => 'a']), ['q1' => '', 'q2' => 'a', 'q3' => '', 'q4' => '']);

echo "\n$total pemeriksaan, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
