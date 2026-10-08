# CRF Sistem

Aplikasi **Change Request Form (CRF)** PT Persona Prima Utama (PPU): pengajuan,
persetujuan, eksekusi, dan pelaporan permintaan perubahan, dilengkapi modul
Forum, Helpdesk, dan Notifikasi.

- Stack: PHP native (PDO) + MySQL, tanpa framework.
- Library (Composer): `dompdf/dompdf` (cetak PDF), `phpmailer/phpmailer`
  (email notifikasi), `aws/aws-sdk-php` (lampiran ke storage S3-compatible).
- Alur workflow lengkap: lihat [WORKFLOW_UPDATE.md](WORKFLOW_UPDATE.md).
- Database dan migrasi: lihat [database/README.md](database/README.md).

## Instalasi (lokal / XAMPP)

1. Pasang dependensi PHP:
   ```bash
   composer install
   ```
   Atau, bila memasang manual satu per satu:
   ```bash
   composer require dompdf/dompdf
   composer require aws/aws-sdk-php
   composer require phpmailer/phpmailer:6.9
   ```
2. Buat database: jalankan `database/schema.sql` (instalasi baru tidak perlu migrasi).
3. Sesuaikan `config/database.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
4. Salin file contoh konfigurasi bila diperlukan:
   - `config/mail.example.php` → `config/mail.php` (email notifikasi)
   - `config/dev_login.example.php` → `config/dev_login.php` (login pengembangan)
   - `config/wasabi.php` (storage lampiran S3; tidak ada di repo)

   File-file ini sudah masuk `.gitignore`.
5. Buka `login.php` melalui web server.

## Struktur folder

| Folder | Isi |
|--------|-----|
| `user/` | Pemohon: Form CRF, Pengajuan Saya, detail |
| `cmo/`, `otomasi/`, `pak_joko/` | Antrean dan detail untuk CMO, PIC CRF (Otomasi), dan Kepala Departemen Operasional; setelah login langsung masuk ke antrean masing-masing |
| `admin/` | Dashboard, master kategori CRF/Helpdesk, Handling Kategori |
| `helpdesk/` | Ticket Helpdesk (form, ticket saya, penanganan PIC) |
| `forum/` | Diskusi per CRF dan penetapan urgensi final |
| `notifications/` | Notifikasi in-app |
| `crf/` | Pintu masuk tautan CRF (mengarahkan ke halaman sesuai peran) |
| `actions/` | Endpoint POST (submit, draft, approval, assign, ekspor, dll.) |
| `includes/` | Logika inti: `auth.php` (peran/akses), `functions.php` (status, SLA, nomor register), `categories.php`, `helpdesk.php`, `notifications.php`, `forum.php` |
| `config/` | Konfigurasi: `siap.php` (sumber user & peran), `crf.php`, `sla.php`, `database.php` |
| `database/` | `schema.sql` dan migrasi bernomor |

## Peran

| Peran | Penentuan | Fungsi |
|-------|-----------|--------|
| Pemohon | Semua user lain | Mengajukan CRF, merevisi, mengisi PIR |
| CMO | Departemen di `CRF_CMO_DEPTS` | Filter awal, finalisasi, urgensi final di Forum |
| Otomasi / Handler | `CRF_OTOMASI_USERIDS` atau handler kategori | Menetapkan SLA, eksekusi, mengisi Implementasi |
| Kepala Departemen Operasional | `CRF_KADEP_OPERASIONAL_USERIDS` | Persetujuan sebelum eksekusi |
| Admin | `CRF_ADMIN_USERIDS` (hak tambahan) | Memantau, laporan, kelola kategori/handler, penugasan ulang, pembatalan administratif |
| Demo | `CRF_DEMO_USERIDS` | Menjalankan semua peran untuk presentasi |

Sumber user dan peran diatur di `config/siap.php`. Pemisahan tugas: Admin tidak
boleh menugaskan ulang atau membatalkan CRF yang sedang ia pegang sebagai Handler.

## Catatan untuk production

- Set `CRF_DEMO_MODE = false` di `config/siap.php`.
- Jangan jalankan migrasi `006_siap_demo_user_migration.sql` (khusus lokal/testing).
- Perbarui `CRF_SLA_HOLIDAYS` di `config/sla.php` setiap tahun.
- Folder `database/`, `uploads/`, serta file `.sql/.md/.py/.lock` dilindungi `.htaccess`.
