-- Tabel komentar dan penanda komentar yang sudah dibaca per user.
-- Mendukung sumber akun CRF lokal maupun SIAP.
-- Aman dijalankan ulang pada database crf_sistem.

USE crf_sistem;

CREATE TABLE IF NOT EXISTS forum_comments (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id       INT UNSIGNED NOT NULL,
    user_id                 INT UNSIGNED NOT NULL,
    user_name               VARCHAR(150) NOT NULL,
    user_role               VARCHAR(50) NOT NULL,
    comment                 TEXT NOT NULL,
    reply_to_comment_id     INT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_forum_comments_crf_created (change_request_id, created_at, id),
    KEY idx_forum_comments_user (user_id),
    CONSTRAINT fk_forum_comments_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_forum_comments_reply
        FOREIGN KEY (reply_to_comment_id) REFERENCES forum_comments(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS forum_read_states (
    user_id                     INT UNSIGNED NOT NULL,
    change_request_id           INT UNSIGNED NOT NULL,
    last_read_comment_id        INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, change_request_id),
    CONSTRAINT fk_forum_read_states_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS remove_forum_local_user_constraints;

DELIMITER //

CREATE PROCEDURE remove_forum_local_user_constraints()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'forum_comments'
          AND CONSTRAINT_NAME = 'fk_forum_comments_user'
    ) THEN
        ALTER TABLE forum_comments
            DROP FOREIGN KEY fk_forum_comments_user;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'forum_read_states'
          AND CONSTRAINT_NAME = 'fk_forum_read_states_user'
    ) THEN
        ALTER TABLE forum_read_states
            DROP FOREIGN KEY fk_forum_read_states_user;
    END IF;
END//

DELIMITER ;

CALL remove_forum_local_user_constraints();
DROP PROCEDURE remove_forum_local_user_constraints;
