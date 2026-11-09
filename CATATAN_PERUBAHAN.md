# Catatan Perubahan Pasca-Zip

Daftar file yang berubah sejak folder project dikirim (zip) ke atasan. Zip tersebut tidak menyertakan
folder `.git`, jadi file ini dipakai untuk mencocokkan versi yang sudah di-hosting dengan versi terbaru.

Cara pakai saat update ke server: salin file di daftar tanggal terkait, lalu jalankan file migrasi SQL
yang bertanda (jalankan).

---

## 2026-11-09

Penyeragaman istilah dan tampilan (hasil tinjauan konsistensi):

- Label status: satu istilah "Perlu Revisi" (sebelumnya "Ditolak / Perlu Revisi"), keterangan tahap Pemohon "Perbaikan oleh Pemohon" (sebelumnya "Menunggu Pemeriksaan"), cadangan "Menunggu Tindakan" (sebelumnya "Submitted"), tahap Otomasi "Tindak Lanjut Otomasi": `includes/functions.php`, `actions/cmo_action.php`, `includes/partials/timeline.php`
- Format tanggal seragam `dd-mm-yyyy HH:mm` (24 jam, tanpa bulan Inggris dan AM/PM): `includes/partials/timeline.php`, `forum/index.php`, `includes/forum.php`, `includes/header.php`, `notifications/index.php`. Tidak diubah: format panjang berbahasa Indonesia di email dan keterangan antrean (`includes/crf_queue_notice.php`, `includes/notifications.php`)
- "SLA" untuk Resolution diganti "Resolution Time" pada judul peringatan ("Resolution time terlewati/mendesak", "Pengingat resolution time") dan kalimat mulai/berhenti/tidak dihitung: `includes/sla_alerts.php`, `actions/automation_action.php`, `actions/kadep_operasional_approve.php`, `cmo/detail.php`, `otomasi/detail.php`, `otomasi/index.php`, `kadep_operasional/index.php`, `kadep_operasional/detail.php`, `includes/partials/informasi_sla.php`, `includes/partials/timeline.php`. `crfStripSlaText()` kini juga menyaring "Resolution Time" dan "Response time" agar Pemohon tetap tidak melihatnya (efek samping: kalimat "Response time tercatat" ikut tersaring untuk Pemohon)
- Judul notifikasi: "Pengingat isi PIR CRF: N" dan "UAT lulus, isi implementasi: N" (`actions/cmo_action.php`)
- Judul halaman daftar = label menu ("Dashboard CRF", "Pengajuan CRF Saya", "Handling CRF", "Tindak Lanjut Otomasi", "Verifikasi CMO", "Persetujuan Kepala Departemen Operasional") dan judul detail seragam "Detail CRF - nomor": `admin/dashboard.php`, `cmo/index.php`, `cmo/detail.php`, `crf/handling.php`, `forum/detail.php`, `kadep_operasional/index.php`, `kadep_operasional/detail.php`, `otomasi/index.php`, `otomasi/detail.php`, `otomasi/view_detail.php`, `user/pengajuan_saya.php`
- Kadep: nama lengkap "Kepala Departemen Operasional" di kalimat, "Kadep" hanya di tabel dan badge: `actions/cmo_action.php`, `actions/export_admin_report.php`, `actions/export_crf.php`, `admin/dashboard.php`, `includes/forum_discussions.php`, `includes/partials/informasi_sla.php`; riwayat lama "Diteruskan ke Kepala Departemen" tampil lengkap lewat pemetaan di `timeline.php`
- Rincian bulanan PDF dan Excel: kolom `kategori_perubahan` berlabel "Kelompok Laporan" (bukan "Kategori"): `actions/export_admin_report.php`
- Riwayat lama tetap tampil seragam lewat pemetaan di `timeline.php` (SLA berhenti/mulai dihitung, Ditolak / Perlu Revisi, Tindak Lanjut Divisi Otomasi); tanpa migrasi data
- `assets/css/style.css`: aturan CSS tak terpakai dibuang (kelas `crf-sla-panels`, `-metrics`, `-line`, `-target`, `-due`, `-grid`, `-item`, `-value`)
- Dokumen: `CLAUDE.md` (hasil Forum hanya CMO, target Resolution vs Response, peringatan Resolution vs Response, daftar nilai tersimpan versi lama), `WORKFLOW_UPDATE.md`, `README.md`, komentar `includes/sla_alerts.php` dan `database/maintenance/kirim_peringatan_sla.php`
- Sengaja tidak diubah: nilai tersimpan (aktivitas, status, kunci tahap), menu SIAP, dan buku panduan docx/pdf (perlu diperbarui manual)

Perubahan lain pada hari yang sama:

Istilah "PIC CRF" diganti "PIC Otomasi" pada semua teks yang tampil (tombol, judul, pesan, notifikasi, email, laporan PDF, riwayat, peran di header):

- Sekitar 80 baris di 28 berkas: `actions/admin_cancel_crf.php`, `automation_action.php`, `automation_start.php`, `category_master.php`, `export_crf.php`, `kadep_operasional_approve.php`, `admin/detail.php`, `admin/master_data.php`, `assets/js/integration.js`, `assets/js/script.js`, `cmo/detail.php`, `crf/handling.php`, `forum/index.php`, `includes/auth.php` (label peran), `forum_discussions.php`, `functions.php` (label tahap "Mulai dikerjakan PIC Otomasi"), `partials/crf_row_request.php`, `partials/handler_assignment.php`, `partials/informasi_pengajuan.php`, `partials/timeline.php`, `kadep_operasional/detail.php`, `kadep_operasional/index.php`, `otomasi/detail.php`, `user/form_crf.php`
- Riwayat lama yang menulis "PIC CRF" tetap tampil "PIC Otomasi" lewat pemetaan di `timeline.php`; tidak ada migrasi data
- `README.md`, `WORKFLOW_UPDATE.md`, `docs/alur-helpdesk-crf.html`, `CLAUDE.md`
- Tidak diganti: kata "PIC" yang berdiri sendiri (mis. "Response PIC", "PIC kategori"), nama peran di kode (`otomasi`), dan nama menu "Tindak Lanjut Otomasi"

Istilah "eksekusi" diganti "pengerjaan" pada semua teks yang tampil (tombol, judul, pesan, notifikasi, email, laporan PDF, riwayat). Kata "implementasi" kini hanya untuk fase setelah UAT lulus (isi hasil implementasi), jadi tidak ada dua langkah yang bernama sama:

