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

echo "Penilaian urgensi berbobot (butir tabel Keterangan Level Urgensi)\n";

$h = crfHitungUrgensi([]);
$cek('tanpa pilihan: level kosong', $h['level'], null);
$cek('tanpa pilihan: skor 0', $h['skor'], 0.0);

$tabel = [
    'Tinggi' => ['high_system_stopped', 'high_no_alternative', 'high_liquidity_legal_risk'],
    'Normal' => ['normal_operations_disrupted', 'normal_partial_access', 'normal_temporary_workaround', 'normal_operational_risk'],
    'Rendah' => ['low_new_issue_or_feature', 'low_no_efficiency_impact', 'low_no_daily_interruption', 'low_no_risk'],
];
foreach ($tabel as $levelTabel => $butirList) {
    foreach ($butirList as $butir) {
        // Butir Rendah baru berarti Rendah bila sendirian; butir Normal/Tinggi sendirian sesuai kolom tabel.
        $cek("tabel: $butir sendirian = $levelTabel", crfHitungUrgensi([$butir])['level'], $levelTabel);
    }
}
$cek('tabel: bobot butir Tinggi 50, Normal 10, Rendah 0',
    array_merge(
        array_map(static fn(string $k): float => (float) crfUrgencyConfig()['bobot'][$k], $tabel['Tinggi']),
        array_map(static fn(string $k): float => (float) crfUrgencyConfig()['bobot'][$k], $tabel['Normal']),
        array_map(static fn(string $k): float => (float) crfUrgencyConfig()['bobot'][$k], $tabel['Rendah'])
    ),
    [50.0, 50.0, 50.0, 10.0, 10.0, 10.0, 10.0, 0.0, 0.0, 0.0, 0.0]);
$cek('tabel: tiga butir Normal sekaligus tetap Normal',
    crfHitungUrgensi(['normal_operations_disrupted', 'normal_partial_access', 'normal_temporary_workaround'])['level'], 'Normal');
$cek('tabel: empat butir Normal sekaligus tetap Normal (tertinggi menang)',
    crfHitungUrgensi($tabel['Normal'])['level'], 'Normal');
$cek('teks 11 pernyataan sama dengan tabel resmi',
    array_values(crfImpactOptions()),
    ['Fungsi aplikasi berhenti total.', 'Tanpa ada solusi alternatif.', 'Dapat menimbulkan risiko likuiditas dan hukum.',
     'Operasional terganggu berat.', 'Aplikasi masih bisa diakses sebagian.', 'Ada solusi alternatif sementara yang merepotkan.',
     'Dapat menimbulkan risiko operasional.', 'Masalah atau permintaan fitur baru.', 'Tidak mempengaruhi efisiensi kerja.',
     'Tidak menghentikan operasional harian.', 'Tidak menimbulkan risiko.']);

$h = crfHitungUrgensi(['normal_operations_disrupted', 'normal_temporary_workaround']);
$cek('terganggu + solusi merepotkan: skor 20 Normal', [$h['skor'], $h['level']], [20.0, 'Normal']);
$h = crfHitungUrgensi(['normal_operations_disrupted', 'high_no_alternative']);
$cek('terganggu + tanpa alternatif: skor 60 Tinggi', [$h['skor'], $h['level']], [60.0, 'Tinggi']);
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
$cek('label dampak: dua pernyataan', count(crfDampakLabels(['dampak_dipilih' => 'high_no_alternative,normal_partial_access', 'kategori_dampak' => 'high_no_alternative'])), 2);
$cek('label dampak: baris lama satu pernyataan', count(crfDampakLabels(['dampak_dipilih' => null, 'kategori_dampak' => 'low_no_risk'])), 1);

