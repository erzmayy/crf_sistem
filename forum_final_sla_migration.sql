-- Menambahkan level urgensi final hasil kesepakatan Forum.
-- Aman dijalankan ulang pada database crf_system.

USE crf_system;

DROP PROCEDURE IF EXISTS add_forum_final_urgency_column;

DELIMITER //

CREATE PROCEDURE add_forum_final_urgency_column()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'final_urgency_level'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN final_urgency_level
                ENUM('Tinggi', 'Normal', 'Rendah') NULL
                AFTER level;
    END IF;
END//

DELIMITER ;

CALL add_forum_final_urgency_column();
DROP PROCEDURE add_forum_final_urgency_column;