- `otomasi/detail.php` (judul "Pengerjaan / Tangani Permintaan", tombol "Selesai Pengerjaan · Kirim ke UAT"), `otomasi/index.php`, `actions/automation_action.php`, `actions/automation_start.php`, `actions/kadep_operasional_approve.php`, `actions/uat_upload.php`, `actions/export_crf.php`, `cmo/detail.php`, `cmo/index.php`, `kadep_operasional/detail.php`, `admin/dashboard.php`, `includes/functions.php` (label status "Disetujui · Pengerjaan"), `includes/partials/informasi_sla.php`, `includes/partials/timeline.php`, `includes/sla_alerts.php`
- Riwayat lama tetap tampil seragam: `timeline.php` memetakan nilai tersimpan "Eksekusi Selesai" menjadi "Pengerjaan Selesai" dan kata eksekusi/dieksekusi menjadi pengerjaan/dikerjakan saat ditampilkan
- Sengaja tidak diganti: nilai aktivitas "Eksekusi Selesai" di `crf_log_aktivitas` (dipakai untuk pencarian) dan kunci fase kode `'eksekusi'`; tanpa migrasi data
- `README.md`, `WORKFLOW_UPDATE.md`, `docs/alur-helpdesk-crf.html`, `CLAUDE.md` (kalimatnya disesuaikan)
- Fase setelah UAT lulus tidak berubah: judul "Implementasi", tombol "Implementasikan", entri Riwayat "Implementasi Selesai"
- `includes/crf_nav.php` (tooltip antrean PIC: "CRF menunggu tindak lanjut PIC", karena antrean mencakup pengerjaan, perbaikan UAT, dan implementasi)

Response Time untuk semua peran selain Pemohon (CMO verifikasi, Kadep, PIC mulai dikerjakan, CMO UAT, CMO finalisasi), ditampilkan sederhana: satu keterangan waktu respon di Riwayat, satu angka gabungan di dashboard. Response PIC digabung ke mekanisme yang sama (kolom `sla_respon_*` tetap ditulis dan dibekukan saat Kadep menyetujui, lalu disalin ke tabel baru). Target semua peran kini satu tabel global (tahap x urgensi), sama untuk semua kategori; UAT dan finalisasi sengaja tanpa target (lamanya tetap dicatat).

- `database/migrations/028_sla_respon_tahap_migration.sql` (jalankan; tabel `crf_sla_respon`, `crf_sla_respon_target` beserta target awal, penanda peringatan, dan salinan Response PIC yang sudah ada) dan `database/schema.sql`
- `includes/functions.php` (`crfRespSync()` dan fungsi pendukung; `logCrfActivity()` memanggil `crfRespSync()`; `getCrfResponStatus()` kini pembungkus `crfRespRowStatus()`; `crfSlaSeconds()`)
- `includes/partials/timeline.php` (keterangan "Respon CMO/Kadep/PIC: 2 Menit · tepat waktu" pada entri yang menutup satu tahap, dan "Menunggu CMO · sisa/terlambat" pada langkah yang sedang menunggu; hanya peran internal, tidak untuk Pemohon)
- `includes/partials/informasi_sla.php` (panel Informasi SLA kini hanya Resolution Time dengan tampilan baru)
- `includes/partials/crf_row_status.php` (penanda "Response mendesak / melewati target" di daftar, tidak untuk Pemohon), `otomasi/index.php` (daftar Otomasi tidak memakai penanda itu karena kolom Status SLA sudah menampilkannya)
- `includes/sla_alerts.php` (`crfSendTahapAlerts()` menggantikan `crfSendResponAlerts()`: pengingat 50%, mendesak, terlambat berulang tiap hari kerja; eskalasi ke Admin pada terlambat pertama)
- Istilah status seragam (Masih dalam target, Melewati target, Sesuai target, Terlambat): `includes/functions.php`, `assets/js/script.js`
- Perbaikan: tahap yang ditutup oleh sistem (mis. Forum dibuka otomatis saat SLA standar kategori kosong) tidak lagi dihitung sebagai respon CMO dan tidak dicatat atas nama pemohon (`crfRespSync()` menerima pelaku; `logCrfActivity()` meneruskannya). Riwayat menyebut "Lama UAT" dan "Lama finalisasi" (bukan "Respon") untuk tahap tanpa target (`includes/partials/timeline.php`)
- Target PIC global: `actions/kadep_operasional_approve.php` membekukan target dari tabel global (matriks lama per kategori hanya cadangan bila tabel belum diisi); kolom Response dihapus dari formulir kategori (`admin/master_data.php`, kolom lama di `crf_kategori` dibiarkan sebagai cadangan); `includes/partials/informasi_sla.php` (perkiraan target sebelum disetujui). Validasi dua arah: target PIC harus lebih kecil dari Resolution terkecil (`actions/sla_respon_target.php`) dan Resolution kategori harus lebih besar dari target PIC (`actions/category_master.php`). Tambahan di `actions/category_master.php`: urutan Resolution per urgensi dijaga (Tinggi tidak lebih lama dari Normal, Normal tidak lebih lama dari Rendah; kolom kosong ikut Normal). Data kategori (bukan berkas): Resolution Tinggi dan Rendah diisi untuk Security (4 jam / 2 hari) dan Infrastruktur, Proses, Lainnya (1,5 hari / 5 hari) hanya bila masih kosong; Aplikasi tidak diubah. Di database lokal sudah dijalankan; untuk server lewat skrip SQL yang dikirim lewat chat
- Target Response semua peran (tahap x urgensi, termasuk PIC) diatur Admin dalam satu tabel: bagian baru di `admin/master_data.php` dan **berkas baru** `actions/sla_respon_target.php`. Nilai awal (migrasi 028, usulan): CMO verifikasi 30 menit/2 jam/4 jam, Kadep 1 jam/4 jam/1 hari, PIC 1 jam/2 jam/4 jam, UAT dan finalisasi kosong
- Dashboard Admin: panel Response Time kini gabungan semua peran (satu angka, dihitung tiap kali CRF menunggu satu peran), Resolution tetap per CRF; tabel per kategori dan per urgensi, PDF, dan Excel mengikuti. `getAdminCrfRespCombined()` di `includes/admin_crf_report.php`; `admin/dashboard.php`, `actions/export_admin_report.php`. Daftar "CRF yang Melewati SLA" dan kolom per CRF tetap memakai Response PIC (dilabeli Response PIC)
- `assets/css/style.css` (gaya Resolution baru, keterangan Riwayat, `.admin-sla-kpis--v2`)
- Sengaja tidak dibuat (disederhanakan): daftar Response per tahap di panel detail, rincian per peran di dashboard, tabel dan lembar Excel per tahap
- `CLAUDE.md`, `database/README.md`
- Unggah bersamaan: `includes/functions.php`, `includes/sla_alerts.php`, `includes/admin_crf_report.php`, `includes/partials/informasi_sla.php`, `includes/partials/timeline.php`, `includes/partials/crf_row_status.php`, `otomasi/index.php`, `admin/dashboard.php`, `admin/master_data.php`, `actions/category_master.php`, `actions/kadep_operasional_approve.php`, `actions/sla_respon_target.php` (baru), `actions/export_admin_report.php`, `assets/js/script.js`, `assets/css/style.css`. Jalankan migrasi 028 lebih dulu. Tanpa migrasi, fitur diam dan halaman tetap normal.

---

## 2026-11-06

- `includes/whatsapp.php` (templat pesan WA minimalis: judul tebal, nomor CRF di baris sendiri, satu kalimat inti, baris Pemohon dan Kategori·Urgensi untuk penerima selain pemohon termasuk PIC, tautan tanpa kata "Buka:", tanpa tanda tangan perusahaan; kalimat inti maks. 200 karakter)

