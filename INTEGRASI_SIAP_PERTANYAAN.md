# Daftar Pertanyaan Integrasi Modul CRF ke SIAP

Dokumen ini untuk dibahas dengan tim SIAP. Setiap pertanyaan punya **asumsi kami**
(dipakai bila tidak ada jawaban) dan kolom **Jawaban** untuk diisi.

## A. Ringkasan teknis CRF saat ini

| Hal | Kondisi CRF sekarang |
|---|---|
| Bahasa / versi | PHP native 8.2, tanpa framework, MySQL 8 (PDO, prepared statements) |
| Tampilan | Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 (CDN), CSS sendiri `assets/css/style.css` |
| Login | Pengguna dan password dibaca dari `siap.tbl_user`. Login dan logout nantinya memakai milik SIAP; `login.php`, `logout.php`, dan `actions/login.php` CRF tidak dipakai lagi. Sesi memakai `$_SESSION['user_id']` dan `$_SESSION['active_user']` |
| Peran CRF | Ditentukan kode (`config/siap.php`) dari userid, departemen, dan divisi user SIAP: Pemohon, CMO, Kepala Departemen Operasional, PIC CRF, Admin |
| PIC CRF | Didaftarkan Admin per kategori di menu **Kategori CRF** (tabel `crf_kategori_pic`) |
| Folder halaman | `user/` (pemohon), `cmo/`, `otomasi/` (PIC CRF), `kadep_operasional/` (Kepala Departemen Operasional), `admin/`, `forum/`, `notifications/`, `crf/` |
| Database | Database terpisah `crf_sistem` (tabel CRF, Forum, notifikasi, log); user dibaca dari database `siap` (hanya SELECT pada `tbl_user`) |
| Instalasi database | `database/schema.sql` untuk instalasi baru. Folder `database/migrations/` hanya riwayat; **tidak perlu dijalankan** pada instalasi baru |
| Lampiran | Disimpan sebagai objek **private** di storage kompatibel S3 (Wasabi) lewat `aws/aws-sdk-php`, dibuka hanya lewat `actions/download_attachment.php` setelah cek hak akses |
| Email | PHPMailer 6.9, konfigurasi di `config/mail.php` (contoh: `config/mail.example.php`) |
| PDF / Excel | `dompdf/dompdf` untuk PDF; ekspor Excel (`xlsx`) dibuat sendiri |
| Notifikasi | Tabel `crf_notifikasi` sendiri (lonceng + email) |
| Tampilan modul | Sudah ada mode `module` (`CRF_LAYOUT_MODE`): hanya area konten, menu tersedia dari `crfNavGroups()` |
| Modul Helpdesk | **Tidak ada di CRF.** Helpdesk dipegang SIAP; tabel dan halaman Helpdesk di CRF sudah dihapus |
| Jadwal berkala | `database/maintenance/kirim_peringatan_sla.php` (peringatan SLA ke PIC CRF), dijalankan tiap 5 menit |

## B. Cara modul dipasang dan dipanggil

1. **Bagaimana halaman modul dimuat di layout SIAP?** (a) CRF memanggil file layout SIAP di
   bagian atas dan bawah halaman; (b) SIAP memuat file CRF lewat `include`/route; (c) iframe.
   *Asumsi kami: (a) atau (b), bukan iframe.*
   Jawaban: ______
2. **Di mana kode CRF diletakkan?** Folder di dalam aplikasi SIAP (mis. `/modules/crf`),
   repositori terpisah, atau paket Composer? Apa URL dasarnya (`CRF_BASE_URL`)?
   Jawaban: ______
3. **Apakah SIAP memakai framework/router sendiri?** Bila ya, apakah file PHP CRF yang berdiri
   sendiri (`admin/…`, `user/…`, dst.) boleh diakses langsung lewat URL, atau harus lewat route?
   Jawaban: ______
4. **Siapa yang mendaftarkan modul dan menu?** Apakah menu SIAP dibaca dari tabel/daftar
   (kami bisa memberi `crfNavGroups()` sebagai sumber), atau ditulis manual?
   Jawaban: ______
5. **Versi PHP, ekstensi, dan Composer di server SIAP** — apakah cocok dengan PHP 8.2 dan
   `vendor/` CRF dapat dipakai bersama atau harus digabung dengan milik SIAP?
   *Paket yang kami kirim hanya berisi `composer.json` dan `composer.lock`; `vendor/` dibuat
   dengan `composer install`.*
   Jawaban: ______

## C. Login, sesi, dan identitas

6. **Apakah CRF memakai sesi login SIAP?** Bila ya, apa nama kunci sesinya (ID user, userid,
   nama, departemen, divisi/jabatan)? *Asumsi kami: `pengguna_id` sama dengan ID di `tbl_user`.*
   Jawaban: ______
