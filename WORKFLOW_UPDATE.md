# Alur Workflow CRF

```
Pemohon → CMO (screening) ─┬→ Kepala Departemen Operasional (approve; SLA mulai)
                           └→ Pembahasan Forum → hasil Tetap/Diubah (Admin) → kembali ke CMO
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
| **Admin** (`forumResultRoles()` di `includes/forum.php`) | Mencatat **hasil** dan menerapkannya ke CRF |

- **Hasil pembahasan** selalu salah satu dari:
  - **Tetap** — Level dan SLA tetap memakai nilai sistem (data CRF tidak berubah).
  - **Diubah** — nilai kesepakatan diterapkan: `final_urgency_level`, `level`, `sla_value`, `sla_unit`.
- **Riwayat** (timeline, komentar sistem, dan tabel `forum_discussions`) mencatat nilai
  **sebelum**, nilai **sesudah**, alasan, waktu, dan pelaksana.
- **Tidak ada penetapan langsung** dan tidak ada pengajuan nilai oleh pengguna lain;
  satu-satunya jalan mengubah Level/SLA adalah hasil pembahasan "Diubah".
- **Satu pembahasan terbuka per CRF** (UNIQUE `forum_discussions.open_crf_id`).
- **Pembahasan otomatis**: bila kategori belum punya SLA standar sehingga SLA default
  kosong, sistem membuka pembahasan; Admin wajib menetapkannya (hasil "Diubah") sebelum
  CRF dapat diteruskan. Pembahasan jenis ini tidak dapat dibatalkan selama SLA kosong.
- **Target hasil** 2 hari kerja (`FORUM_DISCUSSION_DECISION_DAYS`). Lewat target,
  Admin mendapat satu pengingat (dikirim saat Forum dibuka).
- Level/SLA tidak dapat diubah lagi setelah Kepala Departemen Operasional menyetujui.
- **Notifikasi:** Admin dan PIC CRF kategori saat pembahasan diajukan; CMO, pengaju, dan
  peserta diskusi saat hasil dicatat.

## SLA

- Satuan: Menit, Jam, atau Hari.
- **Mulai** dihitung saat Kepala Departemen Operasional menyetujui CRF; **berhenti** saat
  PIC CRF menekan Selesaikan.
- Hanya berjalan pada hari kerja. Sabtu, Minggu, dan tanggal di
  `CRF_SLA_HOLIDAYS` (`config/sla.php`) tidak dihitung.
- Contoh: SLA 1 Hari yang dimulai Jumat 16:00 jatuh tempo Senin 16:00.
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
