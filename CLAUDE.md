# Konteks sistem CRF

Dokumen ini adalah konteks lengkap sistem CRF dan cara CRF terpasang di server SIAP PT Persona Prima Utama (PPU). Bahasa kerja: Indonesia. Dokumen lain: `README.md` (instalasi), `WORKFLOW_UPDATE.md` (alur rinci), `database/README.md` (daftar migrasi), `CATATAN_PERUBAHAN.md` (riwayat perubahan per berkas), `docs/panduan/` (buku panduan pengguna).

Isi: 1 Ringkasan, 2 Peta berkas, 3 Peran dan akses, 4 Alur, 5 Data, 6 Urgensi dan SLA, 7 Forum, 8 Kategori dan PIC, 9 Notifikasi, 10 Lampiran dan laporan, 11 Keamanan, 12 CRF di server SIAP, 13 Pengembangan lokal, 14 Aturan kerja, 15 Status dan hal tertunda.

## 1. Ringkasan

Change Request Form (CRF): pengajuan, persetujuan, eksekusi, UAT, dan pelaporan permintaan perubahan, dengan modul Forum dan Notifikasi. PHP native (PDO) dan MySQL, tanpa framework. Library Composer (`vendor/`, tidak masuk git): `dompdf/dompdf` (PDF), `phpmailer/phpmailer` (email), `aws/aws-sdk-php` (lampiran di storage S3/Wasabi). Zona waktu `Asia/Jakarta` (diset di `config/database.php`, koneksi `+07:00`).

Helpdesk dipegang modul Helpdesk SIAP; modul Helpdesk di CRF sudah dihapus (migrasi 022).

CRF berjalan dua cara dengan kode yang sama: mandiri (`standalone`, login dan layout sendiri, dipakai di lokal) dan sebagai modul di dalam SIAP (`module`, dipakai di server). Lihat bagian 12.

## 2. Peta berkas

| Lokasi | Isi |
|---|---|
| `user/` | Pemohon: `form_crf.php`, `pengajuan_saya.php`, `detail.php`, `dashboard.php` |
| `cmo/` | `index.php` (antrean), `detail.php` |
| `otomasi/` | `index.php` (antrean PIC), `detail.php` (proses), `view_detail.php` (baca saja) |
| `kadep_operasional/` | `index.php`, `detail.php` |
| `admin/` | `dashboard.php` (laporan), `detail.php`, `edit.php`, `master_data.php` (Kategori CRF) |
| `forum/` | `index.php`, `detail.php` (panel detail CRF) |
| `notifications/` | `index.php`, `open.php` |
| `crf/` | `open.php` (tautan notifikasi, arahkan ke halaman sesuai peran), `handling.php` (Handling Kategori CRF) |
| `actions/` | Endpoint POST: `submit_crf`, `save_draft`, `delete_draft`, `cmo_action`, `kadep_operasional_approve`, `automation_start`, `automation_action`, `submit_pir`, `uat_upload`, `admin_cancel_crf`, `category_master`, `forum_comment`, `forum_discussion`, `download_attachment`, `export_crf`, `export_admin_report`, `user_search`, `login`, `logout` |
| `includes/` | Logika inti (lihat di bawah) |
| `includes/partials/` | Potongan tampilan bersama: `informasi_sla`, `informasi_pengajuan`, `detail_header`, `detail_permintaan`, `timeline`, `handler_assignment`, `category_members`, `crf_list_filters`, `crf_list_pagination`, `crf_row_request`, `crf_row_status`, `forum_button`, `uat_upload_form` |
| `config/` | `siap.php` (sumber user, peran, mode layout, WA), `sla.php` (jam kerja, libur), `urgensi.php` (bobot), `database.php`; contoh: `mail.example.php`, `wasabi.example.php`, `dev_login.example.php` |
| `assets/` | `css/style.css`, `css/bootstrap-scoped.css` (dibuat otomatis, jangan diedit), `js/script.js`, `forum.js`, `integration.js`, `img/logo-header.png` |
| `database/` | `schema.sql` (instalasi baru), `migrations/001-027`, `maintenance/` (cron dan ekspor) |
| `tools/` | Skrip bantu: `check_login.php`, `uji_urgensi.php`, `wasabi_make_private.php` |
| `index.php`, `login.php`, `logout.php` | Pintu masuk mandiri. Tidak dipakai di SIAP |

