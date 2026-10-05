-- Penyempurnaan Forum CRF:
-- - Komentar sistem (tidak dihitung sebagai komentar belum dibaca)
-- - Ubah & hapus komentar (soft delete, tetap tersimpan untuk audit)
-- - Mention pengguna (@Nama)
-- - Lampiran komentar
-- - Status pembahasan selesai per CRF
-- Aman dijalankan ulang.

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_add_column;
DROP PROCEDURE IF EXISTS crf_add_index;

DELIMITER //

CREATE PROCEDURE crf_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @crf_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE crf_stmt FROM @crf_sql;
        EXECUTE crf_stmt;
        DEALLOCATE PREPARE crf_stmt;
    END IF;
END//

CREATE PROCEDURE crf_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @crf_sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
        PREPARE crf_stmt FROM @crf_sql;
        EXECUTE crf_stmt;
        DEALLOCATE PREPARE crf_stmt;
    END IF;
END//

DELIMITER ;

CALL crf_add_column('forum_comments', 'is_system', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reply_to_comment_id');
CALL crf_add_column('forum_comments', 'edited_at', 'DATETIME NULL AFTER created_at');
CALL crf_add_column('forum_comments', 'deleted_at', 'DATETIME NULL AFTER edited_at');
CALL crf_add_column('forum_comments', 'deleted_by_name', 'VARCHAR(150) NULL AFTER deleted_at');

CALL crf_add_column('change_requests', 'forum_resolved_at', 'DATETIME NULL');
CALL crf_add_column('change_requests', 'forum_resolved_by_name', 'VARCHAR(150) NULL AFTER forum_resolved_at');

CALL crf_add_index('forum_comments', 'idx_forum_comments_crf_id', 'change_request_id, id');

-- Komentar otomatis hasil penetapan urgensi/SLA sebelum migrasi ini.
UPDATE forum_comments
SET is_system = 1
WHERE is_system = 0
  AND comment LIKE 'Kesepakatan Forum diperbarui oleh %';

CREATE TABLE IF NOT EXISTS forum_comment_mentions (
    forum_comment_id    INT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NOT NULL,

    PRIMARY KEY (forum_comment_id, user_id),
    KEY idx_forum_comment_mentions_user (user_id),
    CONSTRAINT fk_forum_comment_mentions_comment
        FOREIGN KEY (forum_comment_id) REFERENCES forum_comments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS forum_attachments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forum_comment_id    INT UNSIGNED NOT NULL,
    original_name       VARCHAR(255) NOT NULL,
    stored_name         VARCHAR(255) NOT NULL,
    file_path           VARCHAR(500) NOT NULL,
    file_type           VARCHAR(100) NULL,
    file_size           INT UNSIGNED NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_forum_attachments_comment (forum_comment_id),
    CONSTRAINT fk_forum_attachments_comment
        FOREIGN KEY (forum_comment_id) REFERENCES forum_comments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS crf_add_column;
DROP PROCEDURE IF EXISTS crf_add_index;
