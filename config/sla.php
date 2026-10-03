<?php
/**
 * config/sla.php
 * Aturan waktu kerja untuk perhitungan SLA (CRF & Helpdesk).
 *
 * SLA hanya berjalan pada hari kerja: Sabtu, Minggu, dan tanggal libur
 * di bawah ini tidak dihitung. Contoh: SLA 1 Hari yang dimulai Jumat
 * 16:00 jatuh tempo Senin 16:00.
 */

/**
 * Tanggal libur nasional / cuti bersama (format Y-m-d).
 * Perbarui setiap tahun sesuai SKB pemerintah.
 */
const CRF_SLA_HOLIDAYS = [
    // '2026-12-25',
];
