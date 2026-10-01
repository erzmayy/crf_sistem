-- Menambahkan tanggal implementasi dan tanggal PIR untuk database crf_system yang sudah ada.
-- Aman dijalankan ulang jika kolom sudah tersedia.

USE crf_system;

DROP PROCEDURE IF EXISTS add_implementation_dates_columns;

DELIMITER //

CREATE PROCEDURE add_implementation_dates_columns()
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
END//

DELIMITER ;

CALL add_implementation_dates_columns();
DROP PROCEDURE add_implementation_dates_columns;