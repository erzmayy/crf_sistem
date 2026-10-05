# Alur Workflow CRF

```
Pemohon → CMO (Filter) → Otomasi (Level Urgensi + SLA)
  → Kepala Departemen Operasional (Approval)
  → Otomasi (Eksekusi & Implementasi)
  → Pemohon (Post Implementation Review)
  → CMO (Finalisasi) → Selesai
```

## Tahap workflow (`change_requests.workflow_stage`)

| Tahap | Label di aplikasi | Pemegang |
|-------|-------------------|----------|
| `PEMOHON` | Menunggu Pemeriksaan | Pemohon (draft / revisi) |
| `CMO_FILTER` | Verifikasi CMO | CMO |
| `OTOMASI` | Tindak Lanjut Divisi Otomasi | Handler (tetapkan SLA, lalu eksekusi) |
| `kadep_operasional` | Persetujuan Kepala Departemen Operasional | Kepala Departemen Operasional |
| `PEMOHON_PIR` | Menunggu PIR Pemohon | Pemohon |
| `CMO_FINAL` | Finalisasi CMO | CMO |
| `SELESAI` | Selesai | - |

Status (`status`): Draft, Belum Ditindak Lanjuti (Menunggu Tindakan), Perlu Revisi,
Dalam Proses (Sedang Diproses), Solve (Selesai), Cancel (Dibatalkan).

## Langkah per peran

1. **Pemohon** mengisi Form CRF dan mengajukannya (bisa disimpan sebagai Draft).
   Level Urgensi ditentukan otomatis dari kategori Dampak.
2. **CMO** memfilter: meneruskan ke Otomasi, meminta revisi (kembali ke Pemohon),
   atau membatalkan.
3. **Otomasi (Handler)** mengambil CRF pada kategorinya, lalu menetapkan SLA.
   SLA standar diambil dari matriks Kategori × Urgensi dan tidak mengubah Level
   Urgensi. CRF diteruskan ke Kepala Departemen Operasional.
4. **Kepala Departemen Operasional** menyetujui. **SLA mulai dihitung sejak
   persetujuan ini**, lalu CRF kembali ke Otomasi.
5. **Otomasi** mengeksekusi dan mengisi Tanggal serta Hasil Implementasi.
   CRF diteruskan ke Pemohon.
6. **Pemohon** mengisi Tanggal PIR dan Post Implementation Review.
   Setelah itu CRF diteruskan ke CMO.
7. **CMO** memfinalisasi dan menandai selesai.

Admin dapat memantau semua CRF, menugaskan ulang Handler, dan membatalkan CRF
secara administratif. Forum dipakai CMO, Otomasi, Kepala Departemen Operasional,
dan Admin untuk berdiskusi; hanya Admin yang dapat menetapkan Level Urgensi dan SLA final di
Forum (prioritas di atas level otomatis). Kesepakatan ini terkunci setelah
persetujuan Kepala Departemen Operasional.

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

## Database

- Instalasi baru: jalankan `database/schema.sql`.
- Database lama: jalankan migrasi di `database/migrations/` berurutan.
  Daftar lengkap ada di [database/README.md](database/README.md).
- Tahap `PEMOHON_PIR` dipulihkan oleh migrasi `011_pir_pemohon_migration.sql`.

## Akun

Login memakai user SIAP (`siap.tbl_user`); peran ditentukan oleh `config/siap.php`.
Akun demo `CRFDEMO` (aktif bila `CRF_DEMO_MODE = true`) dapat menjalankan semua
peran. Nonaktifkan mode demo di production.
