<?php
/**
 * config/sla.php
 * Aturan waktu kerja untuk perhitungan SLA (CRF).
 *
 * SLA hanya berjalan pada hari kerja dan jam kerja: Sabtu, Minggu, tanggal
 * libur di bawah ini, serta waktu di luar jam kerja tidak dihitung.
 * 1 Hari SLA = 1 hari kerja penuh (jam mulai sampai jam selesai).
 * Contoh (08:00-17:00): SLA 1 Hari yang dimulai Jumat 16:00 jatuh tempo
 * Senin 16:00 (1 jam Jumat + 8 jam Senin).
 */

/** Jam kerja SLA (detik sejak 00:00): 08:00 sampai 17:00. */
const CRF_SLA_WORK_START = 8 * 3600;
const CRF_SLA_WORK_END = 17 * 3600;
const CRF_SLA_WORK_SECONDS_PER_DAY = CRF_SLA_WORK_END - CRF_SLA_WORK_START;

/**
 * Tanggal libur nasional / cuti bersama (format Y-m-d).
 * Perbarui setiap tahun sesuai SKB 3 Menteri.
 *
 * Hanya hari Senin-Jumat yang dicantumkan: Sabtu dan Minggu sudah otomatis
 * tidak dihitung. Libur yang jatuh pada akhir pekan sengaja tidak ditulis.
 *
 * Cuti bersama bersifat fakultatif bagi pegawai swasta. Sesuaikan dengan
 * kebijakan perusahaan: hapus barisnya bila PPU tetap bekerja pada hari itu.
 */
const CRF_SLA_HOLIDAYS = [
    // ---- 2026 (SKB 3 Menteri No. 1497/2025, 2/2025, 5/2025) ----
    // Libur nasional
    '2026-01-01', // Tahun Baru Masehi
    '2026-01-16', // Isra Mikraj
    '2026-02-17', // Tahun Baru Imlek
    '2026-03-19', // Hari Suci Nyepi
    '2026-04-03', // Wafat Yesus Kristus
    '2026-05-01', // Hari Buruh Internasional
    '2026-05-14', // Kenaikan Yesus Kristus
    '2026-05-27', // Iduladha
    '2026-06-01', // Hari Lahir Pancasila
    '2026-06-16', // Tahun Baru Islam
    '2026-08-17', // Proklamasi Kemerdekaan
    '2026-08-25', // Maulid Nabi Muhammad SAW
    '2026-12-25', // Natal
    // Cuti bersama
    '2026-02-16', // Imlek
    '2026-03-18', // Nyepi
    '2026-03-20', // Idulfitri
    '2026-03-23', // Idulfitri
    '2026-03-24', // Idulfitri
    '2026-05-15', // Kenaikan Yesus Kristus
    '2026-05-28', // Iduladha
    '2026-12-24', // Natal

    // ---- 2027 (SKB 3 Menteri No. 1205/2026, 3/2026, 2/2026) ----
    // Libur nasional
    '2027-01-01', // Tahun Baru Masehi
    '2027-01-05', // Isra Mikraj
    '2027-03-08', // Hari Suci Nyepi
    '2027-03-10', // Idulfitri
    '2027-03-11', // Idulfitri
    '2027-03-26', // Wafat Yesus Kristus
    '2027-05-06', // Kenaikan Yesus Kristus
    '2027-05-17', // Iduladha
    '2027-05-20', // Waisak
    '2027-06-01', // Hari Lahir Pancasila
    '2027-08-17', // Proklamasi Kemerdekaan
    // Cuti bersama
    '2027-02-05', // Imlek
    '2027-03-09', // Idulfitri
    '2027-03-12', // Idulfitri
    '2027-03-15', // Idulfitri
    '2027-03-25', // Wafat Yesus Kristus
    '2027-05-18', // Iduladha
    '2027-05-19', // Waisak
    '2027-12-24', // Natal
];
