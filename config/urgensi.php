<?php
/**
 * config/urgensi.php
 * ---------------------------------------------------------------
 * Penilaian urgensi berbobot.
 *
 * Pemohon mencentang SEMUA pernyataan dampak yang berlaku. Skor =
 * jumlah bobot pernyataan yang dipilih (maksimum 'maks'), lalu
 * dipetakan ke Level Urgensi lewat 'ambang'.
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
        'high_system_stopped'         => 40,  // Fungsi aplikasi berhenti total
        'high_liquidity_legal_risk'   => 35,  // Risiko likuiditas dan hukum
        'high_no_alternative'         => 25,  // Belum ada alternatif
        // Normal
        'normal_operations_disrupted' => 20,  // Operasional terganggu berat
        'normal_operational_risk'     => 15,  // Risiko operasional
        'normal_partial_access'       => 10,  // Aplikasi masih bisa diakses sebagian
        'normal_temporary_workaround' => 10,  // Ada solusi sementara yang merepotkan
        // Rendah
        'low_new_issue_or_feature'    => 5,   // Masalah atau permintaan fitur baru
        'low_no_efficiency_impact'    => 0,   // Tidak mempengaruhi efisiensi kerja
        'low_no_daily_interruption'   => 0,   // Tidak menghentikan operasional harian
        'low_no_risk'                 => 0,   // Tidak menimbulkan risiko
    ],

    'maks' => 100,

    'ambang' => [
        'Tinggi' => 35,
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
        ],
        'Problem' => [
            'tandai_hanya' => ['low_new_issue_or_feature'],
        ],
    ],
];
