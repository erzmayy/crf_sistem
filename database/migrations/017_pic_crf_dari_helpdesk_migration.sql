-- PIC CRF diturunkan dari PIC Kategori Helpdesk (satu tempat pengisian PIC).
--
--   PIC Helpdesk "Aplikasi SIAP"  --(pemetaan)-->  PIC CRF kategori "Aplikasi"
--
--   * crf_category_pic_sources      : pemetaan Kategori CRF <- Kategori Helpdesk.
--   * crf_category_handlers_manual  : PIC CRF yang dulu diisi manual (masa transisi).
--   * crf_category_handlers (VIEW)  : gabungan keduanya, satu baris per (kategori, user).
--     Nama lama dipertahankan agar kode pembaca tidak perlu diubah; penulisan
--     dilakukan ke tabel *_manual / *_pic_sources.
--
-- Aman dijalankan ulang, termasuk pada database yang sudah setengah termigrasi.

SET NAMES utf8mb4;
USE crf_sistem;

CREATE TABLE IF NOT EXISTS crf_category_pic_sources (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    crf_category_id         INT UNSIGNED NOT NULL,
    helpdesk_category_id    INT UNSIGNED NOT NULL,
    created_by_name         VARCHAR(150) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_crf_category_pic_sources (crf_category_id, helpdesk_category_id),
    KEY idx_crf_category_pic_sources_helpdesk (helpdesk_category_id),
    CONSTRAINT fk_crf_pic_sources_crf
        FOREIGN KEY (crf_category_id) REFERENCES crf_categories(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_crf_pic_sources_helpdesk
        FOREIGN KEY (helpdesk_category_id) REFERENCES helpdesk_categories(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS migrate_pic_crf_from_helpdesk;

DELIMITER //

CREATE PROCEDURE migrate_pic_crf_from_helpdesk()
BEGIN
    -- crf_category_handlers masih tabel biasa -> ganti nama menjadi *_manual.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'crf_category_handlers'
          AND TABLE_TYPE = 'BASE TABLE'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'crf_category_handlers_manual'
    ) THEN
        RENAME TABLE crf_category_handlers TO crf_category_handlers_manual;
    END IF;
END//

DELIMITER ;

CALL migrate_pic_crf_from_helpdesk();
DROP PROCEDURE migrate_pic_crf_from_helpdesk;

-- Instalasi yang belum pernah punya tabel handler sama sekali.
CREATE TABLE IF NOT EXISTS crf_category_handlers_manual (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    crf_category_id         INT UNSIGNED NOT NULL,
    user_id                 INT UNSIGNED NOT NULL,
    user_name               VARCHAR(150) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_crf_category_handlers (crf_category_id, user_id),
    KEY idx_crf_category_handlers_user (user_id),
    CONSTRAINT fk_crf_category_handlers_category
        FOREIGN KEY (crf_category_id) REFERENCES crf_categories(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pemetaan awal dari "Kategori CRF default" kategori Helpdesk yang Butuh CRF.
INSERT IGNORE INTO crf_category_pic_sources (crf_category_id, helpdesk_category_id, created_by_name)
SELECT hc.default_crf_category_id, hc.id, 'Migrasi 017'
FROM helpdesk_categories hc
WHERE hc.requires_crf = 1
  AND hc.default_crf_category_id IS NOT NULL
  AND hc.deleted_at IS NULL;

-- Satu baris per (kategori CRF, user). is_manual = 1 bila (juga) diisi manual;
-- sources = nama kategori Helpdesk asal PIC tersebut.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW crf_category_handlers AS
SELECT
    MIN(h.id)               AS id,
    h.crf_category_id       AS crf_category_id,
    h.user_id               AS user_id,
    MAX(h.user_name)        AS user_name,
    MIN(h.created_at)       AS created_at,
    MAX(h.is_manual)        AS is_manual,
    GROUP_CONCAT(DISTINCT h.source_name ORDER BY h.source_name SEPARATOR ', ') AS sources
FROM (
    SELECT m.id, m.crf_category_id, m.user_id, m.user_name, m.created_at,
           1 AS is_manual,
           CAST(NULL AS CHAR(100) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source_name
    FROM crf_category_handlers_manual m
    UNION ALL
    SELECT 1000000000 + p.id, s.crf_category_id, p.user_id, p.user_name, p.created_at,
           0,
           hc.name
    FROM crf_category_pic_sources s
    INNER JOIN helpdesk_categories hc
        ON hc.id = s.helpdesk_category_id AND hc.deleted_at IS NULL
    INNER JOIN helpdesk_category_pics p
        ON p.helpdesk_category_id = s.helpdesk_category_id
) h
GROUP BY h.crf_category_id, h.user_id;
