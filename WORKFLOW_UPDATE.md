# Alur Workflow CRF

```
Pemohon → CMO (screening) ─┬→ Kepala Departemen Operasional (approve; SLA mulai)
                           └→ Pembahasan Forum → hasil Tetap/Diubah (CMO atau Admin) → langsung ke Kepala Departemen Operasional
  → PIC CRF: Selesaikan (implementasi)
  → Pemohon (Post Implementation Review)
  → CMO (Finalisasi) → Selesai
```

## Tahap workflow (`change_requests.workflow_stage`)

| Tahap | Label di aplikasi | Pemegang |
|-------|-------------------|----------|
| `PEMOHON` | Menunggu Pemeriksaan | Pemohon (draft / revisi) |
| `CMO_FILTER` | Verifikasi CMO (atau **Menunggu Pembahasan Forum**) | CMO |
| `kadep_operasional` | Persetujuan Kepala Departemen Operasional | Kepala Departemen Operasional |
| `OTOMASI` | Tindak Lanjut Divisi Otomasi | PIC CRF (eksekusi) |
| `PEMOHON_PIR` | Menunggu PIR Pemohon | Pemohon |
| `CMO_FINAL` | Finalisasi CMO | CMO |
| `SELESAI` | Selesai | - |

Status (`status`): Draft, Belum Ditindak Lanjuti (Menunggu Tindakan), Perlu Revisi,
Dalam Proses (Sedang Diproses), Solve (Selesai), Cancel (Dibatalkan).

Selama pembahasan Forum terbuka, CRF di tahap `CMO_FILTER` bertanda
`forum_discussion_open = 1` dan tampil sebagai **Menunggu Pembahasan Forum**.

## Langkah per peran

1. **Pemohon** mengisi Form CRF dan mengajukannya (bisa disimpan sebagai Draft).
   Saat submit, **Level Urgensi dan SLA default terisi otomatis** dari kategori Dampak
   dan matriks Kategori × Urgensi, jadi CMO dan Forum langsung melihat nilai yang sama.
2. **CMO screening** memilih salah satu:
   - **Teruskan ke Kepala Departemen Operasional** bila Level/SLA default tidak perlu dibahas.
   - **Ajukan Pembahasan Forum** (alasan wajib) bila perlu dibahas. CRF bertanda
     *Menunggu Pembahasan Forum* dan **tidak dapat diteruskan** sampai hasil dicatat.
     Setelah CMO atau Admin mencatat hasil (Tetap atau Diubah), CRF **otomatis diteruskan ke Kepala
     Departemen Operasional** tanpa kembali ke verifikasi CMO.
   - Kembalikan untuk perbaikan atau batalkan.
3. **Kepala Departemen Operasional** menyetujui (tidak ada jalur tolak). **SLA mulai
   dihitung sejak persetujuan ini**, lalu CRF diserahkan ke PIC CRF. Persetujuan butuh
   SLA yang valid dan tidak ada pembahasan Forum yang terbuka.
4. **PIC CRF** mengeksekusi, mengisi Tanggal serta Hasil Implementasi, lalu menekan
   **Selesaikan**. PIC CRF tidak menentukan atau mengubah SLA. Yang menyelesaikan
   tercatat sebagai pemegang CRF.
5. **Pemohon** mengisi Tanggal PIR dan Post Implementation Review.
   Setelah itu CRF diteruskan ke CMO.
6. **CMO** memfinalisasi dan menandai selesai. Pada tahap ini CMO tidak dapat membatalkan CRF
   (pekerjaan sudah dilaksanakan); pembatalan hanya saat verifikasi CMO atau oleh Admin.

Daftar eksekusi PIC CRF diurutkan menurut tenggat SLA terdekat, lalu Level Urgensi
(Tinggi → Normal → Rendah). Tidak ada fitur "Ambil CRF", "Mulai Kerjakan", penugasan
PIC, maupun perpanjangan SLA.

Admin dapat memantau semua CRF dan membatalkan CRF secara administratif. Forum dipakai
CMO, PIC CRF, Kepala Departemen Operasional, dan Admin untuk berdiskusi.