Berkas inti di `includes/`:
- `auth.php`: `requireLogin`, `getCrfRole`, `requireCrfRole`, `isAdmin`, `canAccessCrf`, `canHandleCrf`, `crfRoleLabel`.
- `siap_user_provider.php`: baca user dari `siap.tbl_user`, `resolveCrfRoleFromUser()`, verifikasi password lokal.
- `session.php`: `getCurrentUser()`, `h()` (escape HTML), memuat `csrf.php`.
- `functions.php`: nomor register, status dan label, tahap, perhitungan SLA dan urgensi, `logCrfActivity()`, filter daftar, lampiran.
- `categories.php`: kategori dan PIC. `forum.php`, `forum_discussions.php`: Forum.
- `notifications.php`, `whatsapp.php`, `sla_alerts.php`: notifikasi, WA, peringatan SLA.
- `crf_url.php`: semua pembuat URL dan redirect sadar mode. `crf_nav.php`: menu dan angka antrean. `crf_queue_notice.php`: keterangan CRF di luar antrean. `header.php`, `footer.php`: layout. `admin_crf_report.php`, `admin_crf_xlsx.php`: laporan.

## 3. Peran dan akses

Peran dihitung dari data user SIAP (`CRF_USER_SOURCE = 'siap_db'` membaca `siap.tbl_user`; `CRF_ROLE_SOURCE = 'resolver'` menentukan peran dari userid atau departemen). Pengaturan di `config/siap.php`.

| Peran | Penentuan | Tugas |
|---|---|---|
| Pemohon | semua user lain | ajukan CRF, revisi, isi PIR |
| CMO | departemen user ada di `CRF_CMO_DEPTS` (`CMO`); tidak ada daftar userid CMO | verifikasi, UAT, finalisasi, Forum (ajukan dan catat hasil) |
| Otomasi / PIC CRF | `CRF_OTOMASI_USERIDS` atau handler kategori | mulai kerjakan, eksekusi, implementasi |
| Kadep Operasional | `CRF_KADEP_OPERASIONAL_USERIDS` | setujui sebelum eksekusi |
| Admin | `CRF_ADMIN_USERIDS`, hak tambahan di atas peran kerja | pantau, laporan, kelola kategori dan handler, tugaskan ulang, batalkan |
| Demo | `CRF_DEMO_USERIDS` hanya bila `CRF_DEMO_MODE` true | semua peran, untuk presentasi |

Urutan penentuan: demo, Otomasi, Kadep, CMO, Admin, Pemohon. Admin tidak boleh menugaskan ulang atau membatalkan CRF yang sedang ia pegang sebagai handler.

Dua lapis akses (jangan dicampur): CRF mengecek peran sendiri lewat `requireCrfRole([...])` di tiap halaman. Di SIAP, menu hanya tampil bila user punya hak menu (bagian 12). Memberi hak menu tidak memberi peran CRF.

## 4. Alur

```
Pemohon -> CMO (verifikasi) -> Kadep (setuju; SLA mulai) -> PIC (Mulai kerjakan, eksekusi) -> UAT oleh CMO
  UAT perlu perbaikan: kembali ke PIC, lalu UAT ulang
  UAT lulus: PIC isi tanggal dan hasil implementasi -> Pemohon isi PIR -> CMO finalisasi -> Selesai
CMO boleh mengajukan pembahasan Forum; hasil (Tetap/Diubah) mencatat level urgensi dan SLA, lalu CRF langsung ke Kadep.
CMO boleh meminta revisi (kembali ke Pemohon) atau membatalkan. Admin boleh membatalkan.
```

Tahap (`crf_pengajuan.tahap_alur`): `PEMOHON`, `CMO_FILTER`, `kadep_operasional`, `OTOMASI`, `UAT`, `PEMOHON_PIR`, `CMO_FINAL`, `SELESAI`. Status (`status`): `Draft`, `Belum Ditindak Lanjuti` (Menunggu Tindakan), `Perlu Revisi`, `Dalam Proses` (Sedang Diproses), `Solve` (Selesai), `Cancel` (Dibatalkan). Label: `statusLabel()`, `workflowStageLabel()`.