---

## 2026-11-05

- `includes/forum.php` (`forumResultRoles()` jadi CMO saja: hanya CMO yang bisa mengubah Level Urgensi dan SLA lewat hasil Forum)
- `includes/forum_discussions.php`, `cmo/detail.php`, `actions/cmo_action.php`, `actions/forum_discussion.php`, `includes/partials/timeline.php` (teks "CMO atau Admin" jadi "CMO")
- `assets/js/forum.js` (Forum memuat komentar baru otomatis tiap 8 detik tanpa refresh manual)
- `includes/forum_discussions.php` (balasan otomatis "Asisten CRF": `forumMaybeAutoReply()`, 3 template)
- `forum/index.php` (memanggil `forumMaybeAutoReply()`)

---

## 2026-10-28

- `actions/automation_action.php`
- `actions/automation_start.php` (baru)
- `actions/category_master.php`
- `actions/export_admin_report.php`
- `actions/kadep_operasional_approve.php`
- `admin/dashboard.php`
- `admin/master_data.php`
- `assets/css/style.css`
- `assets/js/script.js`
- `cmo/index.php`
- `config/sla.php`
- `database/migrations/026_sla_respon_migration.sql` (baru, jalankan)
- `database/schema.sql`
- `includes/admin_crf_report.php`
- `includes/admin_crf_xlsx.php`
- `includes/crf_nav.php`
- `includes/functions.php`
- `includes/header.php`
- `includes/partials/crf_list_filters.php`
- `includes/partials/crf_row_status.php`
- `includes/partials/detail_permintaan.php`
- `includes/partials/handler_assignment.php`
- `includes/partials/informasi_sla.php`
- `includes/sla_alerts.php`
- `kadep_operasional/index.php`
- `otomasi/detail.php`
- `otomasi/index.php`

---

## 2026-11-02

Uji integrasi hibrida di SIAP (CRF di-include ke layout SIAP lewat `pages/crf/*.php`):
`<html>` SIAP memakai font-size 10px (Bootstrap 3), sehingga seluruh satuan `rem` di CSS CRF
tampil ±62% (kecil dan gepeng). Semua `rem` diganti `calc(X * var(--crf-rem))`; `--crf-rem` = `1rem`
(standalone, tampilan tidak berubah) dan `16px` pada `.crf-module--embedded` (mode module).

- `assets/css/style.css`
- `otomasi/index.php` (satu nilai `rem` pada `<style>` inline)

### Tahap 1 hibrida: URL dan redirect sadar mode (alur CMO dan Otomasi)

Di dalam layout SIAP, link relatif (`detail.php`, `../actions/...`, `?stage=...`) dan `header('Location')`
tidak bekerja (halaman berjalan dari `/index.php`, header sudah tercetak). Semua link, form, dan redirect
alur CMO + Otomasi dialihkan ke helper baru `includes/crf_url.php`: `crfUrl()`, `crfActionUrl()`,
`crfSelfUrl()`, `crfRouteFields()`, `crfRedirect()`. `crfLayoutIsModule()` pindah ke file ini.
Mode standalone: hasil URL setara dengan sebelumnya. Mode module: `/index.php?mod=crf&submod=<submod>`.

- `includes/crf_url.php` (baru)
- `includes/crf_nav.php` (memuat crf_url.php, `crfLayoutIsModule()` dipindah)
- `includes/auth.php` (redirect login dan role lewat `crfRedirect`)
- `includes/partials/crf_list_filters.php` (input mod/submod pada form GET, tombol Reset)
- `includes/partials/category_members.php`
- `includes/partials/detail_header.php`
- `includes/partials/detail_permintaan.php`
- `includes/partials/forum_button.php`
- `includes/partials/uat_upload_form.php`
- `cmo/index.php`, `cmo/detail.php`
- `otomasi/index.php`, `otomasi/detail.php`, `otomasi/view_detail.php`
- `actions/cmo_action.php`, `actions/automation_action.php`, `actions/automation_start.php`
- `actions/kadep_operasional_approve.php`, `actions/uat_upload.php`

`includes/session.php` sengaja tidak disentuh: versi server memuat jembatan sesi SIAP yang tidak ada di lokal.

### Tahap 2 hibrida: sisa halaman dan actions

Pola yang sama diterapkan ke seluruh halaman dan actions yang masih memakai URL relatif.
Helper tambahan di `includes/crf_url.php`: `crfDirectUrl()` (file langsung, mis. potongan HTML
forum `partial=1`) dan `crfUrlFromPath()` (path tersimpan di notifikasi, mis.
`forum/index.php?crf_id=5#comment-9`, diubah sesuai mode; tautan email di mode module memakai
skema+host `app_url` ditambah `/index.php?mod=crf&submod=...`). Rute baru: `crf.open`, `notifications.open`.
Form GET (filter, pencarian, rentang tanggal Admin) membawa input hidden `mod`/`submod`
lewat `crfRouteFields()`; tautan `?x=y` diganti `crfSelfUrl()`.

- `kadep_operasional/index.php`, `kadep_operasional/detail.php`
- `admin/dashboard.php`, `admin/detail.php`, `admin/edit.php`, `admin/master_data.php`
- `user/dashboard.php`, `user/detail.php`, `user/form_crf.php`, `user/pengajuan_saya.php`
- `crf/handling.php`, `crf/open.php`
- `forum/index.php`, `forum/detail.php`
- `notifications/index.php`, `notifications/open.php`
- `includes/forum.php`, `includes/notifications.php`, `includes/crf_url.php`
- `actions/admin_cancel_crf.php`, `actions/category_master.php`, `actions/delete_draft.php`
- `actions/forum_comment.php`, `actions/forum_discussion.php`, `actions/save_draft.php`
- `actions/submit_crf.php`, `actions/submit_pir.php`

Sengaja tidak diubah: `actions/login.php`, `actions/logout.php`, `login.php`, `logout.php`, `index.php`
(login milik SIAP pada mode module), serta `includes/session.php`.

### Kompatibilitas Bootstrap 3 (SIAP) di mode module

Halaman Kategori CRF tampil kosong di SIAP: tab-pane CRF berkelas `fade show active` (Bootstrap 5),
sedangkan CSS Bootstrap 3 milik SIAP memberi `.fade { opacity: 0 }` dan baru menampilkannya dengan `.in`.
Blok CSS kompatibilitas ditambahkan di bawah `.crf-module--embedded` (`.fade.show`, `.collapse.show`,
`.modal.show`, backdrop modal, dan offcanvas untuk panel detail Forum). `includes/footer.php` kini selalu
memuat JS Bootstrap 5 di mode module dengan `data-bs-no-jquery`, agar tab/modal/offcanvas CRF (`data-bs-*`)
berjalan tanpa menimpa plugin jQuery Bootstrap 3 milik SIAP.

- `assets/css/style.css`
- `includes/footer.php`

