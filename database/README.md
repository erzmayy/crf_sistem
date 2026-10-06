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

Migrasi baru berikutnya diberi nomor `017_...`.
