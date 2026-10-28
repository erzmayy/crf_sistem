# Catatan Perubahan Pasca-Zip

Daftar perubahan sejak folder project dikirim (zip) ke atasan. Zip tersebut tidak menyertakan
folder `.git`, jadi file ini dipakai untuk mencocokkan versi yang sudah di-hosting dengan versi terbaru.
Setiap perubahan dicatat: tanggal, apa yang diubah, dan file yang tersentuh.

Cara pakai saat update ke server: salin hanya file yang tercantum di bagian "File" tiap entri,
lalu jalankan migrasi SQL bila entri menyebutkannya.

---

## 2026-10-28

### 1. Detail CRF: kartu skor urgensi mengikuti keputusan CMO/Admin
- **Masalah:** setelah CMO/Admin mengubah level urgensi lewat Forum ("Diubah"), kartu skor di halaman detail
  masih menampilkan skor dan level lama, sementara badge di header sudah level baru.
- **Perubahan:** judul kartu menjadi "Skor urgensi (sistem)". Bila level yang berlaku berbeda dari level
  hitungan sistem, muncul keterangan "Level urgensi diubah dari X ke Y lewat keputusan Forum".
  Skor tetap hasil hitungan sistem saat pengajuan (tidak ditimpa).
- **File:** `includes/partials/detail_permintaan.php`
- **Migrasi SQL:** tidak ada.

### 2. Menu "Pengajuan CRF Saya" tampil untuk semua role
- **Masalah:** role selain pemohon (mis. CMO) tidak punya menu untuk melihat CRF yang ia ajukan sendiri.
  Menu "Form CRF" sebelumnya sudah tampil untuk semua role.
- **Perubahan:** syarat tampil menu dihapus (sebelumnya hanya `pemohon` dan `demo`).
- **File:** `includes/crf_nav.php` (baris menu "Pengajuan CRF Saya")
- **Migrasi SQL:** tidak ada.

### 3. SLA dihitung berdasarkan jam kerja 08:00-17:00
- **Masalah:** SLA sebelumnya hanya melewati Sabtu/Minggu/libur, tetapi 1 hari dihitung 24 jam. CRF yang
  disetujui Jumat sore baru jatuh tempo Senin sore, dan malam hari ikut dihitung.
- **Perubahan:** SLA hanya berjalan Senin-Jumat (bukan libur) pukul 08:00-17:00. "1 Hari" SLA = 1 hari kerja
  (9 jam), jadi Jumat 16:00 + 1 Hari = Senin 16:00. Tampilan durasi ("X Hari Y Jam") ikut memakai 1 hari = 9 jam.
  Tampilan "Lebih cepat / Terlambat" pada CRF selesai kini juga dihitung dalam jam kerja. Hitung mundur di browser
  (`script.js`) disamakan dengan server. Jam kerja diatur di `config/sla.php`.
- **Catatan:** `sla_tenggat` CRF yang sudah berjalan tidak berubah otomatis. Untuk menghitung ulang, jalankan
  `database/maintenance/hitung_tenggat_sla.php`. Durasi diskusi Forum (satuan "Hari") dan hitungan hari tunggu
  PIR ikut memakai 1 hari = 9 jam kerja.
- **File:** `config/sla.php`, `includes/functions.php`, `includes/header.php`, `assets/js/script.js`
- **Migrasi SQL:** tidak ada.

---

## Rencana / belum dikerjakan
- Jeda SLA (status on hold saat menunggu pemohon/vendor) dan eskalasi ke Kadep/CMO saat SLA terlewat: ditunda atas permintaan.
- Nomor registrasi (rubrik): menunggu format dari pemilik proses. Perubahan ada di `generateRequestNumber()`, `includes/functions.php`.
- Aturan pemohon tidak boleh memproses CRF miliknya sendiri: menunggu keputusan bisnis.
- Kolom urgensi/SLA di laporan admin dan XLSX.
- Kolom terpisah untuk menyimpan level urgensi sistem asli (butuh migrasi SQL).