Teks tombol dan isian tampil ±9px di SIAP: CRF mengandalkan CSS Bootstrap 5 untuk ukuran huruf `.btn`, `.btn-sm`,
`.form-control`, `.form-select` (tidak dimuat di SIAP, yang memakai Bootstrap 3). Di `.crf-module--embedded`
ukuran huruf kontrol dikunci ke 16px (14px untuk varian `-sm`) lewat `--crf-rem`. Aturan komponen CRF yang
lebih spesifik/di bawahnya tetap berlaku. CSS CRF sendiri tidak bocor ke sidebar SIAP (semua aturan global
CRF memakai awalan `.crf-module`).

### Bootstrap 5 dibatasi di .crf-module (mode module)

Penyebab header/sidebar SIAP berubah di halaman CRF (tautan bergaris bawah, font jadi system-ui, garis
"-" dari tautan kosong bergaris bawah): `CRF_LOAD_BOOTSTRAP = true` di server membuat `includes/header.php`
memuat CSS Bootstrap 5 global dari CDN, dan reboot-nya (`a { text-decoration: underline }`, `body { font-family }`)
mengenai seluruh halaman SIAP (Bootstrap 3). Sekarang mode module memuat `assets/css/bootstrap-scoped.css`:
Bootstrap 5.3.3 dengan tiap selektor berawalan `.crf-module`, `:root/html/body` menjadi `.crf-module`, dan
satuan `rem` diganti `calc(X * var(--crf-rem))`. Opsi `CRF_LOAD_BOOTSTRAP` tidak lagi dipakai di mode module.
Mode standalone tidak berubah (tetap Bootstrap CDN global).

- `assets/css/bootstrap-scoped.css` (baru, hasil build; jangan diedit manual)
- `includes/header.php`

Koreksi: awalan `.crf-module` menaikkan spesifisitas aturan Bootstrap (0,1,0 jadi 0,2,0) sehingga `.btn` Bootstrap
mengalahkan `.btn-crf-primary`/`.btn-crf-outline` (tombol tampil polos tanpa warna). Build ulang memakai awalan
`:where(.crf-module)` (spesifisitas 0) sehingga aturan CRF satu-kelas tetap menimpa seperti saat Bootstrap global.
Dashboard di mode module (area konten SIAP lebih sempit dari standalone): tiga kartu laporan (Tren, Status,
Urgensi) tetap satu baris pada layar >= 1200px (2 kolom pada 901-1199px); grafik batang `minmax(24px, 1fr)` dengan
label bulan lebih kecil agar 11-12 bulan satu baris; donut 92px dan legenda dirapatkan agar tidak saling tumpuk.
Berkas: `assets/css/bootstrap-scoped.css`, `assets/css/style.css`.

Dua perbaikan lagi di mode module (`assets/css/style.css`):
- Panel detail Forum (offcanvas) menutup layar hitam: backdrop ditempel Bootstrap 5 di dalam `.crf-module`, sehingga
  terkena `.crf-module--embedded .fade.show { opacity: 1 }`. Ditambah aturan `.offcanvas-backdrop.fade.show` dan
  `.modal-backdrop.fade.show` opacity 0.5 yang lebih spesifik.
- Filter Pengajuan Saya punya kolom kosong di awal: "clearfix" Bootstrap 3 (`.row::before { display: table; content: " " }`)
  menjadi item grid/flex. Pseudo-element `::before/::after` pada `.row`, `.nav`, `.btn-toolbar`, `.modal-header`,
  `.modal-footer` dimatikan di `.crf-module--embedded`.

Perbaikan HTTP 500 pada `actions/save_draft.php` (dan `delete_draft.php`): action itu hanya memuat `session.php` +
`functions.php`, sehingga `crfRedirect()`/`crfUrl()` tidak ada (fatal saat redirect setelah simpan).
`includes/functions.php` kini memuat `includes/crf_url.php`. Berkas: `includes/functions.php`.

Informasi SLA sebelum persetujuan Kadep (SLA belum dimulai): panel Response Time dan Resolution Time tidak lagi
ditampilkan (isinya kosong dan target belum final karena level urgensi baru ditetapkan di Forum). Diganti keterangan
"SLA dimulai setelah persetujuan Kadep..." plus baris "Target saat disetujui: Response Time X · Resolution Time Y"
(perkiraan dari matriks kategori x level urgensi, dipakai Kadep yang memeriksa SLA sebelum menyetujui). Rincian waktu
disembunyikan; CRF dibatalkan sebelum mulai menampilkan "CRF dibatalkan sebelum SLA dimulai." Setelah SLA mulai,
tampilan tidak berubah. Berkas: `includes/partials/informasi_sla.php` (dipakai halaman Admin, CMO, Kadep, Otomasi, Forum).

Panel detail Forum (offcanvas) tidak bisa di-scroll: CSS Bootstrap 3 SIAP punya `.show { display: block !important }`
yang mengubah offcanvas (flex kolom) menjadi block, sehingga `.offcanvas-body` tidak dibatasi tinggi panel (tab Permintaan
764px dalam panel 534px). Ditambah `.crf-module--embedded .offcanvas.show/.showing/.hiding { display: flex !important }`.
Berkas: `assets/css/style.css`.

Mode module: CSS milik SIAP (`style.css`, `themes.css`) masih bocor ke komponen CRF. Hasil pengukuran di 7 halaman
SIAP (Form CRF, Pengajuan Saya, detail pengajuan, Forum, Dashboard, Kategori, Tindak Lanjut Otomasi) menemukan: judul
h1-h5 jadi "Open Sans" (SIAP: `h1,h2,h3,h4,h5`), teks semua tombol jadi #333 (`.theme-blue .btn`), input/textarea
dipaksa `border-radius:0 !important` + latar #f9f9f9, ukuran huruf `.btn` 16px menimpa ukuran khusus tombol halaman.
Perbaikan di `assets/css/style.css`: ukuran huruf kontrol memakai `:where(.crf-module--embedded)` (spesifisitas rendah,
kembali ke ukuran khusus halaman), font judul sengaja dibiarkan ikut SIAP (Open Sans), `.form-control` radius 7px/4px (sm) + latar putih
(`!important` untuk melawan SIAP), checkbox/radio dikembalikan, warna teks `btn-crf-primary`/`btn-crf-outline`/
`pengajuan-create-button` dan varian Bootstrap (`btn-outline-danger`, dst) dikembalikan lewat variabel. Diuji dengan
menyuntikkan aturan yang sama ke halaman SIAP (hanya di browser): Pengajuan Saya, Kategori sesuai lokal.
Label pilihan Dampak dan Biaya Anggaran (Form CRF) tebal karena Bootstrap 3 SIAP: `label{font-weight:700;margin-bottom:5px}`;
dikembalikan lewat `:where(.crf-module--embedded) label{font-weight:inherit;margin-bottom:0;max-width:none}`
(label berkelas seperti `.form-label` tetap 650). Berkas: `assets/css/style.css`.
Belum diuji di server setelah diunggah. Halaman peran CMO/Kadep tidak bisa diukur (akun tanpa akses).

Diuji di halaman tiruan (CSS Bootstrap 3 SIAP asli + CSS CRF): tautan header/sidebar SIAP tanpa garis bawah dan
tetap Open Sans 13px, tombol CRF 16px/14px, `gap-2` = 8px, tab-pane terlihat. Belum diuji di server.