$cek('konflik: tanpa pernyataan "tidak ada" = aman', crfDampakBertentangan(['high_system_stopped', 'normal_operational_risk']), []);
$cek('konflik: berhenti total vs tidak mengganggu harian', count(crfDampakBertentangan(['high_system_stopped', 'low_no_daily_interruption'])), 1);
$cek('konflik: tidak ada risiko vs risiko hukum', count(crfDampakBertentangan(['low_no_risk', 'high_liquidity_legal_risk'])), 1);
$cek('konflik: "tidak ada" saja tidak bentrok', crfDampakBertentangan(['low_no_risk', 'low_no_efficiency_impact']), []);
$cek('konflik: fitur baru + berhenti total tidak dianggap bentrok', crfDampakBertentangan(['low_new_issue_or_feature', 'high_system_stopped']), []);
$cek('catatan: centang semua -> ada catatan', count(crfDampakCatatan(array_keys(crfImpactOptions()))) >= 1, true);
$cek('catatan: pilihan wajar -> kosong', crfDampakCatatan(['normal_operations_disrupted', 'high_no_alternative']), []);

echo "\nTipe pengajuan: catatan CMO dan pembatasan\n";
$cek('tipe: Tambahan + berhenti total ditolak', crfTipeBertentangan('Permohonan Tambahan', ['high_system_stopped']), true);
$cek('tipe: Perubahan + berhenti total ditolak', crfTipeBertentangan('Permohonan Perubahan', ['high_system_stopped']), true);
$cek('tipe: Problem + berhenti total boleh', crfTipeBertentangan('Problem', ['high_system_stopped']), false);
$cek('tipe: tanpa tipe tidak menolak', crfTipeBertentangan('', ['high_system_stopped']), false);
$cek('tipe: Perubahan + dampak lain tidak ditolak', crfTipeBertentangan('Permohonan Perubahan', ['normal_operations_disrupted', 'high_no_alternative']), false);
$cek('tipe: Tambahan + hanya tanpa alternatif dibatasi Normal, CMO dapat catatan',
    count(array_filter(crfDampakCatatan(['high_no_alternative'], 'Permohonan Tambahan'), static fn($c) => str_contains($c, 'dibatasi Normal'))), 1);
$cek('tipe: Tambahan + risiko uang/hukum saja (jalur sah) tidak ditandai',
    crfDampakCatatan(['high_liquidity_legal_risk'], 'Permohonan Tambahan'), []);
$cek('tipe: Tambahan + uang/hukum + tanpa alternatif ditandai (Tinggi tanpa jalur uang/hukum)',
    count(crfDampakCatatan(['high_liquidity_legal_risk', 'high_no_alternative'], 'Permohonan Tambahan')), 1);
$cek('tipe: Tambahan + dampak normal wajar', crfDampakCatatan(['normal_partial_access'], 'Permohonan Tambahan'), []);
$cek('tipe: Problem hanya fitur baru ditandai', count(crfDampakCatatan(['low_new_issue_or_feature'], 'Problem')), 1);
$cek('tipe: Problem fitur baru + operasional terganggu wajar',
    crfDampakCatatan(['low_new_issue_or_feature', 'normal_operations_disrupted'], 'Problem'), []);

echo "\nPertanyaan dampak (4 pertanyaan mengikuti tabel -> kunci dampak)\n";

// Alur: q1 a -> q3 a/b; q1 b -> q2 a (lalu q3 a/b) atau q2 b (q3 dilewati); q1 c -> q2, q3 dilewati.
$alur = static function (array $q1Pilihan): array {
    $daftar = [];
    foreach ($q1Pilihan as $q1) {
        if ($q1 === 'a') {
            $q2s = [''];
        } elseif ($q1 === 'b') {
            $q2s = ['a', 'b'];
        } else {
            $q2s = [''];
        }
        foreach ($q2s as $q2) {
            if ($q1 === 'c') {
                $q3s = [''];
            } elseif ($q1 === 'a' || $q2 === 'a') {
                $q3s = ['a', 'b'];
            } else {
                $q3s = [''];         // q1 = b dan q2 = b: operasional tidak terhenti, q3 tidak ditanyakan
            }
            foreach ($q3s as $q3) {
                foreach (['a', 'b', 'c'] as $q4) {
                    $daftar[] = ['q1' => $q1, 'q2' => $q2, 'q3' => $q3, 'q4' => $q4];
                }
            }
        }
    }

    return $daftar;
};
$jumlahPertanyaan = static fn(array $j): int => 2 + ($j['q1'] === 'c' ? 0 : ($j['q1'] === 'a' ? 1 : ($j['q2'] === 'a' ? 2 : 1)));