7. **Berkas apa yang cukup dipanggil di awal halaman** untuk memastikan user sudah login
   (mis. `require 'siap/auth.php'`), dan ke mana user diarahkan bila belum login/sesi habis?
   Jawaban: ______
8. **Login dan logout memakai SIAP**, jadi `login.php`, `logout.php`, dan `actions/login.php`
   CRF dihapus dari paket pemasangan. Ke URL mana tombol "Keluar" harus mengarah?
   Jawaban: ______
9. **Apakah ada pengaman tambahan** yang harus diikuti (token CSRF global, header keamanan,
   batas waktu sesi, pembatasan percobaan login)? *CRF memakai token CSRF sendiri pada
   semua aksi yang mengubah data.*
   Jawaban: ______
10. **Akun demo `CRFDEMO` dan login lokal (`config/dev_login.php`)** hanya untuk presentasi dan
    **dimatikan di produksi** (`CRF_DEMO_MODE = false`). Apakah ada lingkungan staging untuk
    presentasi? Mohon pastikan akun `CRFDEMO` tidak ada di `tbl_user` produksi.
    Jawaban: ______

## D. Peran dan hak akses

11. **Apakah SIAP punya tabel/mekanisme peran resmi?** Atau peran CRF tetap ditentukan kode CRF
    dari departemen dan divisi (cara sekarang)?
    Jawaban: ______
12. **Siapa yang berhak menetapkan** CMO, Kepala Departemen Operasional, PIC CRF, dan Admin CRF
    (administrator SIAP atau Admin CRF)? Bagaimana bila orangnya berganti jabatan atau cuti?
    *Saat ini Kepala Departemen Operasional hanya satu userid tanpa pengganti.*
    Jawaban: ______
13. **Apakah ada hak akses menu per modul di SIAP** (mis. "boleh membuka modul CRF") yang harus
    dicek sebelum halaman CRF tampil?
    Jawaban: ______
14. **PIC CRF** didaftarkan Admin per kategori di menu Kategori CRF (tidak lagi diturunkan dari
    Helpdesk). Apakah cara ini sesuai, atau PIC harus dibaca dari data SIAP?
    Jawaban: ______

## E. Tampilan, menu, dan layout

15. **Versi Bootstrap dan Bootstrap Icons di SIAP?** CRF memakai 5.3.3 dan 1.11.3.
    Apakah Bootstrap JS (bundle) dimuat di semua halaman? *CRF membutuhkannya untuk modal,
    dropdown, dan dialog konfirmasi.*
    Jawaban: ______
16. **Apakah SIAP memuat CSS global** yang menimpa `body`, `a`, `button`, `.btn`, `.card`,
    `.form-control`? CRF sudah membatasi aturan umum ke `.crf-module`; kami perlu tahu bila ada
    bentrok dari sisi SIAP.
    Jawaban: ______
17. **Siapa yang memegang `<title>`, favicon, breadcrumb, dan judul halaman** (CRF memberi
    `$pageTitle`)? Apakah SIAP butuh format tertentu?
    Jawaban: ______
18. **Menu CRF** — struktur sekarang: satu grup **"Change Request (CRF)"** dengan item
    Dashboard CRF, Kategori CRF, Verifikasi CMO, Tindak Lanjut Otomasi, Persetujuan Kepala
    Departemen Operasional, Handling CRF, Form CRF, Pengajuan CRF Saya, dan Forum (tampil sesuai
    peran). Apakah SIAP mendukung struktur ini?
    Jawaban: ______
19. **Widget di dashboard utama SIAP** — apakah dashboard SIAP boleh menampilkan angka
    "CRF yang perlu tindakan Anda"? Bila ya, bentuknya (API, fungsi PHP, atau query)?
    Jawaban: ______

## F. Database

20. **Apakah tabel CRF berada di database terpisah (`crf_sistem`, seperti sekarang) atau
    dipindah ke database SIAP?** Semua tabel CRF berbahasa Indonesia dan berawalan `crf_`
    (`crf_pengajuan`, `crf_lampiran`, `crf_log_aktivitas`, `crf_kategori`, `crf_kategori_pic`,
    `crf_nomor_urut`, `crf_peringatan_sla`, `crf_peran_pengguna`, `crf_forum_komentar`,
    `crf_forum_pembahasan`, `crf_forum_status_baca`, `crf_notifikasi`), sehingga aman digabung
    ke database SIAP. Apakah ada tabel SIAP dengan awalan `crf_`?
    Jawaban: ______
