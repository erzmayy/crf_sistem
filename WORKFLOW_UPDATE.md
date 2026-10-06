# Alur Workflow CRF

```
Pemohon → CMO (Filter) → Kepala Departemen Operasional (Approval)
  → Antrean Otomasi → Otomasi: Mulai Kerjakan (SLA mulai)
  → Otomasi: Selesai (Implementasi)
  → Pemohon (Post Implementation Review)
  → CMO (Finalisasi) → Selesai
```

## Tahap workflow (`change_requests.workflow_stage`)

| Tahap | Label di aplikasi | Pemegang |
|-------|-------------------|----------|
| `PEMOHON` | Menunggu Pemeriksaan | Pemohon (draft / revisi) |
| `CMO_FILTER` | Verifikasi CMO | CMO |
| `kadep_operasional` | Persetujuan Kepala Departemen Operasional | Kepala Departemen Operasional |
| `OTOMASI` | Tindak Lanjut Divisi Otomasi | Handler (antrean → Mulai Kerjakan → Selesai) |
| `PEMOHON_PIR` | Menunggu PIR Pemohon | Pemohon |
| `CMO_FINAL` | Finalisasi CMO | CMO |
| `SELESAI` | Selesai | - |

Status (`status`): Draft, Belum Ditindak Lanjuti (Menunggu Tindakan), Perlu Revisi,
Dalam Proses (Sedang Diproses), Solve (Selesai), Cancel (Dibatalkan).

Pada tahap `OTOMASI`, CRF dengan `automation_started_at` kosong tampil sebagai
**Disetujui · Antrean**; setelah "Mulai Kerjakan" tampil sebagai **Disetujui · Eksekusi**.

## Langkah per peran

1. **Pemohon** mengisi Form CRF dan mengajukannya (bisa disimpan sebagai Draft).
   Level Urgensi ditentukan otomatis dari kategori Dampak.
2. **CMO** memfilter: meneruskan ke Kepala Departemen Operasional, meminta revisi
   (kembali ke Pemohon), atau membatalkan. Saat diteruskan, Level Urgensi dan SLA
   terisi otomatis dari matriks Kategori × Urgensi. Bila kategori belum punya SLA
   standar, Admin mendapat notifikasi untuk menetapkannya di Forum.
3. **Kepala Departemen Operasional** menyetujui (tidak ada jalur tolak). CRF masuk
   **antrean Otomasi**; SLA **belum** berjalan. Persetujuan butuh SLA yang valid.
4. **Otomasi (Handler)** menekan **Mulai Kerjakan** saat benar-benar mengerjakan.
   **SLA mulai dihitung sejak saat itu.** Handler yang memulai otomatis menjadi
   pemegang CRF. Waktu tunggu di antrean dicatat di timeline. Otomasi tidak
   menentukan atau mengubah SLA.
5. **Otomasi** mengisi Tanggal serta Hasil Implementasi lalu menekan **Selesaikan**.
   CRF diteruskan ke Pemohon.
6. **Pemohon** mengisi Tanggal PIR dan Post Implementation Review.
   Setelah itu CRF diteruskan ke CMO.
7. **CMO** memfinalisasi dan menandai selesai.

Antrean Otomasi diurutkan: CRF yang sedang dikerjakan (tenggat terdekat) lebih dulu,
lalu antrean menurut Level Urgensi (Tinggi → Normal → Rendah) dan waktu persetujuan.

Admin dapat memantau semua CRF dan membatalkan CRF secara administratif.
Tidak ada fitur "Ambil CRF" maupun penugasan Handler oleh Admin: PIC CRF
yang menekan "Mulai Kerjakan" otomatis menjadi pemegang CRF, dan hanya dia yang
dapat menyelesaikannya. Forum dipakai CMO, Otomasi, Kepala Departemen Operasional,
dan Admin untuk berdiskusi. Perubahan Level Urgensi dan SLA hanya lewat mekanisme
usulan di bawah (prioritas di atas level otomatis).

