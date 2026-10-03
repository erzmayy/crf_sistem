-- Matriks SLA Kategori CRF x Level Urgensi.
-- Kolom sla_value / sla_unit yang sudah ada dipakai sebagai SLA urgensi Normal.
-- Kolom baru: SLA urgensi Tinggi dan Rendah (kosong = ikut SLA Normal).
-- Aman dijalankan ulang jika kolom sudah tersedia.

USE crf_sistem;

DROP PROCEDURE IF EXISTS add_crf_sla_matrix_columns;

DELIMITER //

CREATE PROCEDURE add_crf_sla_matrix_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'crf_categories'
          AND COLUMN_NAME = 'sla_tinggi_value'
    ) THEN
        ALTER TABLE crf_categories
            ADD COLUMN sla_tinggi_value DECIMAL(10,2) NULL AFTER sla_unit,
            ADD COLUMN sla_tinggi_unit ENUM('Menit','Jam','Hari') NULL AFTER sla_tinggi_value,
            ADD COLUMN sla_rendah_value DECIMAL(10,2) NULL AFTER sla_tinggi_unit,
            ADD COLUMN sla_rendah_unit ENUM('Menit','Jam','Hari') NULL AFTER sla_rendah_value;
    END IF;
END //

DELIMITER ;

CALL add_crf_sla_matrix_columns();

DROP PROCEDURE IF EXISTS add_crf_sla_matrix_columns;