## Forum: pembahasan Level Urgensi & SLA

Forum membahas apakah Level Urgensi dan SLA default **tetap** atau **perlu diubah**.

| Peran | Hak |
|---|---|
| **CMO** | Mengajukan pembahasan dari halaman verifikasi CMO; membatalkannya selama belum ada hasil |
| **Semua pengguna Forum** | Memberi komentar/pertimbangan. Level/SLA **tidak** dapat diubah dari kolom diskusi |
| **CMO dan Admin** (`forumResultRoles()` di `includes/forum.php`; hasil rapat) | Mencatat **hasil** dan menerapkannya ke CRF. Hanya kedua peran ini yang dapat mengubah Level Urgensi dan SLA |

- **Hasil pembahasan** selalu salah satu dari:
  - **Tetap** — Level dan SLA tetap memakai nilai sistem (data CRF tidak berubah).
  - **Diubah** — nilai kesepakatan diterapkan: `final_urgency_level`, `level`, `sla_value`, `sla_unit`.
- **Riwayat** (timeline, komentar sistem, dan tabel `forum_discussions`) mencatat nilai
  **sebelum**, nilai **sesudah**, alasan, waktu, dan pelaksana.
- **Tidak ada penetapan langsung** dan tidak ada pengajuan nilai oleh pengguna lain;
  satu-satunya jalan mengubah Level/SLA adalah hasil pembahasan "Diubah".
- **Satu pembahasan terbuka per CRF** (UNIQUE `forum_discussions.open_crf_id`).
- **Pembahasan otomatis**: bila kategori belum punya SLA standar sehingga SLA default
  kosong, sistem membuka pembahasan; CMO atau Admin wajib menetapkannya (hasil "Diubah") sebelum
  CRF dapat diteruskan. Pembahasan jenis ini tidak dapat dibatalkan selama SLA kosong.
- **Target hasil** 2 hari kerja (`FORUM_DISCUSSION_DECISION_DAYS`). Lewat target,
  CMO dan Admin mendapat satu pengingat (dikirim saat Forum dibuka).
- Level/SLA tidak dapat diubah lagi setelah Kepala Departemen Operasional menyetujui.
- **Tombol "Diskusi Forum"** di halaman detail CRF (CMO, Kadep, PIC CRF, Admin) membuka ruang Forum CRF itu,
  dengan jumlah komentar dan yang belum dibaca. Hanya tampil untuk CRF yang masih aktif (aturan yang
  sama dengan ruang Forum) dan peran Forum. Komponen: `includes/partials/forum_button.php`.
- **Notifikasi:** CMO, Admin, dan PIC CRF kategori saat pembahasan diajukan; Kepala Departemen Operasional
  saat hasil dicatat (CRF menunggu persetujuannya); pengaju (CMO) dan
  pemberi komentar saat hasil dicatat.

## Form pemohon & notifikasi

- **Saran Alternatif** opsional (disimpan kosong bila tidak diisi).
- **Biaya / Anggaran** opsional (tanpa pilihan bawaan; klik pilihan terpilih untuk membatalkan,
  disimpan tanpa jenis dan tanpa nominal); nominal hanya wajib bila memilih RKAP / BOQ PKS / anggaran baru.
- Kategori Helpdesk & CRF tidak lagi punya isian Urutan (urutan lama dipertahankan).
- Notifikasi hanya ke aktor berikutnya: submit → CMO; selesai PIC → pemohon; PIR → CMO
  yang meloloskan; batal → pemohon. Komentar Forum tidak menumpuk selama notifikasi
  sebelumnya belum dibaca.

## SLA

- Satuan: Menit, Jam, atau Hari.
- **Mulai** dihitung saat Kepala Departemen Operasional menyetujui CRF; **berhenti** saat
  PIC CRF menekan Selesaikan.
- Hanya berjalan pada hari kerja. Sabtu, Minggu, dan tanggal di
  `CRF_SLA_HOLIDAYS` (`config/sla.php`) tidak dihitung.