## Forum: usulan Urgensi & SLA

Level Urgensi dan SLA terisi otomatis dari matriks Kategori × Urgensi. Bila perlu
diubah, dibahas di Forum dengan alur **usulan → keputusan satu pihak** (tanpa voting):

| | Siapa |
|---|---|
| **Mengajukan** | CMO, Admin, PIC CRF kategori CRF (akun demo untuk presentasi) |
| **Memutuskan** | Penentu = `forumDeciderRoles()` di `includes/forum.php` (saat ini hanya **Admin**) |
| Hanya berkomentar | Kepala Departemen Operasional |

- **Pemicu pembahasan:** tombol *Ajukan Pembahasan Urgensi/SLA* (halaman verifikasi CMO
  atau ruang Forum), atau otomatis oleh sistem saat CMO meneruskan CRF yang SLA standar
  kategorinya kosong. Ruang yang punya usulan terbuka diberi penanda **Perlu dibahas**
  dan tampil paling atas; filter bawaan Forum menjadi *Perlu dibahas* bila ada.
- **Jenis usulan** ditentukan dari tahap CRF:
  - *Urgensi & SLA* — sebelum disetujui Kepala Departemen Operasional.
  - *Perpanjangan SLA* — setelah disetujui, selama CRF di tahap Otomasi. SLA baru adalah
    total sejak SLA dimulai dan harus lebih lama; batas SLA dihitung ulang.
- **Keputusan:** Setujui (boleh menyesuaikan nilai) atau Tolak (catatan wajib). Penentu
  juga dapat menetapkan nilai langsung tanpa usulan; tetap tercatat di riwayat.
- **Satu usulan terbuka per CRF** (dijaga UNIQUE `forum_proposals.open_crf_id`).
- **Tidak menahan alur:** CRF tetap bisa diteruskan/disetujui walau usulan belum
  diputuskan; usulan ditutup otomatis saat Kepala Departemen Operasional menyetujui
  (urgensi/SLA) atau CRF selesai dikerjakan/dibatalkan. Pengecualian: CRF **tanpa SLA**
  tidak dapat disetujui sampai penentu menetapkannya.
- **Batas keputusan** 2 hari kerja (`FORUM_PROPOSAL_DECISION_DAYS`) — target proses, bukan
  SLA CRF. Lewat batas, penentu mendapat satu pengingat (dikirim saat Forum dibuka).
- **Notifikasi:** penentu (dan CMO bila masih verifikasi) saat ada usulan; pengusul,
  peserta diskusi, handler, dan CMO saat ada keputusan.
- Urgensi/SLA terkunci setelah persetujuan Kepala Departemen Operasional kecuali lewat
  perpanjangan SLA.

## SLA

- Satuan: Menit, Jam, atau Hari.
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
- Alur antrean Otomasi: jalankan `015_alur_antrean_otomasi_migration.sql` agar CRF
  lama di tahap penetapan SLA Otomasi pindah ke antrean persetujuan.
- Usulan Urgensi & SLA Forum: jalankan `016_forum_proposals_migration.sql` (tabel
  `forum_proposals` dan kolom `forum_comments.is_system`). **Wajib** sebelum kode ini
  dipakai: tanpa tabel itu halaman Forum error.
- PIC CRF dari PIC Helpdesk: jalankan `017_pic_crf_dari_helpdesk_migration.sql`. **Wajib**:
  tabel `crf_category_handlers` diganti nama menjadi `crf_category_handlers_manual` dan
  digantikan VIEW. Aman dijalankan ulang.

## Akun

Login memakai user SIAP (`siap.tbl_user`); peran ditentukan oleh `config/siap.php`.
Akun demo `CRFDEMO` (aktif bila `CRF_DEMO_MODE = true`) dapat menjalankan semua
peran. Nonaktifkan mode demo di production.
