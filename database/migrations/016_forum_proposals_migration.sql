-- Usulan Urgensi & SLA di Forum (usulan -> keputusan, tanpa voting).
--   * Tabel forum_proposals: usulan, keputusan, dan riwayatnya.
--     Satu CRF hanya boleh punya SATU usulan terbuka (status 'menunggu').
--   * forum_comments.is_system: penanda komentar otomatis (usulan/keputusan).
-- Aman dijalankan ulang pada database crf_sistem.

USE crf_sistem;

CREATE TABLE IF NOT EXISTS forum_proposals (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id       INT UNSIGNED NOT NULL,
    kind                    ENUM('urgensi_sla','perpanjangan_sla') NOT NULL DEFAULT 'urgensi_sla',
    status                  ENUM('menunggu','disetujui','ditolak','dibatalkan','kedaluwarsa') NOT NULL DEFAULT 'menunggu',
    -- manual = diajukan user, sistem = dipicu otomatis (SLA kosong), langsung = ditetapkan Admin tanpa usulan
    trigger_source          ENUM('manual','sistem','langsung') NOT NULL DEFAULT 'manual',

    proposed_by             INT UNSIGNED NULL,
    proposed_by_name        VARCHAR(150) NOT NULL,
    proposed_by_role        VARCHAR(50)  NOT NULL,
    proposed_urgency        ENUM('Tinggi','Normal','Rendah') NULL,
    proposed_sla_value      DECIMAL(10,2) NULL,
    proposed_sla_unit       ENUM('Menit','Jam','Hari') NULL,
    reason                  TEXT NOT NULL,

    -- Nilai yang berlaku saat usulan dibuat
    before_urgency          ENUM('Tinggi','Normal','Rendah') NULL,
    before_sla_value        DECIMAL(10,2) NULL,
    before_sla_unit         ENUM('Menit','Jam','Hari') NULL,

    -- Nilai yang akhirnya ditetapkan (bisa berbeda dari usulan)
    final_urgency           ENUM('Tinggi','Normal','Rendah') NULL,
    final_sla_value         DECIMAL(10,2) NULL,
    final_sla_unit          ENUM('Menit','Jam','Hari') NULL,

    decided_by              INT UNSIGNED NULL,
    decided_by_name         VARCHAR(150) NULL,
    decision_note           TEXT NULL,
    decided_at              DATETIME NULL,

    due_at                  DATETIME NULL,
    reminded_at             DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Diisi id CRF selama usulan terbuka dan di-NULL-kan saat ditutup (dikelola
    -- includes/forum_proposals.php). UNIQUE menjamin maksimal satu usulan
    -- terbuka per CRF walau ada dua permintaan bersamaan.
    open_crf_id             INT UNSIGNED NULL,

    UNIQUE KEY uq_forum_proposals_open (open_crf_id),
    KEY idx_forum_proposals_crf (change_request_id, created_at),
    KEY idx_forum_proposals_status_due (status, due_at),
    CONSTRAINT fk_forum_proposals_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS add_forum_comment_is_system;

DELIMITER //

CREATE PROCEDURE add_forum_comment_is_system()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'forum_comments'
          AND COLUMN_NAME = 'is_system'
    ) THEN
        ALTER TABLE forum_comments
            ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER comment;
    END IF;
END//

DELIMITER ;

CALL add_forum_comment_is_system();
DROP PROCEDURE add_forum_comment_is_system;

-- Komentar kesepakatan lama (dibuat otomatis sebelum kolom ini ada).
UPDATE forum_comments
SET is_system = 1
WHERE comment LIKE 'Kesepakatan Forum diperbarui oleh %';
