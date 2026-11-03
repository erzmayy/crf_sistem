<?php
/**
 * Konfigurasi integrasi user SIAP.
 *
 * CRF_USER_SOURCE:
 * - local    : baca user dari tabel users di database CRF (mode demo/lokal)
 * - siap_db  : baca user dari tabel siap.tbl_user (mode integrasi awal)
 *
 * CRF_ROLE_SOURCE:
 * - table    : role dibaca dari crf_peran_pengguna
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
const CRF_ADMIN_USERIDS = ['administrator', 'N76559', 'M00026', 'M00025'];

/*
 * Akun DEMO: dapat menjalankan SEMUA peran (Admin, CMO, Handler, Kadep)
 * dari satu akun, khusus untuk presentasi. Set CRF_DEMO_MODE = false di
 * server production agar akun demo kembali menjadi Pemohon biasa.
 */
const CRF_DEMO_MODE = false;
const CRF_DEMO_USERIDS = ['CRFDEMO'];
const CRF_OTOMASI_USERIDS = ['N75392', 'N76559', '3754'];
const CRF_KADEP_OPERASIONAL_USERIDS = ['3736'];
const CRF_CMO_DEPTS = ['CMO'];

/*
 * Kode awal Nomor Register (PPU-<kode>.4.NNNN.MM.YY) diambil dari kpu_kode akun pemohon di SIAP.
 * Akun tanpa kpu_kode (mis. pusat) memakai kode cadangan berikut.
 */
const CRF_REGISTER_DEFAULT_CODE = '02';

/*
 * Tampilan modul.
 *
 * 'standalone' : CRF memakai header, sidebar, dan <html> sendiri (default).
 * 'module'     : CRF dipasang di layout SIAP; hanya area konten yang
 *                dikeluarkan (dibungkus .crf-module). Menu untuk SIAP
 *                tersedia dari crfNavGroups() di includes/crf_nav.php.
 *
 * Dapat dioverride lewat variabel lingkungan CRF_LAYOUT_MODE=module.
 */
const CRF_LAYOUT_MODE = 'standalone';

/* Awalan URL modul CRF di dalam SIAP, mis. '/modules/crf'. Kosong = otomatis. */
const CRF_BASE_URL = '';

/* Mode module: muat Bootstrap / Bootstrap Icons sendiri bila SIAP belum memuatnya. */
const CRF_LOAD_BOOTSTRAP = false;
const CRF_LOAD_ICONS = true;

/*
 * Notifikasi WhatsApp: pesan dimasukkan ke antrean WA SIAP (tabel tbl_wa_send, status '3')
 * dan dikirim oleh pengirim WA SIAP yang sudah ada. Default MATI.
 * Perlu migrasi 027_wa_notifikasi_migration.sql.
 *
 * CRF_WA_TEST_USERIDS: bila tidak kosong, HANYA userid ini yang dikirimi WA (untuk uji).
 * Kosongkan ([]) setelah uji agar semua penerima mendapat WA.
 */
const CRF_WA_ENABLED = false;
const CRF_WA_TEST_USERIDS = [];
const CRF_WA_APP_URL = 'https://siap.ptppu.co.id';
const CRF_WA_QUEUE_TABLE = 'tbl_wa_send';