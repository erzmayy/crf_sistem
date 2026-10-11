# Alur Workflow CRF

```
Pemohon → CMO (screening) ─┬→ Kepala Departemen Operasional (approve; SLA mulai)
                           └→ Pembahasan Forum → hasil Tetap/Diubah (CMO atau Admin) → langsung ke Kepala Departemen Operasional
  → PIC CRF: Selesai Eksekusi (SLA berhenti)
  → UAT oleh CMO bersama PIC CRF ─┬→ Perlu perbaikan → kembali ke PIC CRF → UAT ulang
                                   └→ Lulus
  → PIC CRF: isi Tanggal dan Hasil Implementasi
  → Pemohon (Post Implementation Review)
  → CMO (Finalisasi) → Selesai
```

## Tahap workflow (`crf_pengajuan.tahap_alur`)

| Tahap | Label di aplikasi | Pemegang |
|-------|-------------------|----------|
| `PEMOHON` | Menunggu Pemeriksaan | Pemohon (draft / revisi) |
| `CMO_FILTER` | Verifikasi CMO (atau **Menunggu Pembahasan Forum**) | CMO |
| `kadep_operasional` | Persetujuan Kepala Departemen Operasional | Kepala Departemen Operasional |
| `OTOMASI` | Tindak Lanjut Divisi Otomasi (tiga fase, lihat di bawah) | PIC CRF |
| `UAT` | Menunggu UAT | CMO (menguji bersama PIC CRF) |
| `PEMOHON_PIR` | Menunggu PIR Pemohon | Pemohon |
| `CMO_FINAL` | Finalisasi CMO | CMO |
| `SELESAI` | Selesai | - |

Status (`status`): Draft, Belum Ditindak Lanjuti (Menunggu Tindakan), Perlu Revisi,
Dalam Proses (Sedang Diproses), Solve (Selesai), Cancel (Dibatalkan).

Tahap `OTOMASI` dipakai ulang untuk tiga fase milik PIC CRF yang dibedakan lewat kolom waktu
(fungsi `crfOtomasiPhase()`):

| Fase | Penanda | Aksi PIC CRF |
|------|---------|--------------|
| Eksekusi | `otomasi_selesai` kosong | **Selesai Eksekusi · Kirim ke UAT** (`execute`) |
| Perbaikan hasil UAT | `otomasi_selesai` ada, `tgl_uat_lulus` kosong | **Kirim Ulang ke UAT** (`resubmit_uat`) |
| Isi implementasi | `tgl_uat_lulus` ada | **Selesaikan** (`complete`) |

Aksi di `actions/automation_action.php` divalidasi menurut fase di server, jadi UAT tidak dapat dilewati.

Selama pembahasan Forum terbuka, CRF di tahap `CMO_FILTER` bertanda
`pembahasan_forum_terbuka = 1` dan tampil sebagai **Menunggu Pembahasan Forum**.

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
4. **PIC CRF** mengeksekusi perubahan, lalu menekan **Selesai Eksekusi · Kirim ke UAT**
   (catatan serah terima untuk penguji bersifat opsional). **SLA berhenti saat ini.** PIC CRF tidak
   menentukan atau mengubah SLA. Yang memproses tercatat sebagai pemegang CRF.
5. **UAT oleh CMO** (tahap `UAT`), bersama PIC CRF; koordinasi lewat tombol *Diskusi Forum*.
   - **Dokumen hasil UAT** diunggah sebagai bukti oleh **CMO** (saat tahap UAT) dan **Admin**
     (kapan pun setelah UAT dimulai) lewat `actions/uat_upload.php`. Dokumen disimpan di
     `crf_lampiran` dengan `category = 'uat'` dan tampil di bagian *Dokumen Hasil UAT* pada Detail Pengajuan
     (terpisah dari lampiran pengajuan; tidak ikut di PDF Form CRF).
   - **Lulus UAT**: wajib sudah ada minimal satu dokumen UAT. CRF kembali ke PIC CRF.
   - **Perlu Perbaikan**: catatan wajib. CRF kembali ke PIC CRF; setelah **Kirim Ulang ke UAT**, CMO menguji lagi.
     Tidak ada batas putaran; tiap putaran tercatat di timeline.
   - **UAT dan perbaikannya tidak dihitung dalam SLA**: waktu eksekusi selesai (`otomasi_selesai`)
     hanya diisi sekali, jadi hasil SLA tidak berubah oleh putaran UAT.
