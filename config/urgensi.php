<?php
/**
 * config/urgensi.php
 * ---------------------------------------------------------------
 * Penilaian urgensi berbobot, mengikuti tabel resmi "Keterangan Level Urgensi".
 *
 * Pemohon menjawab 4 pertanyaan (crfDampakQuestions()); tiap jawaban adalah satu
 * butir tabel dan dipetakan ke satu kunci pernyataan. Level mengikuti butir
 * tertinggi yang berlaku (seperti tabel): butir kolom Tinggi = 50 (satu butir
 * sudah Tinggi), butir kolom Normal = 10 (empat butir Normal sekaligus = 40,
 * tetap Normal), butir kolom Rendah = 0. Skor = jumlah bobot (maksimum 'maks'),
 * lalu dipetakan ke Level Urgensi lewat 'ambang'.
 *
 *   skor >= ambang['Tinggi']  -> Tinggi
 *   skor >= ambang['Normal']  -> Normal
 *   selain itu                -> Rendah
 *
 * 'wajib_tinggi': pernyataan kritis yang SELALU menghasilkan Tinggi,
 * berapa pun skornya (pagar pengaman agar bobot tidak mengencerkan
 * kasus berat).
 *
 * 'bertentangan': pasangan pernyataan yang tidak mungkin benar bersamaan
 * (mis. "tidak ada risiko" dengan "risiko likuiditas/hukum"). Pengajuan
 * dengan pasangan ini ditolak dengan pesan netral.
 *
 * 'batas_banyak': jika jumlah pernyataan dicentang >= angka ini, CMO
 * diberi penanda "perlu dicek" (pemohon tidak diberi tahu).
 *
 * NILAI DI BAWAH ADALAH USULAN AWAL; sesuaikan dengan ketentuan resmi
 * (SOP/pedoman) perusahaan. Kunci pernyataan mengikuti
 * crfImpactGroups() di includes/functions.php.
 * ---------------------------------------------------------------
 */
return [
    'bobot' => [
        // Tinggi
        'high_system_stopped'         => 50,  // Fungsi aplikasi berhenti total
        'high_liquidity_legal_risk'   => 50,  // Risiko likuiditas dan hukum
        'high_no_alternative'         => 50,  // Tanpa ada solusi alternatif
        // Normal
        'normal_operations_disrupted' => 10,  // Operasional terganggu berat
        'normal_operational_risk'     => 10,  // Dapat menimbulkan risiko operasional
        'normal_partial_access'       => 10,  // Aplikasi masih bisa diakses sebagian
        'normal_temporary_workaround' => 10,  // Ada solusi alternatif sementara yang merepotkan
        // Rendah
        'low_new_issue_or_feature'    => 0,   // Masalah atau permintaan fitur baru
        'low_no_efficiency_impact'    => 0,   // Tidak mempengaruhi efisiensi kerja
        'low_no_daily_interruption'   => 0,   // Tidak menghentikan operasional harian
        'low_no_risk'                 => 0,   // Tidak menimbulkan risiko
    ],

    'maks' => 100,

    'ambang' => [
        'Tinggi' => 50,
        'Normal' => 10,
    ],

    'wajib_tinggi' => [
        'high_system_stopped',
        'high_liquidity_legal_risk',
    ],

    'bertentangan' => [
        'low_no_daily_interruption' => ['high_system_stopped', 'normal_operations_disrupted'],
        'low_no_risk'               => ['high_liquidity_legal_risk', 'normal_operational_risk'],
        'low_no_efficiency_impact'  => ['high_system_stopped', 'normal_operations_disrupted', 'normal_temporary_workaround'],
    ],

    'batas_banyak' => 7,

    // Kecocokan Tipe Pengajuan dengan pernyataan dampak (tidak mengubah skor).
    // 'tolak'  : kombinasi mustahil, pengajuan ditolak.
    // 'tandai' : kombinasi janggal, CMO diberi penanda "Perlu dicek".
    // 'tandai_hanya': ditandai bila SEMUA pernyataan yang dipilih ada di daftar ini.
    'tipe_pengajuan' => [
        'Permohonan Tambahan' => [
            'tolak'  => ['high_system_stopped'],
            'tandai' => ['high_liquidity_legal_risk', 'high_no_alternative'],
            // Tandai hanya bila level Tinggi tetap tercapai TANPA risiko uang/hukum (jalur yang sah
            // untuk fitur baru); jadi catatan "periksa dampaknya" tidak muncul di hampir semua CRF.
            'tandai_tinggi_tanpa' => ['high_liquidity_legal_risk'],
            // Pagar: fitur baru yang belum ada tidak bisa menjadi darurat tanpa alasan kuat. Level Tinggi
            // hanya bila salah satu pernyataan ini dipilih; selain itu dibatasi Normal (CMO tetap bisa
            // menaikkan lewat Forum).
            'tinggi_hanya_dengan' => ['high_liquidity_legal_risk'],
        ],
        // Permohonan Perubahan: yang diubah sudah berjalan, jadi "tidak bisa berjalan sama sekali" tidak berlaku.
        'Permohonan Perubahan' => [
            'tolak' => ['high_system_stopped'],
        ],
        'Problem' => [
            // Problem tetapi semua jawaban dampak ringan (pekerjaan masih berjalan normal).
            'tandai_hanya' => [
                'low_new_issue_or_feature',
                'low_no_daily_interruption',
                'low_no_efficiency_impact',
                'low_no_risk',
            ],
        ],
    ],
];
