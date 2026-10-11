-- =====================================================================
-- 025: Penilaian urgensi berbobot
-- ---------------------------------------------------------------------
-- Pemohon kini boleh mencentang beberapa pernyataan dampak. Skor dihitung
-- dari bobot tiap pernyataan (config/urgensi.php) lalu dipetakan ke Level
-- Urgensi.
--
--   dampak_dipilih : kunci pernyataan yang dicentang, dipisah koma
--   skor_urgensi   : skor berbobot (0-100); NULL untuk CRF lama
--
-- Kolom kategori_dampak tetap dipakai sebagai pernyataan UTAMA (bobot
-- tertinggi) agar tampilan, laporan, dan PDF lama tetap berfungsi.
-- CRF lama dipertahankan: dampak_dipilih diisi dari kategori_dampak dan
-- skor_urgensi dibiarkan kosong (level lama tidak dihitung ulang).
--
-- Aman dijalankan ulang. Backup dulu.
-- =====================================================================

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_tambah_kolom_urgensi;

DELIMITER //

CREATE PROCEDURE crf_tambah_kolom_urgensi()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'crf_pengajuan' AND column_name = 'dampak_dipilih'
    ) THEN
        ALTER TABLE crf_pengajuan ADD COLUMN dampak_dipilih VARCHAR(600) NULL AFTER kategori_dampak;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'crf_pengajuan' AND column_name = 'skor_urgensi'
    ) THEN
        ALTER TABLE crf_pengajuan ADD COLUMN skor_urgensi DECIMAL(5,2) NULL AFTER dampak_dipilih;
    END IF;
END//

DELIMITER ;

CALL crf_tambah_kolom_urgensi();
DROP PROCEDURE IF EXISTS crf_tambah_kolom_urgensi;

UPDATE crf_pengajuan
SET dampak_dipilih = kategori_dampak
WHERE dampak_dipilih IS NULL
  AND kategori_dampak IS NOT NULL
  AND kategori_dampak <> '';