- Contoh: SLA 1 Hari yang dimulai Jumat 16:00 jatuh tempo Senin 16:00.
- **Peringatan ke PIC CRF** (hanya PIC, tidak ke atasan), dihitung dalam waktu kerja:
  *Pengingat* saat 50% SLA terpakai (dilewati bila SLA < 1 jam), *Mendesak* saat sisa waktu
  ≤ yang lebih kecil antara 25% SLA dan 1 jam, *Terlambat* saat melewati batas lalu diulang tiap
  hari kerja hingga PIC menekan Selesaikan. Dikirim oleh `database/maintenance/kirim_peringatan_sla.php`
  (jadwalkan tiap 5 menit); penanda anti-ganda di tabel `crf_sla_alerts` (migrasi 019).

## Nomor register

Format `PPU-02.4.NNNN.MM.YY`. Nomor urut direset per tahun dan dibuat atomik
lewat tabel `crf_sequence`.

## Helpdesk

Ticket Helpdesk dikelompokkan per kategori dengan PIC masing-masing. Jenis
permintaan: Maintenance, Request, Komplain. Pada kategori yang mewajibkan CRF,
Request diajukan langsung lewat Form CRF. Ticket lama berstatus
"Diteruskan ke CRF" mengikuti status CRF-nya.

## PIC CRF

PIC CRF (dulu disebut Petugas Otomasi/Handler) **tidak diisi terpisah**. Aturannya satu:
kategori Helpdesk bertanda **Butuh CRF** dengan *Kategori CRF default* X → semua PIC-nya
menjadi PIC CRF kategori X. Contoh: PIC **Aplikasi SIAP** (Butuh CRF · Aplikasi) = PIC CRF
kategori **Aplikasi**.

- PIC cukup dikelola di **Master Data → Kategori Helpdesk** (kelak menu Kategori Helpdesk SIAP).
- Mengubah atau mematikan *Butuh CRF* / *Kategori CRF default* langsung mengubah PIC CRF-nya.
- Perubahan PIC di kategori Helpdesk langsung berlaku untuk antrean CRF (tanpa sinkronisasi).
- PIC CRF yang dulu diisi manual tetap berlaku (ditandai *isian manual lama*) dan hanya bisa dihapus.
- Secara teknis `crf_category_handlers` adalah VIEW gabungan `crf_category_pic_sources` +
  `helpdesk_category_pics` + `crf_category_handlers_manual`. Saat integrasi SIAP, view ini cukup
  diarahkan ke tabel kategori & PIC Helpdesk milik SIAP.

## Database

- Instalasi baru: jalankan `database/schema.sql`.
- Database lama: jalankan migrasi di `database/migrations/` berurutan.
  Daftar lengkap ada di [database/README.md](database/README.md).
- Tahap `PEMOHON_PIR` dipulihkan oleh migrasi `011_pir_pemohon_migration.sql`.
- Alur antrean Otomasi (015) dan usulan Forum (016) sudah digantikan oleh migrasi 018.
  Pada database lama jalankan 015, 016, 017, lalu 018 secara berurutan.
- PIC CRF dari PIC Helpdesk: jalankan `017_pic_crf_dari_helpdesk_migration.sql`. **Wajib**:
  tabel `crf_category_handlers` diganti nama menjadi `crf_category_handlers_manual` dan
  digantikan VIEW. Aman dijalankan ulang.
- Forum hasil pembahasan: jalankan `018_forum_hasil_pembahasan_migration.sql`. **Wajib**: tabel
  `forum_proposals` menjadi `forum_discussions` dan `change_requests` mendapat kolom
  `forum_discussion_open`. Aman dijalankan ulang. Bila saat itu ada CRF yang sudah
  disetujui tetapi belum dimulai, jalankan setelahnya `php database/maintenance/hitung_tenggat_sla.php`
  untuk menghitung tenggat SLA-nya.

## Akun

Login memakai user SIAP (`siap.tbl_user`); peran ditentukan oleh `config/siap.php`.
Akun demo `CRFDEMO` (aktif bila `CRF_DEMO_MODE = true`) dapat menjalankan semua
peran. Nonaktifkan mode demo di production.