### Email CRF memakai pengaturan SMTP SIAP

`includes/notifications.php`: `crfMailConfig($pdo)` kini, bila `config/mail.php` tidak ada/tidak aktif dan mode layout
`module`, membaca pengaturan SMTP SIAP dari `tbl_email_settings` (id = 1: smtp_host/port/username/password/encryption,
from_email, from_name; diisi lewat menu SIAP Email > Pengaturan Email, sama dengan `3rdparty/email_helper.php`).
Fungsi baru `crfSiapMailConfig()`. Tanpa enkripsi, `SMTPAutoTLS` dimatikan seperti helper SIAP. Pengiriman tetap lewat
PHPMailer milik CRF dan templat email CRF (bukan `kirimEmailOtomatis()`), jadi tidak perlu SMTP terpisah.
`config/mail.php` yang aktif tetap diutamakan. Berkas: `includes/notifications.php`.

Berkas konteks `CLAUDE.md` (baru, di akar project): konteks lengkap sistem CRF (peta berkas, peran dan akses, alur, data,
urgensi dan SLA, Forum, kategori dan PIC, notifikasi, lampiran, keamanan) dan CRF di server SIAP (pola hibrida
`pages/crf`, mode layout dan URL, menu dan hak akses SIAP, bentrok Bootstrap, email/WA/cron, jebakan yang pernah terjadi),
pengembangan lokal, aturan kerja, dan status tertunda. Hanya dokumentasi, tidak mengubah perilaku aplikasi; tidak perlu
diunggah ke server.

Email ke Pemohon saat hasil pembahasan Forum dicatat: pemohon tidak punya akses Forum dan sebelumnya tidak diberi tahu
hasilnya (penerima "Hasil pembahasan Forum" hanya CMO pengaju dan pemberi komentar). `forumRecordResult()` kini juga
mengirim notifikasi (in-app + email) ke Pemohon CRF: judul "Level urgensi dan SLA ditetapkan: nomor", isi hasil
(level urgensi + SLA) dan, bila langsung diteruskan, "CRF diteruskan ke Kepala Departemen Operasional untuk persetujuan".
Tanpa isi diskusi/komentar; tanpa WhatsApp. Berkas: `includes/forum_discussions.php`. Belum diuji.

Keterangan CRF di luar antrean: tautan "Buka CRF" (email/notifikasi) membuka halaman detail sesuai peran penerima; bila CRF
tidak ada di antrean peran itu, dulu muncul kotak merah "CRF tidak ditemukan pada antrean ...". Kini tampil keterangan
(kotak info, dua baris): "Belum sampai di antrean X" atau "Sudah ditindaklanjuti oleh <nama> · <tanggal>" (dari
`crf_log_aktivitas`; bila pelaksananya tidak ditemukan: "Sudah tidak berada di antrean X"), lalu "Saat ini: <tahap>"
(CRF selesai/dibatalkan menampilkan statusnya); tombol Kembali, dan "Buka detail CRF" untuk Admin. CRF Draft / tanpa akses tetap "tidak ditemukan atau
Anda tidak memiliki akses". Berkas baru: `includes/crf_queue_notice.php` (`crfQueueNoticeHtml()`); dipakai di
`cmo/detail.php`, `kadep_operasional/detail.php`, `otomasi/detail.php`, `otomasi/view_detail.php` (judul halaman
"Keterangan CRF"). Diuji di lokal (akun Otomasi): CRF belum sampai (tahap Verifikasi CMO) dan CRF sudah lewat
(Finalisasi CMO, dilakukan Fikri Raihan); halaman CMO/Kadep belum diuji karena akun lokal berperan Otomasi.

Templat email CRF dirombak lagi (menggantikan versi di bawah): logo + nama perusahaan di atas, kepala berwarna datar
dengan label "NOTIFIKASI CRF" dan judul besar, kartu ringkasan CRF (nomor register + pill status, pemohon, unit asal,
kategori, urgensi, diajukan, ringkasan 140 huruf), tombol "Buka CRF" + tautan cadangan, kaki. Warna aksen menurut jenis
notifikasi (dibaca dari judul, `crfEmailTone()`): biru (CRF baru/dikirim ulang/lainnya), oranye (revisi, mendesak,
pengingat), hijau (selesai, disetujui, finalisasi), merah (dibatalkan, terlewati). Kartu hanya muncul bila notifikasi
punya `pengajuan_id` (data dari `crfEmailCrfData()`, `crf_pengajuan`). Berkas: `includes/notifications.php`
(`crfEmailHtml`, `sendNotificationEmail` menerima parameter `$crf`).

Tampilan email CRF (`crfEmailHtml()`) disamakan dengan email SIAP (`3rdparty/email_helper.php`): latar #f4f5fb, kartu
520px bersudut 12px, logo di atas (gambar tersemat cid, tinggi 42px), kepala gradasi biru "SIAP — PT. Persona Prima
Utama", lingkaran ikon, judul, sapaan "Dear <nama>", tombol biru #2f7fe0 "Buka CRF" + tautan cadangan, kaki biru muda
"Email ini dikirim otomatis oleh sistem...". Isi/judul/tautan notifikasi tidak berubah.

### Pilihan Dampak di Form CRF: 4 pertanyaan bahasa awam, terkait Tipe Pengajuan

Kotak centang 11 pernyataan dampak diganti 4 pertanyaan, masing-masing satu jawaban (pilihan bulat):
1 keadaan aplikasi/pekerjaan sekarang (a-d), 2 adakah cara lain (a-c), 3 bisa rugi uang/melanggar hukum? (ya/tidak),
4 bisa membuat pekerjaan salah? (ya/tidak). Jawaban dipetakan ke kunci dampak yang sudah ada
(`crfDampakDariJawaban()`), sehingga penyimpanan `dampak_dipilih`, bobot, skor, level, SLA, Forum, laporan, ekspor,
PDF tidak berubah dan tidak ada migrasi. Pasangan `bertentangan` tidak mungkin lagi muncul dari form (tetap dicek
di server untuk formulir lama). "Tidak perlu cara lain" (2c) hanya boleh bila pertanyaan 1 = c atau d.
Teks 11 pernyataan di `crfImpactGroups()` ikut diganti ke bahasa awam (dipakai detail CRF, PDF, laporan).