Tahap `OTOMASI` dipakai tiga kali, dibedakan `crfOtomasiPhase()`: eksekusi (`otomasi_selesai` kosong), perbaikan hasil UAT (`otomasi_selesai` ada, `tgl_uat_lulus` kosong), isi implementasi (`tgl_uat_lulus` ada). Aksi divalidasi menurut fase di server, jadi UAT tidak bisa dilewati. Aksi yang tidak cocok dengan tahap ditolak dengan pesan "Aksi tidak tersedia pada tahap CRF saat ini" (biasa terjadi bila tombol diklik dua kali atau halaman lama dipakai); datanya aman.

Setiap tindakan dicatat di `crf_log_aktivitas` lewat `logCrfActivity()` (aktivitas, deskripsi, `pelaksana` berupa nama, status lama dan baru). Riwayat di halaman detail membaca tabel ini.

## 5. Data

Tabel (semua awalan `crf_`; nama tabel dan kolom sudah berbahasa Indonesia sejak migrasi 023 dan 024): `crf_pengajuan` (inti), `crf_nomor_urut`, `crf_lampiran`, `crf_log_aktivitas`, `crf_peran_pengguna`, `crf_forum_komentar`, `crf_forum_status_baca`, `crf_forum_pembahasan`, `crf_kategori`, `crf_kategori_pic`, `crf_notifikasi`, `crf_peringatan_sla`. Instalasi baru cukup `schema.sql`; database berjalan memakai migrasi bernomor berurutan.

`crf_pengajuan` (kolom penting): `nomor_register` (unik), `pengguna_id` (id di `tbl_user`), `nama_pemohon`, `dept_asal`, `dept_tujuan`, `kategori_perubahan` (Aplikasi, Infrastruktur, Proses, Security, Lainnya), `kategori_id`, `deskripsi_perubahan`, `dampak_dipilih`, `skor_urgensi`, `level`, `level_urgensi_final`, `status`, `tahap_alur`, `pic_id`, `pembahasan_forum_terbuka`, kolom SLA (`sla_nilai`, `sla_satuan`, `sla_mulai`, `sla_tenggat`, `sla_respon_*`), `otomasi_mulai`, `otomasi_selesai`, `tgl_uat_lulus`, `tgl_disetujui_kadep`, `tgl_selesai`, `tgl_dibatalkan`, `tanggapan_tindak_lanjut`. Aturan wajib isi ditegakkan di PHP (`actions/submit_crf.php`), bukan di skema.

Nomor register: `PPU-<kode>.4.NNNN.MM.YY`. Kode dari `kpu_kode` akun pemohon di SIAP, cadangan `CRF_REGISTER_DEFAULT_CODE` (`02`). Penghitung di `crf_nomor_urut` (aman untuk pengajuan bersamaan).

Draft disimpan lewat `actions/save_draft.php` (status `Draft`, tanpa nomor register). Draft tidak terlihat oleh peran lain.

## 6. Urgensi dan SLA

Urgensi berbobot (`config/urgensi.php`, `crfHitungUrgensi()`): pemohon menjawab pertanyaan dampak yang mengikuti tabel resmi "Keterangan Level Urgensi" (`crfDampakQuestions()`: 1 keadaan fungsi aplikasi, 2 dampak operasional harian, 3 solusi alternatif, 4 risiko; q2 hanya bila q1 = sebagian, q3 (solusi alternatif, 2 pilihan) hanya bila fungsi berhenti total atau operasional terganggu berat; level = butir tertinggi, detail menampilkan "Penentu level" bukan skor; judul dan teks pilihan menyesuaikan Tipe Pengajuan; Perubahan dan Tambahan tanpa "fungsi aplikasi berhenti total"; Tambahan hanya bisa Tinggi bila risiko likuiditas dan hukum, `tinggi_hanya_dengan`), lalu `crfDampakDariJawaban()` memetakannya ke pernyataan dampak yang disimpan di `dampak_dipilih`; skor = jumlah bobot (maksimum 100), lalu ambang menentukan Tinggi, Normal, Rendah. Ada pernyataan `wajib_tinggi`, pasangan `bertentangan` (ditolak, untuk formulir lama), dan `batas_banyak` (penanda "perlu dicek" untuk CMO). Nilai bobot adalah usulan awal.