6. **PIC CRF** setelah UAT lulus mengisi **Tanggal Implementasi** dan **Implementasi / Hasil Perubahan**,
   lalu CRF diteruskan ke Pemohon.
7. **Pemohon** mengisi Tanggal PIR dan Post Implementation Review.
   Setelah itu CRF diteruskan ke CMO.
8. **CMO** memfinalisasi dan menandai selesai. Pada tahap ini CMO tidak dapat membatalkan CRF
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
  - **Diubah** — nilai kesepakatan diterapkan: `level_urgensi_final`, `level`, `sla_nilai`, `sla_satuan`.
- **Riwayat** (timeline, komentar sistem, dan tabel `crf_forum_pembahasan`) mencatat nilai
  **sebelum**, nilai **sesudah**, alasan, waktu, dan pelaksana.
- **Tidak ada penetapan langsung** dan tidak ada pengajuan nilai oleh pengguna lain;
  satu-satunya jalan mengubah Level/SLA adalah hasil pembahasan "Diubah".
- **Satu pembahasan terbuka per CRF** (UNIQUE `crf_forum_pembahasan.crf_terbuka_id`).
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
  PIC CRF menekan **Selesai Eksekusi** (sebelum UAT). UAT, perbaikan hasil UAT, dan pengisian
  implementasi tidak dihitung.
- Hanya berjalan pada hari kerja. Sabtu, Minggu, dan tanggal di
  `CRF_SLA_HOLIDAYS` (`config/sla.php`) tidak dihitung.
- Contoh: SLA 1 Hari yang dimulai Jumat 16:00 jatuh tempo Senin 16:00.
- **Peringatan ke PIC CRF** (hanya PIC, tidak ke atasan), dihitung dalam waktu kerja:
  *Pengingat* saat 50% SLA terpakai (dilewati bila SLA < 1 jam), *Mendesak* saat sisa waktu
  ≤ yang lebih kecil antara 25% SLA dan 1 jam, *Terlambat* saat melewati batas lalu diulang tiap
  hari kerja hingga PIC menekan Selesai Eksekusi. Dikirim oleh `database/maintenance/kirim_peringatan_sla.php`
  (jadwalkan tiap 5 menit); penanda anti-ganda di tabel `crf_peringatan_sla` (migrasi 019).

## Nomor register

Format `PPU-<kode>.4.NNNN.MM.YY`. `<kode>` adalah `kpu_kode` akun SIAP pemohon saat mengajukan
(`crfRegisterCode()`); akun tanpa kode (mis. pusat) memakai `CRF_REGISTER_DEFAULT_CODE` di
`config/siap.php` (default `02`). Nomor tidak berubah setelah terbit. Nomor urut satu penghitung
bersama untuk semua KPU, direset per tahun, dan dibuat atomik lewat tabel `crf_nomor_urut`.

## Helpdesk

Aplikasi CRF **tidak lagi memiliki modul Helpdesk** (migrasi 022). Helpdesk ditangani modul
Helpdesk SIAP yang berdiri sendiri; pemohon masuk ke CRF langsung lewat **Form CRF**
(`user/form_crf.php`). Tidak ada lagi ticket, tautan ticket ↔ CRF, maupun status ticket yang
mengikuti CRF.

## PIC CRF

PIC CRF (dulu disebut Petugas Otomasi/Handler) diisi Admin di **Kategori CRF** (`admin/master_data.php`):
buka kartu kategori → **PIC CRF** → cari user SIAP → **Tambah**.

- Kategori tanpa PIC ditangani role Otomasi (fallback agar tidak ada CRF yatim).
- PIC dari kategori Helpdesk (migrasi 017) sudah disalin menjadi isian tetap oleh migrasi 022.
- Tabel `crf_kategori_pic` kembali menjadi tabel biasa (bukan VIEW).
## Database

- Instalasi baru: jalankan `database/schema.sql`.
- Database lama: jalankan migrasi di `database/migrations/` berurutan.
  Daftar lengkap ada di [database/README.md](database/README.md).
