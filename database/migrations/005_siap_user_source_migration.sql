-- =====================================================================
-- CRF - Migrasi awal integrasi user SIAP (opsi B)
-- =====================================================================
-- Tujuan:
-- - CRF tetap memakai database sendiri: crf_sistem
-- - Data user dibaca dari database SIAP: siap.tbl_user
-- - Kolom user_id tetap disimpan sebagai integer, tetapi tidak lagi
--   memakai foreign key ke tabel users lokal.
--
-- Jalankan setelah backup database.
-- =====================================================================

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_drop_fk_if_exists;

DELIMITER //

CREATE PROCEDURE crf_drop_fk_if_exists(
    IN p_table_name VARCHAR(64),
    IN p_constraint_name VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND CONSTRAINT_NAME = p_constraint_name
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE `', p_table_name,
            '` DROP FOREIGN KEY `', p_constraint_name, '`'
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

CALL crf_drop_fk_if_exists('change_requests', 'fk_crf_user');
CALL crf_drop_fk_if_exists('change_requests', 'fk_crf_kadep_operasional_approved_by');
CALL crf_drop_fk_if_exists('change_requests', 'fk_crf_pak_joko_approved_by');
CALL crf_drop_fk_if_exists('crf_user_roles', 'fk_crf_user_roles_user');

DROP PROCEDURE crf_drop_fk_if_exists;

-- Index tetap dipertahankan agar query berdasarkan user_id tetap cepat.
-- Tabel users lokal boleh dibiarkan dulu untuk mode local/demo.
-- Jangan buat FK ke siap.tbl_user karena tbl_user memakai MyISAM.
