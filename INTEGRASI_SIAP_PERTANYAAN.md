# Daftar Pertanyaan Integrasi Modul CRF ke SIAP

Dokumen ini untuk dibahas dengan tim SIAP. Setiap pertanyaan punya **asumsi kami**
(dipakai bila tidak ada jawaban) dan kolom **Jawaban** untuk diisi.

## A. Ringkasan teknis CRF saat ini

| Hal | Kondisi CRF sekarang |
|---|---|
| Bahasa / versi | PHP native 8.2, tanpa framework, MySQL 8 (PDO, prepared statements) |
| Tampilan | Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 (CDN), CSS sendiri `assets/css/style.css` |
| Login | `login.php` sendiri; membaca user dari `siap.tbl_user`; sesi PHP memakai `$_SESSION['user_id']` dan `$_SESSION['active_user']` |
| Peran CRF | Ditentukan kode (`config/siap.php`) dari userid, departemen, dan divisi user SIAP: Pemohon, CMO, Kepala Departemen Operasional, PIC CRF, Admin |
| Database | Database terpisah `crf_sistem` (tabel CRF, Forum, notifikasi, log); user dibaca dari database `siap` |
| Lampiran | Disimpan di storage kompatibel S3 (Wasabi) lewat `aws/aws-sdk-php` |
| Email | PHPMailer 6.9, konfigurasi di `config/mail.php` |
| PDF / Excel | `dompdf/dompdf` untuk PDF; ekspor Excel dibuat sendiri |
| Notifikasi | Tabel `notifications` sendiri (lonceng + email) |
| Tampilan modul | Sudah ada mode `module` (`CRF_LAYOUT_MODE`): hanya area konten, menu tersedia dari `crfNavGroups()` |

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
   Jawaban: ______

## C. Login, sesi, dan identitas

6. **Apakah CRF memakai sesi login SIAP?** Bila ya, apa nama kunci sesinya (ID user, userid,
   nama, departemen, divisi/jabatan)? *Asumsi kami: `user_id` sama dengan ID di `tbl_user`.*
   Jawaban: ______
7. **Berkas apa yang cukup dipanggil di awal halaman** untuk memastikan user sudah login
   (mis. `require 'siap/auth.php'`), dan ke mana user diarahkan bila belum login/sesi habis?
   Jawaban: ______
8. **Apakah `login.php`, `logout.php`, dan `actions/login.php` CRF boleh dihapus?**
   Dan ke URL mana tombol "Keluar" harus mengarah?
   Jawaban: ______
9. **Apakah ada pengaman tambahan** yang harus diikuti (token CSRF global, header keamanan,
   batas waktu sesi, IP whitelist)?
   Jawaban: ______
10. **Akun demo `CRFDEMO` dan login lokal (`config/dev_login.php`)** akan dimatikan di produksi.
    Apakah ada lingkungan staging untuk presentasi?
    Jawaban: ______

## D. Peran dan hak akses

11. **Apakah SIAP punya tabel/mekanisme peran resmi?** Atau peran CRF tetap ditentukan kode CRF
    dari departemen dan divisi (cara sekarang)?
    Jawaban: ______
12. **Siapa yang berhak menetapkan** CMO, Kepala Departemen Operasional, PIC CRF, dan Admin CRF
    (administrator SIAP atau Admin CRF)? Bagaimana bila orangnya berganti jabatan?
    Jawaban: ______
13. **Apakah ada hak akses menu per modul di SIAP** (mis. "boleh membuka modul CRF") yang harus
    dicek sebelum halaman CRF tampil?
    Jawaban: ______
14. **PIC CRF** sudah disinkronkan dari PIC kategori Helpdesk SIAP. Apakah kategori dan PIC
    Helpdesk tetap dikelola di halaman SIAP, dan apakah SIAP mengizinkan kolom/opsi tambahan
    (mis. penanda "butuh CRF" dan kategori CRF bawaan)?
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
18. **Menu CRF** — berapa tingkat submenu yang didukung SIAP? Struktur sekarang: grup
    "Dashboard", "Help Desk", dan "Change Request (CRF)" dengan 2–7 item per grup.
    Jawaban: ______
19. **Widget di dashboard utama SIAP** — apakah dashboard SIAP boleh menampilkan angka
    "CRF yang perlu tindakan Anda"? Bila ya, bentuknya (API, fungsi PHP, atau query)?
    Jawaban: ______

## F. Database

20. **Apakah tabel CRF berada di database terpisah (`crf_sistem`, seperti sekarang) atau
    dipindah ke database SIAP?** Bila digabung, apakah awalan `crf_` untuk semua tabel cukup
    untuk menghindari bentrok nama?
    Jawaban: ______
21. **Apakah akun database CRF boleh membaca `siap.tbl_user` dan tabel Helpdesk SIAP**
    (hak SELECT saja), dan tabel/kolom mana yang stabil dan tidak akan diubah tanpa kabar?
    Jawaban: ______
22. **Bagaimana migrasi dijalankan?** Saat ini berupa skrip SQL bernomor di
    `database/migrations/` (sampai 018). Apakah SIAP punya mekanisme migrasi sendiri?
    Jawaban: ______
23. **Data CRF yang sudah ada** (pengajuan, lampiran, riwayat) — dibawa ke SIAP atau mulai baru?
    Jawaban: ______

## G. Notifikasi dan email

24. **Apakah SIAP sudah punya sistem notifikasi (lonceng/inbox)?** Bila ya, apakah CRF memakai
    tabel/fungsinya (kami cukup memanggil satu fungsi `notify(user, judul, pesan, url)`), atau
    tetap memakai tabel `notifications` CRF?
    Jawaban: ______
25. **Pengiriman email** — pakai SMTP/konfigurasi SIAP yang sama? Apakah ada template atau
    alamat pengirim wajib?
    Jawaban: ______
26. **Apakah SIAP punya penjadwal (cron)?** CRF memakai pengecekan berkala untuk pengingat PIR
    dan batas waktu Forum.
    Jawaban: ______

## H. Lampiran, file, dan laporan

27. **Storage lampiran** — tetap memakai bucket Wasabi CRF, atau storage file milik SIAP?
    Apakah ada batas ukuran/jenis file dan kebijakan antivirus?
    Jawaban: ______
28. **Ekspor PDF/Excel** — apakah SIAP sudah menyediakan pustaka/layanan ekspor yang wajib
    dipakai?
    Jawaban: ______

## I. Integrasi dengan Helpdesk SIAP

29. **Hubungan tiket Helpdesk dan CRF** — saat ini tiket kategori "butuh CRF" mengarahkan ke
    Form CRF dan status tiket ikut diperbarui. Apakah alur itu sesuai dengan modul Helpdesk SIAP,
    dan siapa pemilik teknis sisi Helpdesk?
    Jawaban: ______
30. **Apakah modul Helpdesk di CRF** (`helpdesk/`) tetap dipakai, atau digantikan modul Helpdesk
    SIAP yang sudah ada?
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