$kombinasi = 0;
$bentrokKombinasi = [];
$roundTripSalah = [];
$salahTampil = [];
foreach (crfRequestTypeOptions() as $tipe) {
    $sembunyi = crfDampakOpsiTersembunyi($tipe);
    $q1Pilihan = array_values(array_diff(['a', 'b', 'c'], $sembunyi['q1'] ?? []));
    foreach ($alur($q1Pilihan) as $jawaban) {
        $kombinasi++;
        $kunci = crfDampakDariJawaban($jawaban, $tipe);
        $err = crfDampakValidasi($jawaban, $kunci, $tipe);
        if ($err !== null || crfDampakBertentangan($kunci)) {
            $bentrokKombinasi[] = "$tipe " . json_encode($jawaban) . ' ' . (string) $err;
        }
        if (crfJawabanDariDampak($kunci) !== $jawaban) {
            $roundTripSalah[] = "$tipe " . json_encode($jawaban) . ' -> ' . json_encode(crfJawabanDariDampak($kunci));
        }
        $tampil = crfDampakJawabanTampil($kunci, $tipe);
        $skor = crfHitungUrgensi($kunci)['skor'];
        if ($tampil === null || count($tampil) !== $jumlahPertanyaan($jawaban)
            || abs(min(100.0, array_sum(array_column($tampil, 'bobot'))) - $skor) > 0.001) {
            $salahTampil[] = "$tipe " . json_encode($jawaban);
        }
    }
}
$cek("semua $kombinasi kombinasi jawaban sah dan tanpa pertentangan", $bentrokKombinasi, []);
$cek('isi ulang jawaban dari kunci persis sama', $roundTripSalah, []);
$cek('detail: semua jawaban menjadi pertanyaan-jawaban, bobot menjumlah ke skor (maks 100)', $salahTampil, []);

$cek('opsi tersembunyi: Tambahan menyembunyikan q1 a', crfDampakOpsiTersembunyi('Permohonan Tambahan'), ['q1' => ['a']]);
$cek('opsi tersembunyi: Perubahan menyembunyikan q1 a', crfDampakOpsiTersembunyi('Permohonan Perubahan'), ['q1' => ['a']]);
$cek('opsi tersembunyi: Problem tidak menyembunyikan apa pun', crfDampakOpsiTersembunyi('Problem'), []);
$cek('judul q1 berbeda untuk ketiga tipe',
    count(array_unique([crfDampakJudul('q1', 'Problem'), crfDampakJudul('q1', 'Permohonan Perubahan'), crfDampakJudul('q1', 'Permohonan Tambahan')])), 3);
$cek('label q1 b per tipe: Problem menyebut aplikasi, Tambahan menyebut pekerjaan',
    [crfDampakLabel('q1', 'b', 'Problem'), crfDampakLabel('q1', 'b', 'Permohonan Tambahan')],
    ['Aplikasi hanya bisa diakses sebagian', 'Sebagian pekerjaan terhambat']);
$cek('label tanpa tipe memakai teks default', crfDampakLabel('q4', 'a'), 'Dapat menimbulkan risiko likuiditas dan hukum');

echo "\nAlur pertanyaan\n";
$cek('alur: q1 c -> q2 dan q3 tidak ditanyakan',
    [crfDampakPertanyaanTampil('q2', ['q1' => 'c']), crfDampakPertanyaanTampil('q3', ['q1' => 'c']), crfDampakPertanyaanTampil('q4', ['q1' => 'c'])], [false, false, true]);
$cek('alur: q1 a -> q2 tidak ditanyakan, q3 ditanyakan',
    [crfDampakPertanyaanTampil('q2', ['q1' => 'a']), crfDampakPertanyaanTampil('q3', ['q1' => 'a'])], [false, true]);