Keterkaitan dengan Tipe Pengajuan: judul pertanyaan 1 menyesuaikan tipe (Problem / Permohonan Perubahan /
Permohonan Tambahan); untuk Permohonan Tambahan pilihan 1a "tidak bisa dibuka atau dipakai" disembunyikan (diturunkan
dari `tolak` di `config/urgensi.php`, `crfDampakOpsiTersembunyi()`), jawaban yang tidak berlaku dikosongkan saat tipe
diganti dan server tetap menolak bila dipaksa. Penanda CMO "perlu dicek": Problem dengan semua jawaban ringan
(`tandai_hanya` diperluas). Isi ulang draft/revisi: `crfJawabanDariDampak()`; catatan: jawaban "tidak" pada
pertanyaan 3/4 yang berpasangan dengan "ya" tidak tersimpan sebagai kunci, jadi dikosongkan dan perlu dijawab ulang.
Draft boleh sebagian; kirim CRF mewajibkan keempat pertanyaan. Formulir lama (`dampak_dipilih[]`) tetap diterima.
Berkas: `includes/functions.php` (teks, `crfDampakQuestions` dan fungsi terkait, `crfDampakValidasi`),
`config/urgensi.php` (`tandai_hanya`), `user/form_crf.php`, `assets/js/script.js`, `assets/css/style.css`,
`actions/submit_crf.php`, `actions/save_draft.php`, `tools/uji_urgensi.php` (66 pemeriksaan lulus).
Belum diuji di browser lokal/server. Teks sebaiknya disetujui CMO/atasan.

Perbaikan anomali hasil audit semua kombinasi jawaban: (1) bila jawaban pertanyaan 1 = d (pekerjaan masih berjalan
normal), pertanyaan 2 tidak ditanyakan (disembunyikan, dijawab otomatis "tidak perlu cara lain"; nomor pertanyaan
menyesuaikan lewat CSS counter). Sebelumnya "pekerjaan normal" + "tidak ada cara lain" + "bisa salah" menghasilkan
Tinggi. Server mengabaikan jawaban 2 bila 1 = d; isi ulang draft lama dengan kombinasi itu menjadi 2 = c.
(2) Penanda "perlu dicek" CMO untuk Permohonan Tambahan hanya muncul bila level Tinggi tetap tercapai tanpa jalur
risiko uang/hukum (`tandai_tinggi_tanpa` di `config/urgensi.php`, dipakai `crfDampakCatatan()`); sebelumnya 22 dari 32
kombinasi diberi catatan. Berkas tambahan: `assets/js/script.js` (`sesuaikanPertanyaan2`), `assets/css/style.css`
(counter nomor), `user/form_crf.php`. `tools/uji_urgensi.php`: 74 pemeriksaan lulus.

Teks pertanyaan dampak disederhanakan (versi final): semua memakai kerangka "bila CRF ini tidak dikerjakan" dan satu
subjek "pekerjaan Anda"; pertanyaan 1 bukan lagi tentang "aplikasi" (cocok untuk kategori Infrastruktur/Proses/
Security). Pertanyaan 2 menjadi 2 pilihan (Tidak ada / Ada, walau lebih lama atau repot); pilihan "sama mudahnya"
dihapus karena hampir tidak pernah tampil dan membingungkan. Kunci `low_no_efficiency_impact` kini otomatis ditambahkan
bila jawaban 1 = d (pekerjaan tetap berjalan). Pertanyaan 3 diberi contoh (denda, kehilangan pendapatan, melanggar
kontrak); pertanyaan 4 "hampir pasti jadi salah atau harus diulang". Kolom teks di bawahnya berlabel "Jelaskan dampaknya
dengan kata-kata Anda" (galat: "Penjelasan dampak wajib diisi."), hint Saran Alternatif dibedakan dari "cara lain".
Pagar Permohonan Tambahan (atas permintaan pemilik; bisa dicabut dengan menghapus `tinggi_hanya_dengan` di
`config/urgensi.php`): fitur baru hanya bisa berlevel Tinggi bila pertanyaan 3 = Ya (risiko uang/hukum/kontrak); selain
itu dibatasi Normal walau skor mencapai ambang Tinggi. CMO dapat catatan "dibatasi Normal ... naikkan lewat Forum bila
memang mendesak". `crfHitungUrgensi()` menerima parameter ketiga `$tipe` (hasil mengandung `dibatasi`); dipakai
`submit_crf.php`, `save_draft.php`, `detail_permintaan.php`, `crfUrgensiSistem()` (CRF lama membaca `jenis_pengajuan`).
Skor tersimpan tetap skor mentah, jadi meter skor bisa menunjukkan skor di atas ambang Tinggi dengan level Normal untuk
Permohonan Tambahan. CRF Tambahan yang sudah diajukan memakai level tersimpan (SLA tidak berubah); hanya "level sistem"
yang dihitung ulang. `tools/uji_urgensi.php`: 87 pemeriksaan lulus.

Bobot urgensi disetel kecil (usulan B, atas permintaan pemilik; belum disetujui CMO, bisa dikembalikan lewat
`config/urgensi.php`): `high_no_alternative` 25 -> 20, `normal_temporary_workaround` 10 -> 5 (menghindari hitung ganda
"melambat" dengan pertanyaan 1), `normal_operational_risk` 15 -> 10 (pertanyaan 4 terlalu mudah dijawab Ya), ambang
Tinggi 35 -> 40. Kunci Tinggi (`wajib_tinggi`: tidak bisa berjalan, rugi uang/hukum) tetap. Dari 28 kombinasi jawaban
(tipe Problem): Tinggi 24 -> 21, Normal 3 -> 6, Rendah 1. Contoh yang turun ke Normal: tersendat + ada cara lain + pekerjaan
salah; sebagian tidak bisa dipakai + tidak ada cara lain. `tools/uji_urgensi.php` disesuaikan (77 pemeriksaan lulus).
Pengukuran data nyata: bandingkan `level` dan `level_urgensi_final` di `crf_pengajuan` sebelum dan sesudah.
Jawaban pertanyaan 2 dipersingkat: "Tidak ada" dan "Ada (walau lebih lama atau repot)" (kunci dan skor tetap).
Pertanyaan 3 dan 4 memakai usulan awal (atas permintaan pemilik): 3 "Bisa membuat perusahaan rugi uang, atau melanggar
hukum dan perjanjian?" (contoh: denda, kehilangan pendapatan, atau melanggar kontrak); 4 "Bisa membuat pekerjaan jadi
salah atau berantakan?" (contoh: salah hitung, data tidak cocok, atau harus input dua kali). Kunci dan skor tetap. Teks 11 pernyataan di detail/PDF/laporan ikut disamakan. CRF/draft versi 3 pilihan (q2 = c) terbaca sebagai q2 kosong
dan dijawab ulang. Hasil level kombinasi tertentu bergeser sedikit: sebagian bagian tidak bisa dipakai + "Ada cara lain"
+ pekerjaan salah = skor 35 (Tinggi). Berkas: `includes/functions.php`, `user/form_crf.php`, `actions/submit_crf.php`,
`assets/js/script.js`, `assets/css/style.css`, `tools/uji_urgensi.php` (77 pemeriksaan lulus).

