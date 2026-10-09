-- Modul Helpdesk dihapus dari aplikasi CRF: Helpdesk dipegang modul Helpdesk SIAP
-- (sistem dan database terpisah).
--
--   1. PIC CRF yang selama ini diturunkan dari PIC kategori Helpdesk
--      (crf_category_pic_sources) DISALIN menjadi isian tetap, lalu
--      crf_category_handlers kembali menjadi TABEL biasa (bukan VIEW) dan
--      diisi langsung dari Master Kategori CRF.
--   2. Kolom/relasi ke ticket dilepas: change_requests.helpdesk_ticket_id,
--      notifications.helpdesk_ticket_id. Notifikasi lama yang menaut ke
--      halaman helpdesk/ ikut dihapus (halamannya sudah tidak ada).
--   3. Tabel Helpdesk dihapus PERMANEN: helpdesk_activity_logs,
--      helpdesk_attachments, helpdesk_tickets, helpdesk_category_pics,
--      helpdesk_categories, crf_category_pic_sources, app_sequences.
--      Berkas lampiran ticket di penyimpanan Wasabi (awalan hd_) tidak disentuh.
--
-- BACKUP DATABASE SEBELUM MENJALANKAN. Aman dijalankan ulang.

SET NAMES utf8mb4;
USE crf_sistem;

DROP PROCEDURE IF EXISTS hapus_modul_helpdesk;

DELIMITER //

CREATE PROCEDURE hapus_modul_helpdesk()
BEGIN
    -- 1. crf_category_handlers: VIEW gabungan -> tabel biasa.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'crf_category_handlers'
          AND TABLE_TYPE = 'VIEW'
    ) THEN
        -- Tabel sementara: INSERT ke tabel yang juga dibaca VIEW tidak diizinkan.
        DROP TEMPORARY TABLE IF EXISTS tmp_crf_handlers;
        CREATE TEMPORARY TABLE tmp_crf_handlers AS
            SELECT crf_category_id, user_id, user_name FROM crf_category_handlers;

        INSERT IGNORE INTO crf_category_handlers_manual (crf_category_id, user_id, user_name)
        SELECT crf_category_id, user_id, user_name FROM tmp_crf_handlers;

        DROP TEMPORARY TABLE tmp_crf_handlers;
        DROP VIEW crf_category_handlers;
        RENAME TABLE crf_category_handlers_manual TO crf_category_handlers;
    END IF;

    -- 2. Lepas relasi CRF -> ticket.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'change_requests'
          AND CONSTRAINT_NAME = 'fk_change_requests_helpdesk_ticket' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        ALTER TABLE change_requests DROP FOREIGN KEY fk_change_requests_helpdesk_ticket;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'change_requests'
          AND INDEX_NAME = 'idx_change_requests_helpdesk_ticket'
    ) THEN
        ALTER TABLE change_requests DROP INDEX idx_change_requests_helpdesk_ticket;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'helpdesk_ticket_id'
    ) THEN
        ALTER TABLE change_requests DROP COLUMN helpdesk_ticket_id;
    END IF;

    -- Notifikasi: hapus yang menaut ke halaman Helpdesk, lalu lepas kolom ticket.
    DELETE FROM notifications WHERE url LIKE 'helpdesk/%';

    IF EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
          AND CONSTRAINT_NAME = 'fk_notifications_helpdesk_ticket' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        ALTER TABLE notifications DROP FOREIGN KEY fk_notifications_helpdesk_ticket;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
          AND COLUMN_NAME = 'helpdesk_ticket_id'
    ) THEN
        ALTER TABLE notifications DROP COLUMN helpdesk_ticket_id;
    END IF;
END//

DELIMITER ;

CALL hapus_modul_helpdesk();
DROP PROCEDURE hapus_modul_helpdesk;

-- 3. Tabel Helpdesk (anak dulu, induk belakangan).
DROP TABLE IF EXISTS helpdesk_activity_logs;
DROP TABLE IF EXISTS helpdesk_attachments;
DROP TABLE IF EXISTS crf_category_pic_sources;
DROP TABLE IF EXISTS helpdesk_tickets;
DROP TABLE IF EXISTS helpdesk_category_pics;
DROP TABLE IF EXISTS helpdesk_categories;
DROP TABLE IF EXISTS app_sequences;
