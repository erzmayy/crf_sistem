# Catatan Perubahan Pasca-Zip

Daftar file yang berubah sejak folder project dikirim (zip) ke atasan. Zip tersebut tidak menyertakan
folder `.git`, jadi file ini dipakai untuk mencocokkan versi yang sudah di-hosting dengan versi terbaru.

Cara pakai saat update ke server: salin file di daftar tanggal terkait, lalu jalankan file migrasi SQL
yang bertanda (jalankan).

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

Diuji di halaman tiruan (CSS Bootstrap 3 SIAP asli + CSS CRF): tautan header/sidebar SIAP tanpa garis bawah dan
tetap Open Sans 13px, tombol CRF 16px/14px, `gap-2` = 8px, tab-pane terlihat. Belum diuji di server.

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
