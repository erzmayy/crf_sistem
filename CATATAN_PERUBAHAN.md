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

---

## Rencana / belum dikerjakan
- Nomor registrasi (rubrik): menunggu format dari pemilik proses. Perubahan ada di `generateRequestNumber()`, `includes/functions.php`.
- Aturan pemohon tidak boleh memproses CRF miliknya sendiri: menunggu keputusan bisnis.
- Kolom urgensi/SLA di laporan admin dan XLSX.
- Kolom terpisah untuk menyimpan level urgensi sistem asli (butuh migrasi SQL).