## Integrasi sebagai modul SIAP

CRF dapat berjalan sendiri (`standalone`, bawaan) atau dipasang di layout SIAP (`module`).
Pengaturan ada di `config/siap.php`:

| Konstanta | Fungsi |
|---|---|
| `CRF_LAYOUT_MODE` | `'standalone'` (header/sidebar CRF sendiri) atau `'module'` (hanya area konten). Bisa dioverride lewat env `CRF_LAYOUT_MODE=module`. |
| `CRF_BASE_URL` | Awalan URL modul di SIAP, mis. `'/modules/crf'`. Kosong = otomatis. |
| `CRF_LOAD_BOOTSTRAP` | Mode module: muat Bootstrap sendiri bila SIAP belum memuatnya (bawaan `false`). |
| `CRF_LOAD_ICONS` | Mode module: muat Bootstrap Icons sendiri (bawaan `true`). |

Cara kerja mode `module`:
- `includes/header.php` dan `includes/footer.php` tidak mengeluarkan `<html>`, sidebar, topbar,
  atau footer. Isi halaman dibungkus `<div class="crf-module crf-module--embedded">`.
  Halaman CRF tidak perlu diubah: tetap memanggil `include header` / `include footer`.
- Menu tersedia sebagai data dari `crfNavGroups()` (`includes/crf_nav.php`) agar SIAP dapat
  menampilkannya di menunya sendiri (label, url relatif, show, active, badge, badge_label).
  Angka antrean (CMO: verifikasi + finalisasi, Kadep: persetujuan, PIC CRF: implementasi dan yang
  melewati SLA) dan komentar Forum yang belum dibaca ikut di `badge`; teks tooltipnya di `badge_label`.
  Pengganti kartu angka di dashboard peran yang sudah dihapus.
- CSS: aturan umum (`body`, `:root`, `a`, `button`, `.btn`, `.card`, `.badge`, dst.) dibatasi di
  `.crf-module`, sehingga tidak menimpa gaya SIAP. Variabel warna memakai awalan `--crf-`.
- JavaScript: pencarian elemen dibatasi ke `.crf-module`; modal konfirmasi ditempel di dalamnya.
- Mode module tidak memuat Bootstrap JS. Dialog, dropdown, dan modal CRF memerlukan Bootstrap 5.3
  bundle (JS) dari SIAP, atau aktifkan `CRF_LOAD_BOOTSTRAP`.
- **Kategori dan Handling dipisah per modul** (tanpa tab; menu memilih modul lewat `?tab=`):

  | Modul | Menu | Halaman |
  |---|---|---|
  | Help Desk | Handling Helpdesk, Kategori Helpdesk (Admin) | `helpdesk/handling.php?tab=helpdesk`, `admin/master_data.php?tab=helpdesk` |
  | CRF | Handling CRF, Kategori CRF (Admin) | `helpdesk/handling.php?tab=crf`, `admin/master_data.php?tab=crf` |

  Menu "Dashboard" sudah tidak ada. Saat integrasi SIAP, hapus sisi Helpdesk (halaman dan tab `helpdesk`)
  karena dipegang modul Helpdesk SIAP; `includes/categories.php` cukup diarahkan ke tabel kategori dan PIC
  Helpdesk milik SIAP.
- **Catatan integrasi:** kategori Helpdesk di SIAP **tidak punya kolom "Butuh CRF"**. Di prototipe ini penanda itu
  (`helpdesk_categories.requires_crf` dan `default_crf_category_id`) menentukan tiket mana yang diteruskan ke
  Form CRF dan dari kategori Helpdesk mana PIC CRF diturunkan (`crf_category_pic_sources`). Karena SIAP tidak
  memilikinya, pemetaan itu harus dipindah ke tabel milik modul CRF (kategori Helpdesk SIAP → kategori CRF default,
  plus penanda butuh CRF) dan dikelola dari halaman Kategori CRF.
- Tetap perlu dihapus/diganti saat integrasi: `login.php`, `logout.php`, `actions/login.php`
  (login memakai sesi SIAP).