**Tipe Problem dikeluarkan dari form CRF baru (paling baru).** Problem kini diproses di Helpdesk SIAP: formulir Helpdesk
(kategori "Aplikasi SIAP") mengalihkan Permohonan Tambahan/Perubahan ke `index.php?mod=crf&submod=form_crf&tipe=<nama tipe>`.
Di CRF: `crfRequestTypeOptionsBaru()` (Perubahan dan Tambahan saja) dipakai untuk dropdown dan kunci `?tipe=` di
`user/form_crf.php`; `crfRequestTypeAllowedForSave()` dipakai `actions/submit_crf.php` dan `actions/save_draft.php`: Problem
ditolak untuk pengajuan baru (pesan "Problem diproses lewat Helpdesk SIAP..."), tetapi tetap diterima bila CRF yang diedit
(Draft/Perlu Revisi) memang sudah bertipe Problem. `crfRequestTypeOptions()` tetap memuat Problem untuk filter, detail,
PDF, dan laporan; data dan level CRF Problem lama tidak berubah. Pertanyaan dampak belum disederhanakan (tahap 2, setelah
tidak ada draft Problem terbuka). `tools/uji_urgensi.php`: 118 pemeriksaan lulus. Belum diuji di browser. Berkas:
`includes/functions.php`, `user/form_crf.php`, `actions/submit_crf.php`, `actions/save_draft.php`, `tools/uji_urgensi.php`.

**Penyederhanaan alur q3 dan penentu level.** Audit semua kombinasi menemukan: q1 "sebagian terhambat" + q3
"tidak mempengaruhi efisiensi" bertentangan (opsi q3 c tidak pernah sah); q3 hanya satu pilihan bila q2 = "tidak
menghentikan operasional"; meter skor jumlah menyesatkan karena level kini = butir tertinggi. Perubahan: q3 hanya
ditanyakan bila q1 = berhenti total atau q2 = terganggu berat; q3 punya 2 pilihan ("Tanpa ada solusi alternatif",
"Ada solusi alternatif sementara, tetapi merepotkan"), opsi c dihapus (kunci `low_no_efficiency_impact` tetap otomatis bila
q1 = c); jawaban lama tidak berlaku dikosongkan (`crfDampakOpsiTampil` menolak kode yang tak ada). Detail CRF: meter
"skor / 100" dan "+bobot" diganti baris "Penentu level" (jawaban dengan bobot tertinggi); `skor_urgensi` tetap disimpan.
CRF lama/dengan jawaban q3 yang kini tak berlaku tampil sebagai daftar pernyataan. `tools/uji_urgensi.php`: 116
pemeriksaan lulus. Belum diuji di browser. Berkas: `includes/functions.php`, `assets/js/script.js`,
`includes/partials/detail_permintaan.php`, `tools/uji_urgensi.php`.