SLA (`config/sla.php`, `includes/functions.php`): hanya hari dan jam kerja, 08:00-17:00, tanpa Sabtu, Minggu, dan libur di `CRF_SLA_HOLIDAYS` (perbarui tiap tahun). Satu hari SLA = satu hari kerja penuh. Dua ukuran:
- Response Time: dari persetujuan Kadep sampai PIC menekan Mulai kerjakan.
- Resolution Time: dari persetujuan Kadep sampai PIC menyelesaikan eksekusi. SLA berhenti saat eksekusi selesai; UAT dan perbaikan tidak dihitung ulang.

Target dari matriks kategori x level urgensi (`crfCategorySlaMatrix`, `crfCategoryResponMatrix`), disalin ke CRF saat Kadep menyetujui. Sebelum disetujui, SLA belum berjalan dan panel Informasi SLA menampilkan keterangan "SLA dimulai setelah persetujuan Kepala Departemen Operasional" beserta target perkiraan.

Peringatan SLA ke PIC (`includes/sla_alerts.php`, dijalankan `database/maintenance/kirim_peringatan_sla.php`): pengingat 50% (dilewati bila SLA di bawah 1 jam), mendesak (sisa waktu di bawah atau sama dengan 25% SLA atau 1 jam), terlewati (diulang tiap hari kerja); untuk Response Time: mendesak dan terlewati. Penanda anti-ganda di `crf_peringatan_sla`. Skrip lain: `hitung_tenggat_sla.php`, `ekspor_portabel.php` (manual).

## 7. Forum

Ruang diskusi per CRF (`forum/`). Akses: CMO, Otomasi, Admin, Kadep, Demo (`forumRoles()`). Pemohon tidak punya akses Forum. Hanya CMO (dan demo) yang boleh mengajukan pembahasan (`forumRequestDiscussion`); hasil dicatat CMO atau Admin (`forumResultRoles()`). Saat SLA default belum tersedia, sistem membuka pembahasan sendiri. Selama pembahasan terbuka (`pembahasan_forum_terbuka = 1`), CMO belum bisa meneruskan CRF ke Kadep. Hasil Tetap atau Diubah memperbarui `level_urgensi_final`, `level`, dan SLA, lalu CRF langsung ke Kadep. Pembahasan yang melewati target diingatkan (`forumSendDueReminders`). Komentar sistem otomatis dicatat. Angka komentar belum dibaca: `forumUnreadCounts()` (`crf_forum_status_baca`).

## 8. Kategori dan PIC

Lima kategori CRF (`crf_kategori`), masing-masing punya PIC CRF (handler, `crf_kategori_pic`) dan matriks SLA (resolution dan response). Dikelola Admin di `admin/master_data.php` dan `crf/handling.php`. PIC kategori menangani CRF kategorinya; `canHandleCrf()` membatasi handler pada kategori yang ditanganinya. PIC yang menekan Mulai kerjakan menjadi pemegang CRF (`pic_id`).

## 9. Notifikasi

`notifyUsers()` (`includes/notifications.php`) menyimpan baris `crf_notifikasi` (in-app, lonceng) di dalam transaksi; setelah commit, `dispatchPendingNotificationEmails($pdo)` mengirim email (`status_email`: pending, sent, failed, skipped) lalu memanggil `dispatchPendingNotificationWhatsapp()`. Kegagalan email atau WA tidak menggagalkan proses utama. Pelaku tidak dikirimi notifikasi dari tindakannya sendiri (`exceptUserId`).

Penerima per kejadian (27 jenis email) ada di dokumen "Notifikasi email CRF: siapa menerima dan kapan dikirim". Ringkas: CMO menerima CRF baru dan UAT; Kadep menerima Persetujuan CRF; PIC menerima CRF disetujui, hasil UAT, dan peringatan SLA; Pemohon menerima revisi, pembatalan, disetujui, isi PIR, pengingat PIR, selesai, dan hasil pembahasan Forum (level urgensi dan SLA).

