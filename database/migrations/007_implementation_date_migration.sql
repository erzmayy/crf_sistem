-- Menambahkan tanggal implementasi, tanggal PIR, tipe pengajuan, dan dampak
-- untuk database crf_sistem yang sudah ada.
-- Aman dijalankan ulang jika kolom sudah tersedia.

USE crf_sistem;

DROP PROCEDURE IF EXISTS add_crf_form_columns;

DELIMITER //

CREATE PROCEDURE add_crf_form_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'implementation_date'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN implementation_date DATE NULL AFTER implementation;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'pir_date'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN pir_date DATE NULL AFTER implementation_date;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'request_type'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN request_type VARCHAR(50) NULL AFTER impact;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'impact_category'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN impact_category VARCHAR(50) NULL AFTER request_type;
    ELSE
        ALTER TABLE change_requests
            MODIFY COLUMN impact_category VARCHAR(50) NULL;
    END IF;
END//

DELIMITER ;

CALL add_crf_form_columns();
DROP PROCEDURE add_crf_form_columns;