$cek('alur: q1 b -> q2 ditanyakan, q3 menunggu q2',
    [crfDampakPertanyaanTampil('q2', ['q1' => 'b']), crfDampakPertanyaanTampil('q3', ['q1' => 'b'])], [true, false]);
$cek('alur: q1 b + q2 a (terganggu berat) -> q3 ditanyakan',
    crfDampakPertanyaanTampil('q3', ['q1' => 'b', 'q2' => 'a']), true);
$cek('alur: q1 b + q2 b (operasional tidak terhenti) -> q3 tidak ditanyakan',
    crfDampakPertanyaanTampil('q3', ['q1' => 'b', 'q2' => 'b']), false);
$cek('alur: sebelum q1 dijawab q2 tampil, q3 belum',
    [crfDampakPertanyaanTampil('q2', []), crfDampakPertanyaanTampil('q3', [])], [true, false]);
$cek('alur: pilihan q3 c (dihapus) tidak tersedia',
    crfDampakOpsiTampil('q3', 'c', ['q1' => 'a'], 'Problem'), false);
$cek('normalisasi: jawaban pertanyaan yang tidak ditanyakan dikosongkan',
    crfDampakNormalisasi(['q1' => 'c', 'q2' => 'a', 'q3' => 'a', 'q4' => 'c'], 'Problem'), ['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c']);
$cek('normalisasi: q3 c lama dikosongkan',
    crfDampakNormalisasi(['q1' => 'a', 'q2' => '', 'q3' => 'c', 'q4' => 'c'], 'Problem')['q3'], '');

echo "\nLevel dari jawaban\n";
$h = static fn(array $j, string $tipe): array => crfHitungUrgensi(crfDampakDariJawaban($j, $tipe), null, $tipe);
$cek('level: berhenti total -> Tinggi', $h(['q1' => 'a', 'q2' => '', 'q3' => 'b', 'q4' => 'c'], 'Problem')['level'], 'Tinggi');
$cek('level: sebagian + terganggu berat + solusi merepotkan (skor 20) -> Normal', $h(['q1' => 'b', 'q2' => 'a', 'q3' => 'b', 'q4' => 'c'], 'Problem')['level'], 'Normal');
$cek('level: sebagian + terganggu berat + tanpa solusi alternatif -> Tinggi (skor 70)',
    $h(['q1' => 'b', 'q2' => 'a', 'q3' => 'a', 'q4' => 'c'], 'Problem')['skor'], 70.0);
$cek('normalisasi: q3 dikosongkan bila q1 = b dan q2 = b',
    crfDampakNormalisasi(['q1' => 'b', 'q2' => 'b', 'q3' => 'a', 'q4' => 'c'], 'Problem')['q3'], '');
$cek('level: normal semua -> Rendah', $h(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], 'Problem')['level'], 'Rendah');
$cek('level: normal tetapi risiko likuiditas dan hukum -> Tinggi', $h(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'a'], 'Problem')['level'], 'Tinggi');
$cek('level: normal tetapi risiko operasional -> Normal', $h(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'b'], 'Problem')['level'], 'Normal');
$cek('kunci: berfungsi normal menambah "tidak menghentikan operasional" dan "tidak mempengaruhi efisiensi" otomatis',
    crfDampakDariJawaban(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], 'Problem'),
    ['low_no_efficiency_impact', 'low_no_daily_interruption', 'low_no_risk']);
$cek('kunci: bukan Problem + normal menambah permintaan fitur baru',
    in_array('low_new_issue_or_feature', crfDampakDariJawaban(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], 'Permohonan Perubahan'), true), true);
$cek('kunci: Problem tidak menambah permintaan fitur baru',
    in_array('low_new_issue_or_feature', crfDampakDariJawaban(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], 'Problem'), true), false);
$cek('kunci: jawaban pertanyaan 2 dan 3 yang tidak ditanyakan (q1 = c) diabaikan',
    crfDampakDariJawaban(['q1' => 'c', 'q2' => 'a', 'q3' => 'a', 'q4' => 'c'], 'Problem'),
    ['low_no_efficiency_impact', 'low_no_daily_interruption', 'low_no_risk']);