Email: mode module memakai SMTP SIAP dari `tbl_email_settings` (id 1) bila `config/mail.php` tidak ada; mode lokal memakai `config/mail.php`. Templat `crfEmailHtml()`: logo, kepala berwarna datar, kartu ringkasan CRF (nomor, status, pemohon, unit, kategori, urgensi, tanggal, ringkasan), tombol Buka CRF, kaki. Warna aksen dari judul (`crfEmailTone()`): biru (umum), oranye (revisi, mendesak, pengingat), hijau (selesai, disetujui, finalisasi), merah (dibatalkan, terlewati). Tautan email di mode module lewat `crfUrlFromPath()` dan `CRF_WA_APP_URL`.

WhatsApp (`includes/whatsapp.php`): CRF tidak memanggil API WA dan tidak menyimpan token. Ia menambah baris ke `tbl_wa_send` (`kode = 'CRF'`, `status_kirim = '3'`, antre); pengirim WA SIAP yang mengirim. Default mati (`CRF_WA_ENABLED = false`). `CRF_WA_TEST_USERIDS` membatasi hanya WA, bukan email. Nomor dari `tbl_user.no_wa`. Tidak untuk komentar Forum dan pengingat SLA 50%. Perlu migrasi 027 (`crf_notifikasi.status_wa`). Belum dipastikan pengirim WA SIAP memproses kode `CRF`.

## 10. Lampiran, ekspor, laporan

Lampiran (PDF, JPG, PNG, maksimal 5 MB per berkas) diunggah ke bucket private Wasabi/S3 (`config/wasabi.php`, tidak di git) dan hanya dibuka lewat `actions/download_attachment.php` setelah cek akses. Dokumen UAT memakai `kategori = 'uat'`. Cetak PDF per CRF: `actions/export_crf.php` (dompdf). Laporan admin dan ekspor Excel: `admin/dashboard.php`, `actions/export_admin_report.php`, `includes/admin_crf_xlsx.php`.

## 11. Keamanan dan konvensi kode

- Query memakai PDO prepared statement. Keluaran di-escape dengan `h()`. Form POST memakai `csrfField()` dan `verifyCsrf()`.
- Akses halaman: `requireLogin()`, `requireCrfRole([...])`, `canAccessCrf($pdo, $id)`.
- Semua URL lewat helper `crfUrl()`, `crfActionUrl()`, `crfSelfUrl()`, `crfRedirect()` (`includes/crf_url.php`); jangan menulis path mentah.
- Aksi yang mengubah status memakai transaksi, `logCrfActivity()`, notifikasi, lalu dispatch setelah commit.
- Folder `database/` dan berkas `.sql/.md/.py/.lock` diblokir `.htaccess`.
- `config/database.php` masih berisi baris komentar dengan kredensial database server. Hapus baris itu dari berkas dan riwayat git sebelum repositori dibagikan lebih luas.

## 12. CRF di server SIAP

### Server dan berkas
- SIAP: `https://siap.ptppu.co.id` (PHP, Bootstrap 3, routing `index.php?mod=<modul>&submod=<submodul>`, halaman di `pages/<modul>/<submodul>.php` dimuat setelah layout tercetak). VPS `vps-ppu.ptppu.co.id`, panel CloudPanel, user situs `ptppu-siap`.
- Kode CRF: `/home/ptppu-siap/htdocs/siap.ptppu.co.id/crf/`. Database `siap`: tabel `crf_*` berbagi database dengan tabel SIAP (`tbl_user`, `tbl_menu_submodul`, `tbl_user_menu`, `tbl_email_settings`, `tbl_wa_send`).
- Server tidak punya `.git`. Berkas dipindah manual (unggah lewat File Manager) dari project lokal. Yang belum diunggah belum berubah di server.
- Modul SIAP lain (ada di menu): Bupot, Helpdesk, PKS, Piutang, Sikat, Aplikasi, dan User (Modul, Sub Modul, Kategori Menu, Master User, Hak Akses User). SIAP punya pustaka bersama di `3rdparty/` (`engine.php` pembungkus `$db`, `email_helper.php` dengan PHPMailer, `wa_helper.php`). CRF tidak memakai `$db` SIAP; ia memakai PDO sendiri ke database yang sama.

