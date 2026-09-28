-- Menambahkan kolom approval Kepala Departemen Operasional pada database lama.
-- Aman dijalankan ulang jika sebagian/semua kolom sudah tersedia.

USE crf_system;

DROP PROCEDURE IF EXISTS add_kadep_approval_columns;

DELIMITER //

CREATE PROCEDURE add_kadep_approval_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'kadep_operasional_approved_by'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN kadep_operasional_approved_by INT UNSIGNED NULL AFTER approval_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'kadep_operasional_approved_at'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN kadep_operasional_approved_at DATETIME NULL AFTER kadep_operasional_approved_by;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'kadep_operasional_approval_note'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN kadep_operasional_approval_note TEXT NULL AFTER kadep_operasional_approved_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND CONSTRAINT_NAME = 'fk_crf_kadep_operasional_approved_by'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        ALTER TABLE change_requests
            ADD CONSTRAINT fk_crf_kadep_operasional_approved_by
                FOREIGN KEY (kadep_operasional_approved_by) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END//

DELIMITER ;

CALL add_kadep_approval_columns();
DROP PROCEDURE add_kadep_approval_columns;