echo "\nPagar Permohonan Tambahan (Tinggi hanya dengan risiko likuiditas dan hukum)\n";
$tanpaHukum = ['q1' => 'b', 'q2' => 'a', 'q3' => 'a', 'q4' => 'c'];
$cek('Tambahan: terganggu berat + tanpa solusi alternatif (skor 60) dibatasi Normal', $h($tanpaHukum, 'Permohonan Tambahan')['level'], 'Normal');
$cek('Tambahan: pembatasan ditandai dibatasi=true', $h($tanpaHukum, 'Permohonan Tambahan')['dibatasi'], true);
$cek('Perubahan: jawaban sama tetap Tinggi', $h($tanpaHukum, 'Permohonan Perubahan')['level'], 'Tinggi');
$cek('Problem: jawaban sama tetap Tinggi', $h($tanpaHukum, 'Problem')['level'], 'Tinggi');
$cek('Tambahan tanpa tipe diberikan tidak dibatasi (kompatibel)',
    crfHitungUrgensi(crfDampakDariJawaban($tanpaHukum, 'Permohonan Tambahan'))['level'], 'Tinggi');
$cek('Tambahan + risiko likuiditas dan hukum tetap Tinggi', $h(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'a'], 'Permohonan Tambahan')['level'], 'Tinggi');
$cek('Tambahan: normal semua tetap Rendah', $h(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], 'Permohonan Tambahan')['level'], 'Rendah');
$cek('Tambahan: CMO dapat catatan pembatasan',
    count(array_filter(crfDampakCatatan(crfDampakDariJawaban($tanpaHukum, 'Permohonan Tambahan'), 'Permohonan Tambahan'), static fn($c) => str_contains($c, 'dibatasi Normal'))), 1);
$cek('urgensi sistem CRF Tambahan mengikuti pagar',
    crfUrgensiSistem(['dampak_dipilih' => implode(',', crfDampakDariJawaban($tanpaHukum, 'Permohonan Tambahan')), 'jenis_pengajuan' => 'Permohonan Tambahan']), 'Normal');
$cek('urgensi sistem CRF Problem tidak dibatasi',
    crfUrgensiSistem(['dampak_dipilih' => implode(',', crfDampakDariJawaban($tanpaHukum, 'Problem')), 'jenis_pengajuan' => 'Problem']), 'Tinggi');
$cek('Problem berjalan normal ditandai untuk CMO',
    count(crfDampakCatatan(crfDampakDariJawaban(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], 'Problem'), 'Problem')), 1);

echo "\nValidasi dan isi ulang\n";
$cek('validasi: q1 = c tanpa jawaban q2 dan q3 sah',
    crfDampakValidasi(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], [], 'Problem'), null);
$cek('validasi: q1 = a tanpa jawaban q2 sah',
    crfDampakValidasi(['q1' => 'a', 'q2' => '', 'q3' => 'a', 'q4' => 'c'], [], 'Problem'), null);
$cek('validasi: q1 = b tanpa jawaban q2 ditolak',
    crfDampakValidasi(['q1' => 'b', 'q2' => '', 'q3' => 'a', 'q4' => 'c'], [], 'Problem'), 'Jawab semua pertanyaan dampak.');
$cek('validasi: q4 belum dijawab ditolak',
    crfDampakValidasi(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => ''], [], 'Problem'), 'Jawab semua pertanyaan dampak.');
$cek('validasi: q3 c dengan q1 berhenti total dikosongkan lalu ditolak',
    crfDampakValidasi(['q1' => 'a', 'q2' => '', 'q3' => 'c', 'q4' => 'c'], [], 'Problem'), 'Jawab semua pertanyaan dampak.');
$cek('validasi: Tambahan + q1 a dipaksa kirim ditolak',
    crfDampakValidasi(['q1' => 'a', 'q2' => '', 'q3' => 'a', 'q4' => 'a'], [], 'Permohonan Tambahan') !== null, true);