### Pola hibrida
Kode CRF tetap di `crf/`. SIAP memuat lewat `pages/crf/<submod>.php` yang isinya satu baris `require __DIR__ . '/../../crf/<berkas>.php';`, sehingga halaman tampil di layout SIAP tanpa redirect ke halaman CRF terpisah. Pemetaan submodul ke berkas (`crfRoutes()` di `includes/crf_url.php`): `index` ke `cmo/index.php`, `tindak_lanjut_otm` ke `otomasi/index.php`, `kadep_operasional`, `form_crf` ke `user/form_crf.php`, `dashboard` ke `admin/dashboard.php`, `kategori` ke `admin/master_data.php`, `forum`, `pengajuan_saya`, `handling`. Turunan tanpa menu: `cmo_detail`, `otomasi_detail`, `otomasi_view_detail`, `admin_detail`, `kadep_operasional_detail`, `forum_detail`, `pengajuan_detail`, `crf_open`, `notifikasi`, `notifikasi_buka`.

### Mode layout dan URL
- Server: `CRF_LAYOUT_MODE = 'module'`, `CRF_BASE_URL = '/crf'`, `CRF_LOAD_BOOTSTRAP = true`. Lokal: `standalone`, base URL kosong. `crfLayoutIsModule()` juga membaca variabel lingkungan `CRF_LAYOUT_MODE`.
- **`config/siap.php` berbeda antara lokal dan server; jangan menimpa versi server dengan versi lokal.** Tambah konstanta baru langsung di berkas server.
- Mode module: header SIAP sudah terkirim, jadi `header('Location')` gagal; `crfRedirect()` memakai meta refresh dan JS. `display_errors` SIAP = 0, jadi error fatal PHP tampil sebagai halaman kosong.
- `includes/session.php`, `login.php`, `logout.php`, `actions/login.php`, `actions/logout.php`, `index.php` sengaja tidak diubah untuk SIAP: di server ada jembatan sesi SIAP (`daya_user`), dan login memakai halaman login SIAP.

### Menu dan hak akses SIAP
Sub Modul didaftarkan di `tbl_menu_submodul`; hak per user di `tbl_user_menu` (`userid`, `menu_id`, `modul_id`, `header_id`, `hak_akses`), diatur lewat Hak Akses User (`mod=user&submod=previlage_user`, menyimpan lewat `pages/user/previlage_user_simpan.php`). Id submenu CRF: 338 Verifikasi CMO, 339 Tindak Lanjut Otomasi, 341 Persetujuan Kadep Operasional, 342 Form CRF, 344 Dashboard CRF, 345 Kategori CRF, 346 Forum, 347 Pengajuan CRF Saya, 348 Handling CRF, 351 Notifikasi. Submodul yang tidak terdaftar tidak dicek haknya oleh SIAP; CRF tetap mengecek peran sendiri. Memberi hak ke semua user dilakukan lewat SQL `INSERT ... SELECT` ke `tbl_user_menu` (user baru tetap perlu diatur).

### Tampilan: bentrok Bootstrap
- CRF dibuat dengan Bootstrap 5, SIAP memuat Bootstrap 3 global. `assets/css/bootstrap-scoped.css` adalah Bootstrap 5.3.3 yang dibatasi ke `:where(.crf-module)` (dibuat otomatis dengan postcss; jangan diedit manual). JS Bootstrap 5 dimuat dengan `data-bs-no-jquery`.
- `<html>` SIAP berukuran 10px, jadi satuan `rem` CRF diganti `calc(X * var(--crf-rem))`; `--crf-rem` 16px di mode module.
- `assets/css/style.css` memuat penimpaan `.crf-module--embedded` untuk CSS SIAP yang bocor: `.show{display:block!important}` (offcanvas dipaksa `display:flex`), clearfix `.row::before`, `.fade` butuh `.in`, `.theme-blue .btn{color:#333}`, `input{border-radius:0!important}`, `label{font-weight:700}`, ukuran huruf `.btn`. Font judul sengaja mengikuti SIAP (Open Sans).
- Saat mengukur gaya lewat browser, matikan transisi (`*{transition:none !important}`); nilai terhitung bisa tampak beku.

