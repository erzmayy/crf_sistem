-- =====================================================================
-- 026: SLA waktu respon PIC CRF
-- ---------------------------------------------------------------------
-- Selain SLA penyelesaian, CRF kini punya target waktu respon: lamanya
-- sejak Kepala Departemen Operasional menyetujui sampai PIC CRF menekan
-- "Mulai kerjakan". Dihitung dalam jam kerja yang sama dengan SLA (config/sla.php).
--
--   crf_kategori   : target respon per urgensi (kosong = tanpa target;
--                    Tinggi/Rendah kosong ikut Normal)
--   crf_pengajuan  : target respon yang dibekukan saat disetujui Kadep,
--                    batas respon, waktu respon, dan hasilnya
--   crf_peringatan_sla : jenis peringatan respon_mendesak / respon_terlambat
--
-- CRF yang sudah berjalan tidak punya target respon (tampil tanpa target).
-- Aman dijalankan ulang. Backup dulu.
-- =====================================================================

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_tambah_kolom_sla_respon;

DELIMITER //

CREATE PROCEDURE crf_tambah_kolom_sla_respon()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'crf_kategori' AND column_name = 'sla_respon_nilai'
    ) THEN
        ALTER TABLE crf_kategori
            ADD COLUMN sla_respon_nilai DECIMAL(10,2) NULL AFTER sla_rendah_satuan,
            ADD COLUMN sla_respon_satuan ENUM('Menit','Jam','Hari') NULL AFTER sla_respon_nilai,
            ADD COLUMN sla_respon_tinggi_nilai DECIMAL(10,2) NULL AFTER sla_respon_satuan,
            ADD COLUMN sla_respon_tinggi_satuan ENUM('Menit','Jam','Hari') NULL AFTER sla_respon_tinggi_nilai,
            ADD COLUMN sla_respon_rendah_nilai DECIMAL(10,2) NULL AFTER sla_respon_tinggi_satuan,
            ADD COLUMN sla_respon_rendah_satuan ENUM('Menit','Jam','Hari') NULL AFTER sla_respon_rendah_nilai;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'crf_pengajuan' AND column_name = 'sla_respon_nilai'
    ) THEN
        ALTER TABLE crf_pengajuan
            ADD COLUMN sla_respon_nilai DECIMAL(10,2) NULL AFTER sla_hasil,
            ADD COLUMN sla_respon_satuan ENUM('Menit','Jam','Hari') NULL AFTER sla_respon_nilai,
            ADD COLUMN sla_respon_tenggat DATETIME NULL AFTER sla_respon_satuan,
            ADD COLUMN sla_respon_at DATETIME NULL AFTER sla_respon_tenggat,
            ADD COLUMN sla_respon_hasil ENUM('Sesuai SLA','Melebihi SLA') NULL AFTER sla_respon_at;
    END IF;
END//

DELIMITER ;

CALL crf_tambah_kolom_sla_respon();
DROP PROCEDURE IF EXISTS crf_tambah_kolom_sla_respon;

ALTER TABLE crf_peringatan_sla
    MODIFY COLUMN jenis_peringatan
    ENUM('pengingat','mendesak','terlambat','respon_mendesak','respon_terlambat') NOT NULL;
