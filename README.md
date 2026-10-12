# CRF Sistem

Aplikasi **Change Request Form (CRF)** PT Persona Prima Utama (PPU): pengajuan,
persetujuan, eksekusi, dan pelaporan permintaan perubahan, dilengkapi modul
Forum dan Notifikasi. Helpdesk ditangani modul Helpdesk SIAP (bukan bagian aplikasi ini).

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
   - `config/wasabi.example.php` → `config/wasabi.php` (storage lampiran S3)

   File-file ini sudah masuk `.gitignore`. Isian dan cara pakainya ada di bagian
   "Konfigurasi yang harus diisi di server" di bawah.
5. Buka `login.php` melalui web server.

## Struktur folder

| Folder | Isi |
|--------|-----|
| `user/` | Pemohon: Form CRF, Pengajuan Saya, detail |
| `cmo/`, `otomasi/`, `kadep_operasional/` | Antrean dan detail untuk CMO, PIC CRF (Otomasi), dan Kepala Departemen Operasional; setelah login langsung masuk ke antrean masing-masing |
| `admin/` | Dashboard dan master Kategori CRF (+ PIC CRF) |
| `forum/` | Diskusi per CRF dan penetapan urgensi final |
| `notifications/` | Notifikasi in-app |
| `crf/` | Pintu masuk tautan CRF (mengarahkan ke halaman sesuai peran) dan Handling Kategori CRF |
| `actions/` | Endpoint POST (submit, draft, approval, assign, ekspor, dll.) |
| `includes/` | Logika inti: `auth.php` (peran/akses), `functions.php` (status, SLA, nomor register), `categories.php`, `notifications.php`, `forum.php` |
| `config/` | Konfigurasi: `siap.php` (sumber user & peran), `sla.php`, `urgensi.php` (bobot urgensi), `database.php` |
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
- Folder `database/` serta file `.sql/.md/.py/.lock` dilindungi `.htaccess`.

## Konfigurasi yang harus diisi di server

Kedua file berisi kredensial, jadi **tidak ada di repositori git** (di-`.gitignore`).
Pada paket serah terima, keduanya disertakan di folder `config/` dalam keadaan sudah
terisi; pemasang cukup memeriksa nilainya dan mengganti kunci bila perlu. Bila salah satu
belum ada di paket, buat dari berkas contoh. Jangan di-commit.

### `config/mail.php` (email notifikasi)

Salin dari `config/mail.example.php`.

| Isian | Keterangan |
|-------|------------|
| `enabled` | `true` untuk mengaktifkan email. `false` atau file tidak ada: email dilewati, notifikasi lonceng tetap jalan |
| `host`, `port`, `encryption` | Server SMTP perusahaan. `encryption`: `tls` (587), `ssl` (465), atau kosong |
| `username`, `password` | Akun SMTP. Kosongkan bila SMTP internal tidak memakai login |
| `from_email`, `from_name` | Alamat dan nama pengirim. Sering harus sama dengan akun SMTP |
| `app_url` | URL produksi modul CRF tanpa garis miring akhir. Dipakai untuk tombol "Buka CRF" di email. Bila salah, tautan mengarah ke `localhost` |

Email dikirim otomatis untuk semua notifikasi (CRF baru, persetujuan, revisi, UAT, PIR,
Forum, dan peringatan SLA). Penerima harus punya email valid di `siap.tbl_user`; bila
kosong, notifikasi hanya tampil di lonceng. Peringatan SLA hanya terkirim bila tugas
terjadwal di bawah dijalankan.

### `config/wasabi.php` (lampiran)

Salin dari `config/wasabi.example.php`.

| Isian | Keterangan |
|-------|------------|
| `endpoint`, `region` | Endpoint storage S3-compatible (contoh: Wasabi `ap-southeast-1`) |
| `credentials.key`, `credentials.secret` | Access key bucket. Buat khusus untuk CRF |
| `bucket` | Nama bucket. Harus **private** (tanpa bucket policy publik) |
| `upload_path` | Awalan folder di dalam bucket, mis. `crf/uploads/` |

Lampiran diunggah sebagai objek private dan hanya dibuka lewat
`actions/download_attachment.php` setelah cek hak akses. Batas: PDF/JPG/PNG, 5 MB per file.
Tanpa file ini, fitur unggah dan unduh lampiran error. Bila SIAP memakai storage lain, perlu penyesuaian kode.

### Tugas terjadwal (peringatan SLA)

Jalankan tiap 5 menit di server (cron atau Task Scheduler):

```bash
*/5 * * * * php /path/ke/crf/database/maintenance/kirim_peringatan_sla.php
```

Skrip hanya dapat dijalankan dari terminal dan aman diulang. Membutuhkan `config/mail.php`
agar peringatan sampai ke email PIC CRF.