### Email, WA, cron di server
Email: SMTP SIAP (`tbl_email_settings`). WA: antrean `tbl_wa_send`. Cron peringatan SLA: `*/5 * * * * php <php-situs> /home/ptppu-siap/htdocs/siap.ptppu.co.id/crf/database/maintenance/kirim_peringatan_sla.php` (belum dipasang). Migrasi 019 dan 026 sudah ada di server (tabel `crf_peringatan_sla`, kolom `sla_respon_at`).

### Jebakan yang sudah terjadi
- Berkas yang saling memuat harus diunggah bersama: `notifications.php` memuat `whatsapp.php`; tanpa berkas itu semua halaman CRF kosong. Cek berkas di server lewat alamat langsung (`/crf/includes/<berkas>.php`): 404 = tidak ada, 500 = error fatal.
- `includes/functions.php` memuat `crf_url.php` supaya endpoint yang hanya memuat `session.php` dan `functions.php` (mis. `save_draft.php`) tetap punya `crfRedirect()`; tanpa itu simpan draft gagal dengan HTTP 500.
- Tautan "Buka CRF" dari email membuka halaman sesuai peran penerima; bila CRF tidak ada di antrean perannya, tampil keterangan singkat (`crfQueueNoticeHtml()`: belum sampai atau sudah ditindaklanjuti oleh siapa), bukan error.

## 13. Pengembangan lokal

- Laragon, `http://localhost/crf_sistem/`, mode `standalone`, database lokal `crf_sistem` untuk tabel `crf_*`; data user dibaca dari database `siap` (`SIAP_DATABASE`) yang harus ada di MySQL lokal. Login lokal memakai akun di sana; `config/dev_login.php` (di-gitignore) hanya berlaku dari komputer lokal.
- Git: branch kerja `integrasi-siap`, utama `main`; ada branch `dev/mayy` dan beberapa `backup/*`. Tidak di git: `vendor/`, `config/wasabi.php`, `config/mail.php`, `config/dev_login.php`, `backups/`.
- Ekspor database portabel: `database/maintenance/ekspor_portabel.php`. Cadangan lama di `backups/`.
- Pengujian visual: halaman SIAP dibuka di browser dengan akun yang sudah login; hanya dibaca.

## 14. Aturan kerja dengan pemilik

- Jangan commit sendiri. Setiap selesai kerja, beri saran pesan commit.
- Catat tiap perubahan beserta berkas di `CATATAN_PERUBAHAN.md` (paket dikirim ke atasan tanpa `.git`).
- SIAP dan server hanya dibaca: jangan ubah, hapus, atau jalankan apa pun di sana. Skrip SQL untuk server dikirim lewat chat (tanpa `USE`, dijalankan pemilik di database `siap`), bukan dibuatkan folder atau berkas khusus.
- Jangan menyalin rahasia (token, password, kunci) ke chat atau berkas.
- Jelaskan ke pemilik dalam bahasa Indonesia, dengan langkah yang bisa langsung dijalankan. Bila diminta "jelaskan saja", jangan mengubah apa pun.

## 15. Status dan hal tertunda

- Cron peringatan SLA belum dipasang di server.
- WA belum aktif: migrasi 027 dan pengecekan pengirim WA SIAP (kode `CRF`) belum dikonfirmasi.
- Email notifikasi sudah terbukti terkirim dari server (CRF baru ke CMO).
- Badge angka antrean di menu SIAP baru dibahas: perlu endpoint JSON CRF dan satu potongan JS di layout SIAP.
- Kemungkinan perubahan kecil: pesan "Aksi tidak tersedia pada tahap CRF saat ini" dibuat lebih ramah.
- Perubahan yang belum di-commit saat dokumen ini ditulis: `includes/forum_discussions.php` (email ke pemohon saat hasil Forum dicatat), `CATATAN_PERUBAHAN.md`, `CLAUDE.md`.