$cek('validasi: Perubahan + q1 a dipaksa kirim ditolak dengan pesan tipe',
    crfDampakValidasi(['q1' => 'a', 'q2' => '', 'q3' => 'a', 'q4' => 'a'], [], 'Permohonan Perubahan'), 'Tipe pengajuan dan pernyataan dampak tidak sesuai. Periksa kembali pilihan Anda.');
$cek('validasi: formulir lama kosong ditolak', crfDampakValidasi(null, [], 'Problem'), 'Pilih minimal satu pernyataan dampak.');
$cek('validasi: formulir lama sah', crfDampakValidasi(null, ['normal_partial_access'], 'Problem'), null);
$cek('isi ulang: draft hanya q1 = b', crfJawabanDariDampak(crfDampakDariJawaban(['q1' => 'b', 'q2' => '', 'q3' => '', 'q4' => ''], 'Problem')),
    ['q1' => 'b', 'q2' => '', 'q3' => '', 'q4' => '']);
$cek('isi ulang: draft hanya q2 = b (q1 belum dijawab)', crfJawabanDariDampak(['low_no_daily_interruption']), ['q1' => '', 'q2' => 'b', 'q3' => '', 'q4' => '']);
$cek('isi ulang: draft hanya q4', crfJawabanDariDampak(['low_no_risk']), ['q1' => '', 'q2' => '', 'q3' => '', 'q4' => 'c']);
$cek('post: kode lama q2 = d dibuang', crfDampakJawabanDariPost(['dampak_q1' => 'c', 'dampak_q2' => 'd'])['q2'], '');
$cek('post: kode q4 dikenal', crfDampakJawabanDariPost(['dampak_q4' => 'c'])['q4'], 'c');
$cek('post: kode tak dikenal dikosongkan',
    crfDampakJawabanDariPost(['dampak_q1' => 'z', 'dampak_q2' => 'a']), ['q1' => '', 'q2' => 'a', 'q3' => '', 'q4' => '']);
$cek('post: tanpa jawaban baru -> null', crfDampakJawabanDariPost(['dampak_dipilih' => ['low_no_risk']]), null);
$cek('pertanyaan 2 punya 2 pilihan, pertanyaan 3 punya 2, pertanyaan 4 punya 3',
    [array_keys(crfDampakQuestions()['q2']['opsi']), array_keys(crfDampakQuestions()['q3']['opsi']), array_keys(crfDampakQuestions()['q4']['opsi'])],
    [['a', 'b'], ['a', 'b'], ['a', 'b', 'c']]);
$cek('pertanyaan 4 punya contoh "misalnya"', str_starts_with((string) (crfDampakQuestions()['q4']['petunjuk'] ?? ''), '(misalnya'), true);

echo "\nTampilan detail: CRF lama tetap daftar pernyataan\n";
$cek('detail: CRF lama berhenti total + terganggu berat -> daftar pernyataan (null)',
    crfDampakJawabanTampil(['high_system_stopped', 'normal_operations_disrupted'], 'Problem'), null);
$cek('detail: CRF lama satu pernyataan -> null', crfDampakJawabanTampil(['low_no_risk'], 'Problem'), null);
$cek('detail: tanpa kunci -> null', crfDampakJawabanTampil([], 'Problem'), null);
$cek('detail: q1 = c hanya baris q1 dan q4',
    array_column(crfDampakJawabanTampil(crfDampakDariJawaban(['q1' => 'c', 'q2' => '', 'q3' => '', 'q4' => 'c'], 'Problem'), 'Problem'), 'q'), ['q1', 'q4']);
$cek('detail: jawaban dan label per tipe tampil',
    array_column(crfDampakJawabanTampil(crfDampakDariJawaban(['q1' => 'b', 'q2' => 'a', 'q3' => 'b', 'q4' => 'c'], 'Permohonan Perubahan'), 'Permohonan Perubahan'), 'jawaban'),
    ['Sebagian pekerjaan terhambat', 'Operasional terganggu berat', 'Ada solusi alternatif sementara, tetapi merepotkan', 'Tidak menimbulkan risiko']);

echo "\n$total pemeriksaan, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
