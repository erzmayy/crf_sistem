-- Nomor ticket Helpdesk (HD-YYYY-NNNNN) dihapus, mengikuti SIAP yang tidak
-- memakai nomor ticket. Ticket dikenali dari kategori & waktu dibuat.
-- Catatan: teks notifikasi/riwayat lama yang menyebut nomor tetap apa adanya.
-- Aman dijalankan ulang.

USE crf_sistem;

DROP PROCEDURE IF EXISTS drop_helpdesk_ticket_number;

DELIMITER //

CREATE PROCEDURE drop_helpdesk_ticket_number()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'helpdesk_tickets'
          AND INDEX_NAME = 'uq_helpdesk_tickets_number'
    ) THEN
        ALTER TABLE helpdesk_tickets DROP INDEX uq_helpdesk_tickets_number;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'helpdesk_tickets'
          AND COLUMN_NAME = 'ticket_number'
    ) THEN
        ALTER TABLE helpdesk_tickets DROP COLUMN ticket_number;
    END IF;
END //

DELIMITER ;

CALL drop_helpdesk_ticket_number();

DROP PROCEDURE IF EXISTS drop_helpdesk_ticket_number;

-- Penghitung nomor ticket sudah tidak dipakai.
DELETE FROM app_sequences WHERE name = 'helpdesk';