21. **Apakah akun database CRF boleh membaca `siap.tbl_user`** (hak SELECT saja), dan
    kolom mana yang stabil dan tidak akan diubah tanpa kabar? *Kolom yang dipakai: `id`,
    `userid`, `password`, `password_new`, `nama`, `dept`, `divisi`, `email`, `no_wa`, `kpu_kode`.*
    Jawaban: ______
22. **Bagaimana skema database dipasang?** Instalasi baru memakai `database/schema.sql`
    (satu berkas, hasil akhir migrasi 001–024). Perubahan berikutnya diberi nomor
    `025_...` di `database/migrations/`. Apakah SIAP punya mekanisme migrasi sendiri?
    Jawaban: ______
23. **Data CRF yang sudah ada** (pengajuan, lampiran, riwayat): **tidak dibawa**. Modul
    dipasang sebagai modul baru dengan database kosong dan nomor register mulai dari awal.
    Mohon konfirmasi bila ada kebutuhan migrasi data.
    Jawaban: ______

## G. Notifikasi dan email

24. **Apakah SIAP sudah punya sistem notifikasi (lonceng/inbox)?** Bila ya, apakah CRF memakai
    tabel/fungsinya (kami cukup memanggil satu fungsi `notify(user, judul, pesan, url)`), atau
    tetap memakai tabel `crf_notifikasi` CRF?
    Jawaban: ______
25. **Pengiriman email** — pakai SMTP/konfigurasi SIAP yang sama? Apakah ada template atau
    alamat pengirim wajib?
    Jawaban: ______
26. **Apakah SIAP punya penjadwal (cron)?** CRF membutuhkan satu tugas terjadwal tiap 5 menit
    (`database/maintenance/kirim_peringatan_sla.php`) untuk peringatan SLA ke PIC CRF.
    Jawaban: ______

## H. Lampiran, file, dan laporan

27. **Storage lampiran** — tetap memakai Wasabi (bucket dan kredensial baru milik SIAP), atau
    storage file milik SIAP? Lampiran diunggah sebagai objek private dan dibuka lewat
    `actions/download_attachment.php`. Apakah ada batas ukuran/jenis file dan kebijakan
    antivirus? *Sekarang: PDF/JPG/PNG, maksimum 5 MB per file.*
    Jawaban: ______
28. **Ekspor PDF/Excel** — apakah SIAP sudah menyediakan pustaka/layanan ekspor yang wajib
    dipakai?
    Jawaban: ______

## I. Hubungan dengan Helpdesk SIAP

29. **CRF tidak lagi memiliki modul Helpdesk.** Pemohon masuk langsung ke Form CRF. Apakah
    SIAP membutuhkan tautan dari tiket Helpdesk ke Form CRF, dan bila ya, parameter apa yang
    boleh dikirim (mis. nomor tiket)? Saat ini CRF **tidak** memperbarui status tiket.
    Jawaban: ______
30. **Siapa pemilik teknis sisi Helpdesk** yang perlu dihubungi bila alur tiket dan CRF harus
    disambungkan?
    Jawaban: ______

## J. Operasional dan serah terima

31. **Deploy dan lingkungan** — dev, staging, dan produksi; cara deploy; siapa yang melakukan.
    Jawaban: ______
32. **Backup, log, dan pemantauan** — apakah log aktivitas CRF harus masuk ke log pusat SIAP?
    Jawaban: ______
33. **Siapa pemilik teknis modul CRF setelah serah terima**, dan apakah ada standar kode/uji
    yang harus dipenuhi (mis. PSR, linter, uji otomatis)?
    Jawaban: ______
34. **Jadwal dan urutan integrasi** — target tanggal, dan apakah ada periode paralel
    (CRF lama dan modul SIAP berjalan bersamaan)?
    Jawaban: ______

## Pertanyaan yang paling menentukan (jawab dulu)

Nomor **1, 2, 6, 7, 15, 20, dan 24**. Jawaban ketujuh pertanyaan ini menentukan cara memasang
layout, login, gaya, dan database; sisanya mengikuti.

## Yang kami serahkan

| Berkas | Keterangan |
|---|---|
| Source code (zip) | Tanpa `vendor/`, riwayat git, kredensial, dan data |
| `database/schema.sql` | Struktur database kosong + lima kategori CRF bawaan |
| `database/maintenance/` | Skrip peringatan SLA dan alat ekspor |
| `config/*.example.php` | Contoh konfigurasi `wasabi`, `mail`; isi dengan kredensial milik SIAP |
| `WORKFLOW_UPDATE.md`, `alur_crf_lengkap.jpg` | Penjelasan alur dan peran |

Kredensial (Wasabi, email, database) **tidak** ikut dalam paket dan disiapkan terpisah oleh
pihak yang memasang.