**Pertanyaan dampak mengikuti tabel resmi "Keterangan Level Urgensi" (menggantikan versi
cakupan di bawah dan versi 4 pertanyaan sebelumnya).** Tabel resmi (3 level, 11 butir) dijadikan patokan: tiap pilihan
jawaban = satu butir tabel dengan kalimat tabel, dipetakan ke satu kunci dampak yang sudah ada; teks 11 pernyataan di
`crfImpactGroups()` sama persis dengan tabel (diuji). Pertanyaan: 1 keadaan fungsi aplikasi/layanan (berhenti total /
hanya bisa diakses sebagian / berfungsi normal), 2 dampak pada operasional harian (terganggu berat / tidak menghentikan),
3 solusi alternatif (tanpa solusi / ada tetapi merepotkan / tidak mempengaruhi efisiensi kerja), 4 risiko (likuiditas dan
hukum / operasional / tidak ada). Pertanyaan cakupan dihapus karena tidak ada di tabel resmi. Butir Rendah "Masalah atau
permintaan fitur baru" ditentukan Tipe Pengajuan (kunci ditambahkan untuk selain Problem bila pekerjaan normal).
Alur (`crfDampakPertanyaanTampil`, `crfDampakOpsiTampil`, `crfDampakNormalisasi`; JS `script.js` mengikuti):
q1 = berfungsi normal: q2 dan q3 tidak ditanyakan dan kunci "tidak menghentikan operasional harian" + "tidak
mempengaruhi efisiensi kerja" ditambahkan otomatis (menghilangkan kombinasi janggal "normal tetapi tanpa solusi
alternatif = Tinggi"); q1 = berhenti total: q2 dilewati, q3 hanya "tanpa solusi" / "merepotkan"; q3 "tidak mempengaruhi
efisiensi" hanya bila operasional tidak terganggu berat. Per tipe: Perubahan dan Tambahan tanpa "berhenti total"
(`tolak`); judul q1 dan teks pilihan q1 berbeda per tipe ("Bila hal baru ini tidak ada, apakah pekerjaan Anda
terhambat?", "Sebagian pekerjaan terhambat", dst.); Tambahan tetap dengan pagar "Tinggi hanya bila risiko likuiditas
dan hukum". Bobot "tertinggi menang": butir kolom Tinggi 50, Normal 10, Rendah 0, ambang Tinggi 50 / Normal 10; satu butir
Tinggi sudah Tinggi, satu butir Normal sudah Normal, empat butir Normal sekaligus (40) tetap Normal. Pilihan q3
"Tanpa ada solusi alternatif" disembunyikan bila q2 = "tidak menghentikan operasional harian" (diganti oleh
penyederhanaan alur q3 di bawah). Tanpa catatan CMO untuk banyak butir Normal (sengaja). Sebelumnya butir "solusi sementara yang merepotkan" bernilai 5 (di bawah ambang Normal) dan
"tanpa solusi alternatif" 20: tidak sesuai kolom tabel. Sebaran (kombinasi dianggap sama sering): Problem 17 Tinggi / 6
Normal / 1 Rendah dari 24; Perubahan 11/6/1 dari 18; Tambahan 6/11/1 dari 18 (5 dibatasi pagar). Detail dan PDF
menampilkan pertanyaan yang ditanyakan saja (`crfDampakJawabanTampil`); CRF lama dan versi sebelumnya tetap daftar
pernyataan. `tandai_hanya` Problem kembali tanpa kunci cakupan; kunci `scope_*` dihapus. `tools/uji_urgensi.php`: 115
pemeriksaan lulus. Belum diuji di browser lokal/server. Berkas: `includes/functions.php`, `config/urgensi.php`,
`user/form_crf.php`, `assets/js/script.js`, `tools/uji_urgensi.php` (+ berkas detail/PDF/CSS dari catatan di bawah).

**Pertanyaan dampak per Tipe Pengajuan + cakupan terdampak (DIGANTI oleh perbaruan di atas; disimpan sebagai riwayat).** Atas permintaan
pemilik: faktor baru hanya cakupan terdampak, tetap 4 pertanyaan per tipe. Pertanyaan 4 lama ("pekerjaan jadi salah atau
berantakan", terlalu mudah dijawab Ya dan tumpang tindih dengan pertanyaan 1/3) diganti **"Siapa yang terkena masalah
ini?"** (Problem) / "Siapa yang akan terdampak oleh perubahan ini?" (Permohonan Perubahan) / "Siapa yang akan memakai hal
baru ini?" (Permohonan Tambahan), satu jawaban dari 4: satu orang, satu departemen/divisi, beberapa departemen, seluruh
perusahaan atau klien. Pertanyaan 1-3 dan kalimatnya tidak berubah. Kunci dampak baru `scope_individual` (0),
`scope_department` (5), `scope_multi_department` (10), `scope_company_client` (15), grup "Cakupan" di
`crfImpactGroups()`, bobot di `config/urgensi.php`; disimpan di `dampak_dipilih` seperti kunci lain, tanpa migrasi.
Cakupan menaikkan gangguan yang sudah ada dan tidak pernah membuat Tinggi sendirian (maks 15). Kunci lama
`normal_operational_risk` dan `low_no_risk` tetap valid untuk CRF lama tetapi tidak lagi dihasilkan form baru.
Permohonan Perubahan sekarang juga menyembunyikan pilihan 1a "pekerjaan tidak bisa berjalan sama sekali" (`tolak` di
config, karena yang diubah sudah berjalan); Permohonan Tambahan tetap dengan pagar "Tinggi hanya bila pertanyaan 3 = Ya".
`tandai_hanya` Problem diperluas dengan kunci cakupan (Problem yang pekerjaannya tetap berjalan ditandai untuk CMO
apa pun cakupannya). Isi ulang draft/revisi (`crfJawabanDariDampak`): jawaban "Tidak" pada pertanyaan 3 kini
tersimpan lewat adanya kunci cakupan (tidak lagi hilang), dan hanya disimpulkan bila jawaban lain lengkap. CRF versi
4 pertanyaan sebelumnya (tanpa cakupan) dan CRF centang lama tetap tampil sebagai daftar pernyataan di detail.
Sebaran (kombinasi dianggap sama sering): Problem 43 Tinggi / 11 Normal / 2 Rendah dari 56; Perubahan 27/12/1 dari 40;
Tambahan 20/19/1 dari 40 (7 kombinasi dibatasi pagar). Sebaran masih condong Tinggi karena dua kunci (tidak bisa
berjalan; rugi uang/hukum): bobot dan ambang bisa disetel di `config/urgensi.php`.

Detail CRF dan PDF kini menampilkan dampak sebagai pertanyaan dan jawaban (sama dengan form), bukan daftar pernyataan:
`crfDampakJawabanTampil()` menyusun 4 baris (baris pertanyaan 2 dilewati bila pekerjaan tetap berjalan) dari kunci
tersimpan; bobot per jawaban + meter skor + catatan hanya untuk peran internal (jumlah bobot dibatasi maks 100 seperti
skor). Pernyataan otomatis tidak tampil sebagai jawaban pemohon; jawaban "Tidak" di pertanyaan 3 ikut tampil.
Berkas: `includes/functions.php`, `config/urgensi.php`, `user/form_crf.php`, `includes/partials/detail_permintaan.php`
(juga dipakai Forum), `actions/export_crf.php`, `assets/css/style.css` (`.crf-impact-qa`), `tools/uji_urgensi.php`
(107 pemeriksaan lulus). Belum diuji di browser lokal/server. Kalimat "Bayangkan CRF ini tidak dikerjakan." di
`user/form_crf.php` dihapus pemilik; dibiarkan. Teks dan bobot sebaiknya disetujui CMO/atasan sebelum dipakai.

### Notifikasi WhatsApp (antrean WA SIAP, default MATI)

CRF mengikuti pola Help Desk/PKS di SIAP: tidak memanggil API WhatsApp dan tidak menyimpan token, hanya
menambah baris ke `tbl_wa_send` (`kode = 'CRF'`, `status_kirim = '3'` = antre); pengirim WA SIAP yang sudah ada
mengirimnya. Alur mengikuti email: `notifyUsers(..., $whatsapp = true)` menyimpan notifikasi dengan
`crf_notifikasi.status_wa = 'pending'` (ikut transaksi), lalu setelah commit `dispatchPendingNotificationEmails()`
memanggil `dispatchPendingNotificationWhatsapp()` (`includes/whatsapp.php`). Kegagalan WA tidak menggagalkan proses.

WA dikirim untuk: CRF baru/dikirim ulang (CMO), lolos verifikasi dan Forum selesai (Kadep), disetujui Kadep
(PIC dan Pemohon), eksekusi selesai (UAT untuk CMO, isi PIR untuk Pemohon), hasil UAT (PIC), perlu revisi,
dibatalkan, selesai, pengingat PIR, finalisasi, SLA mendesak/terlambat dan response time mendesak/terlambat.
Tidak untuk komentar Forum dan pengingat SLA 50%. Nomor dari `tbl_user.no_wa`; nomor kosong dilewati; pemohon
tidak menerima teks SLA; notifikasi kembar dalam satu putaran dilewati.

Konfigurasi `config/siap.php`: `CRF_WA_ENABLED` (default false), `CRF_WA_TEST_USERIDS` (bila diisi hanya userid itu
yang dikirimi), `CRF_WA_APP_URL`, `CRF_WA_QUEUE_TABLE`. Perlu migrasi `027_wa_notifikasi_migration.sql` (jalankan);
tanpa kolom `status_wa` kode tetap aman (flag WA diabaikan).

- `includes/whatsapp.php` (baru), `database/migrations/027_wa_notifikasi_migration.sql` (baru, jalankan)
- `includes/notifications.php`, `includes/sla_alerts.php`, `includes/forum_discussions.php`, `config/siap.php`
- `actions/submit_crf.php`, `actions/cmo_action.php`, `actions/kadep_operasional_approve.php`
- `actions/automation_action.php`, `actions/submit_pir.php`, `actions/admin_cancel_crf.php`

Diuji lokal dengan tabel sementara: daftar putih, semua penerima, dan WA mati; tanpa migrasi tidak error.
Belum diuji di server dan belum dipastikan pengirim WA SIAP memproses baris `kode = 'CRF'`.

## 2026-11-05

### Badge merah antrean CRF di menu SIAP

Angka antrean (Verifikasi CMO, Tindak Lanjut Otomasi, Persetujuan Kadep, Forum belum dibaca) tampil sebagai badge
merah di menu SIAP, juga di "Change Request Form" dan "Menu Saya" (total). `actions/queue_counts.php` hanya membaca,
memakai `crfQueueCounts()` yang sama dengan menu CRF. Skrip memberi badge hanya pada tautan `mod=crf`.
Agar tampil di semua halaman SIAP (termasuk beranda), SIAP perlu satu baris di `footer.php`:
`<script src="/crf/assets/js/siap_badge_crf.js"></script>` (setelah navbar; cadangkan `footer.php` dulu).

- `actions/queue_counts.php` (baru)
- `assets/js/siap_badge_crf.js` (baru)

Diuji lokal: endpoint tanpa login 401 JSON; skrip diuji dengan menu tiruan dan respons tiruan (badge muncul di item,
induk, dan total; item lain tidak tersentuh). Belum diuji dengan login nyata dan belum di server.

Perbaikan: badge dibatasi ke navbar (`#navigation .main-nav`); sebelumnya tautan `mod=crf` di isi halaman (tab, tombol Reset, kartu ringkasan) ikut diberi badge.

### Tombol fase implementasi

Pada Tindak Lanjut Otomasi, tombol fase isi implementasi (setelah UAT lulus) diganti dari "Selesaikan" menjadi "Implementasikan". Aksi `complete` tidak berubah.

- `otomasi/detail.php`, `WORKFLOW_UPDATE.md`
