# CRF Workflow Update

Alur workflow:

Pemohon → CMO (Filter) → Otomasi (Level Urgensi + SLA) → Kepala Departemen Operasional (Approval) → Otomasi (Eksekusi, Implementasi & PIR) → CMO (Finalisasi) → Selesai → Pemohon

## Tahap workflow
- PEMOHON
- CMO_FILTER
- OTOMASI
- kadep_operasional
- CMO_FINAL
- SELESAI

`PEMOHON_PIR` tetap ada di enum database untuk kompatibilitas data lama, tetapi bukan lagi tahap aktif.

## Role prototype
- pemohon
- cmo
- otomasi
- kadep_operasional
- admin

## Akun demo
Semua password: `password`

- USER001 — Pemohon
- CMO001 — CMO
- OTOMASI001 — Otomasi
- JOKO001 — Pak Joko
- ADMIN001 — Admin

## Database
Untuk database `crf_system` lama yang belum memiliki kolom approval Kepala Departemen Operasional, jalankan:

`kadep_approval_migration.sql`

Migrasi ini aman dijalankan ulang. Untuk instalasi workflow lama yang belum memiliki kolom workflow/SLA atau tabel role, gunakan `workflow_migration.sql`.

Untuk database yang nilai enum `workflow_stage`-nya masih menggunakan `PAK_JOKO`, jalankan `kadep_workflow_stage_migration.sql` agar nilai tersebut diselaraskan dengan `kadep_operasional`.

Migration menambahkan:
- `workflow_stage`
- data SLA
- timestamp proses Otomasi
- data approval Pak Joko
- tabel `crf_user_roles`
- mapping role dan akun demo workflow bila belum ada

## Catatan
- Otomasi menetapkan Level Urgensi dan SLA, lalu mengirim CRF ke Kepala Departemen Operasional untuk approval sebelum eksekusi.
- Setelah approval, Otomasi mengisi Implementasi / Hasil Perubahan dan Post Implementation Review saat menyelesaikan eksekusi.
- Pemohon hanya melihat Implementasi dan PIR; kedua isian tersebut tidak dapat diedit oleh Pemohon.
- Setelah eksekusi selesai, CRF diteruskan ke CMO untuk finalisasi dan penandaan selesai.
