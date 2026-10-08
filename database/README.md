# Database CRF

Folder ini diblokir dari browser (`.htaccess`). Jalankan file-nya lewat
MySQL client / phpMyAdmin oleh admin server.

## Instalasi baru

Cukup jalankan `schema.sql`. File ini sudah berisi struktur terbaru,
jadi tidak perlu menjalankan migrasi.

## Database yang sudah berjalan

Jalankan migrasi di `migrations/` **berurutan sesuai nomor**, mulai dari
nomor setelah migrasi terakhir yang pernah dijalankan. Sebagian besar
migrasi aman dijalankan ulang.

| No | File | Isi |
|----|------|-----|
| 001 | `database_migration.sql` | Penyesuaian schema prototipe lama |
| 002 | `workflow_migration.sql` | Workflow, SLA, tabel role, akun demo workflow |
| 003 | `kadep_approval_migration.sql` | Kolom approval Kepala Departemen Operasional |
| 004 | `kadep_workflow_stage_migration.sql` | Enum `PAK_JOKO` menjadi `kadep_operasional` |
| 005 | `siap_user_source_migration.sql` | User dibaca dari `siap.tbl_user` |
| 006 | `siap_demo_user_migration.sql` | Akun demo di SIAP (**khusus lokal/testing, jangan di production**) |
| 007 | `implementation_date_migration.sql` | Tanggal Implementasi, Tanggal PIR, tipe pengajuan, dampak |
| 008 | `forum_migration.sql` | Tabel komentar Forum |
| 009 | `forum_final_sla_migration.sql` | Level urgensi final hasil Forum |
| 010 | `helpdesk_crf_integration_migration.sql` | Modul Helpdesk, kategori, handler, notifikasi |
| 011 | `pir_pemohon_migration.sql` | Tahap `PEMOHON_PIR` (PIR diisi pemohon) |
| 012 | `sla_matrix_migration.sql` | Matriks SLA kategori × urgensi |
| 013 | `crf_category_five_migration.sql` | Kategori CRF jadi 5: Aplikasi, Infrastruktur, Proses, Security, Lainnya |
| 014 | `drop_helpdesk_ticket_number_migration.sql` | Hapus nomor ticket Helpdesk (mengikuti SIAP) |
| 015 | `alur_antrean_otomasi_migration.sql` | Alur baru: CMO → Kadep → antrean Otomasi; CRF di tahap penetapan SLA Otomasi dipindah ke persetujuan |
| 016 | `forum_proposals_migration.sql` | Usulan Urgensi & SLA di Forum (tabel `forum_proposals`, kolom `forum_comments.is_system`) |
| 017 | `pic_crf_dari_helpdesk_migration.sql` | PIC CRF diturunkan dari PIC Kategori Helpdesk (tabel `crf_category_pic_sources`, `crf_category_handlers` menjadi VIEW) |
| 018 | `forum_hasil_pembahasan_migration.sql` | Forum: pembahasan Level/SLA dengan hasil Tetap/Diubah (`forum_proposals` → `forum_discussions`), penanda `forum_discussion_open`, SLA default saat submit |
| 019 | `peringatan_sla_migration.sql` | Tabel `crf_sla_alerts` (penanda peringatan SLA ke PIC CRF agar tidak terkirim ganda) |
| 020 | `portabilitas_migration.sql` | Ekspor database aman diimpor di MySQL 5.7 / MariaDB: collation `utf8mb4_unicode_ci`, hapus prosedur bantu sisa migrasi, view `crf_category_handlers` tanpa `CAST ... CHARACTER SET` |
| 021 | `uat_migration.sql` | Fase UAT oleh CMO: tahap `UAT`, kolom `uat_passed_at` dan `implementation_submitted_at`, `attachments.category` (dokumen hasil UAT) |

Skrip pemeliharaan (jalankan dari terminal, bukan browser):

- `maintenance/hitung_tenggat_sla.php` — menghitung `sla_due_at` untuk CRF yang SLA-nya sudah dimulai tetapi tenggatnya kosong.

- `maintenance/kirim_peringatan_sla.php` — mengirim peringatan SLA ke PIC CRF. **Jadwalkan tiap 5 menit** (Windows Task Scheduler atau cron), mis. `php C:\path\crf_sistem\database\maintenance\kirim_peringatan_sla.php`.

Migrasi baru berikutnya diberi nomor `022_...`.

## Memindahkan database ke komputer lain (tanpa galat impor)

Ekspor biasa dari MySQL 8 bisa gagal di MySQL 5.7 / MariaDB (galat `DEFINER`,
`Unknown collation utf8mb4_0900_ai_ci`, atau syntax error pada `charset utf8mb4`).
Gunakan alat ekspor portabel:

```
php database/maintenance/ekspor_portabel.php
```

Hasilnya `backups/crf_sistem_portabel_<tanggal>.sql`. Di komputer tujuan:

```
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS crf_sistem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -uroot crf_sistem < crf_sistem_portabel_<tanggal>.sql
```

Bila sudah terlanjur mengekspor dari phpMyAdmin, bersihkan berkasnya dulu:

```
php database/maintenance/ekspor_portabel.php bersihkan berkas.sql
```

Berkas ekspor berisi data nyata (nama dan isi CRF). Folder `backups/` diabaikan git; jangan
dibagikan atau di-commit. Database yang dibuat sebelum migrasi 020: jalankan
`020_portabilitas_migration.sql` agar ekspor dari phpMyAdmin pun sudah bersih.