- Tahap `PEMOHON_PIR` dipulihkan oleh migrasi `011_pir_pemohon_migration.sql`.
- Fase UAT: jalankan `021_uat_migration.sql`. **Wajib**: tahap `UAT` pada ENUM `tahap_alur`, kolom
  `tgl_uat_lulus` dan `tgl_implementasi_diisi` pada `crf_pengajuan`, serta `crf_lampiran.category`.
  Aman dijalankan ulang. CRF yang sudah melewati tahap eksekusi sebelum migrasi tidak terpengaruh;
  CRF yang masih di tahap `OTOMASI` akan melewati UAT.
- Alur antrean Otomasi (015) dan usulan Forum (016) sudah digantikan oleh migrasi 018.
  Pada database lama jalankan 015, 016, 017, lalu 018 secara berurutan.
- PIC CRF dari PIC Helpdesk: `017_pic_crf_dari_helpdesk_migration.sql` (kini digantikan 022).
- Hapus modul Helpdesk: jalankan `022_hapus_modul_helpdesk_migration.sql`. **Backup dulu**: tabel
  `helpdesk_*` di-DROP permanen (ticket, lampiran, log), PIC CRF disalin menjadi isian tetap, dan
  kolom `helpdesk_ticket_id` dilepas. Aman dijalankan ulang. Wajib dijalankan sebelum aplikasi baru
  dipakai (penulisan PIC CRF kini langsung ke `crf_kategori_pic`).
- Forum hasil pembahasan: jalankan `018_forum_hasil_pembahasan_migration.sql`. **Wajib**: tabel
  `forum_proposals` menjadi `crf_forum_pembahasan` dan `crf_pengajuan` mendapat kolom
  `pembahasan_forum_terbuka`. Aman dijalankan ulang. Bila saat itu ada CRF yang sudah
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
- **Halaman master dan handling CRF:** Kategori CRF (Admin) di `admin/master_data.php`, Handling CRF di
  `crf/handling.php`. Sisi Helpdesk sudah dihapus karena dipegang modul Helpdesk SIAP.
- Tetap perlu dihapus/diganti saat integrasi: `login.php`, `logout.php`, `actions/login.php`
  (login memakai sesi SIAP).

## Penilaian urgensi berbobot

Level Urgensi tidak lagi dari satu pilihan dampak, melainkan dari **bobot** semua
pernyataan dampak yang dicentang Pemohon.

- **Skor** = jumlah bobot pernyataan yang dicentang (maksimum 100).
- **Level**: skor ≥ 35 → Tinggi, ≥ 10 → Normal, selain itu Rendah.
- **Pagar pengaman**: "fungsi aplikasi berhenti total" dan "risiko likuiditas dan hukum"
  selalu menghasilkan Tinggi, berapa pun skornya.
- Bobot, ambang, dan pagar pengaman ada di `config/urgensi.php` (nilai awal adalah usulan,
  sesuaikan dengan ketentuan resmi). Pernyataan mengikuti `crfImpactGroups()`.
- Tersimpan di `crf_pengajuan.dampak_dipilih` dan `skor_urgensi`; `kategori_dampak` berisi
  pernyataan dengan bobot tertinggi agar laporan dan PDF lama tetap berfungsi.
- **Pemohon tidak diberi tahu urgensi**: bobot, kelompok, perkiraan, dan hasil level tidak tampil
  di form, detail, daftar, maupun PDF-nya (agar tidak selalu memilih dampak tertinggi). Pernyataan
  ditampilkan dalam urutan netral (abjad). Level dan skor hanya tampil untuk peran internal
  (skor pada kartu Isi Permintaan).
- **Pemeriksaan konsistensi**: pasangan pernyataan yang tidak mungkin benar bersamaan (mis.
  "tidak ada risiko" dengan "risiko likuiditas dan hukum") ditolak saat kirim, diatur di
  `bertentangan` pada `config/urgensi.php`. Jika pemohon mencentang banyak pernyataan
  (`batas_banyak`, bawaan 7), CMO melihat penanda "Perlu dicek" di detail; pemohon tidak melihatnya.
- CMO atau hasil Forum tetap dapat menetapkan Level akhir (`level_urgensi_final`).
- CRF lama tetap memakai pemetaan satu pilihan lama; skornya tidak dihitung ulang.
- Uji: `php tools/uji_urgensi.php`.
