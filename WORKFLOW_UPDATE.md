# CRF Workflow Update

Alur prototype yang diimplementasikan:

Pemohon → CMO (Filter) → Otomasi (Level Urgensi + SLA + Eksekusi) → Pemohon (PIR) → Pak Joko (Approval) → CMO (Finalisasi) → Selesai → Pemohon

## Tahap workflow
- PEMOHON
- CMO_FILTER
- OTOMASI
- PEMOHON_PIR
- kadep_operasional
- CMO_FINAL
- SELESAI

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
Sebelum menjalankan versi kode ini pada database `crf_prototype` lama, jalankan sekali:

`workflow_migration.sql`

Migration menambahkan:
- `workflow_stage`
- data SLA
- timestamp proses Otomasi
- data approval Pak Joko
- tabel `crf_user_roles`
- mapping role dan akun demo workflow bila belum ada

## Catatan
Implementasi dan Post Implementation Review sekarang dipisahkan:
- Implementasi diisi Otomasi.
- PIR diisi Pemohon setelah Otomasi selesai.
- Pak Joko melakukan approval setelah PIR.
- CMO melakukan finalisasi dan menandai CRF Selesai.
