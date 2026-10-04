<?php
/**
 * Konfigurasi integrasi user SIAP.
 *
 * CRF_USER_SOURCE:
 * - local    : baca user dari tabel users di database CRF (mode demo/lokal)
 * - siap_db  : baca user dari tabel siap.tbl_user (mode integrasi awal)
 *
 * CRF_ROLE_SOURCE:
 * - table    : role dibaca dari crf_user_roles
 * - resolver : role ditentukan dari userid/dept/divisi user SIAP
 */
const CRF_USER_SOURCE = 'siap_db';
const CRF_ROLE_SOURCE = 'resolver';

const SIAP_DATABASE = 'siap';
const SIAP_USER_TABLE = 'tbl_user';

/*
 * Hak akses Admin (TAMBAHAN di atas peran kerja):
 * pengelola & pemantau sistem (Dashboard CRF, Kategori & Handling,
 * laporan, penugasan ulang handler, pembatalan administratif).
 *
 * - User yang hanya terdaftar di sini (mis. 'administrator') berperan
 *   Admin murni: memantau, tidak menjalankan aksi CMO/Handler/Kadep/PIR.
 * - User yang juga punya peran kerja (mis. Handler) tetap menjalankan
 *   perannya, ditambah menu Admin. Ia tidak boleh memakai hak Admin
 *   untuk menugaskan ulang / membatalkan CRF yang sedang ia pegang.
 */
const CRF_ADMIN_USERIDS = ['administrator', 'N76559'];

/*
 * Akun DEMO: dapat menjalankan SEMUA peran (Admin, CMO, Handler, Kadep)
 * dari satu akun, khusus untuk presentasi. Set CRF_DEMO_MODE = false di
 * server production agar akun demo kembali menjadi Pemohon biasa.
 */
const CRF_DEMO_MODE = true;
const CRF_DEMO_USERIDS = ['CRFDEMO'];
const CRF_OTOMASI_USERIDS = ['N75392', 'N76559'];
const CRF_KADEP_OPERASIONAL_USERIDS = ['3736'];
const CRF_CMO_DEPTS = ['CMO'];
