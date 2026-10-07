-- Portabilitas database: ekspor dari MySQL 8 dapat diimpor ke MySQL 5.7 / MariaDB tanpa galat.
--
-- Yang diperbaiki (aman dijalankan ulang):
--   1. Collation default database dan tabel yang memakai utf8mb4_0900_ai_ci
--      (tidak dikenal MariaDB / MySQL 5.7) dijadikan utf8mb4_unicode_ci.
--   2. Prosedur bantu sisa migrasi dihapus (membawa DEFINER dan ikut terekspor).
--   3. View crf_category_handlers ditulis ulang tanpa CAST ... CHARACTER SET
--      (MySQL menuliskannya "charset utf8mb4", tidak dikenal MariaDB).
--      Isi view tidak berubah.

USE crf_sistem;

ALTER DATABASE crf_sistem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Hanya mengubah tabel yang masih berkolasi 0900 (tabel lain sudah utf8mb4_unicode_ci).
ALTER TABLE crf_sequence       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE forum_discussions  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS crf_add_column;
DROP PROCEDURE IF EXISTS crf_add_index;
DROP PROCEDURE IF EXISTS crf_add_foreign_key;

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
    SELECT m.id                      AS id,
           m.crf_category_id         AS crf_category_id,
           m.user_id                 AS user_id,
           m.user_name               AS user_name,
           m.created_at              AS created_at,
           1                         AS is_manual,
           -- NULL bertipe teks tanpa CAST ... CHARACTER SET (tidak dikenal MariaDB);
           -- NULLIF(x, x) selalu NULL dan mewarisi collation kolom.
           NULLIF(m.user_name, m.user_name) AS source_name
    FROM crf_category_handlers_manual m
    UNION ALL
    SELECT 1000000000 + p.id         AS id,
           s.crf_category_id         AS crf_category_id,
           p.user_id                 AS user_id,
           p.user_name               AS user_name,
           p.created_at              AS created_at,
           0                         AS is_manual,
           hc.name                   AS source_name
    FROM crf_category_pic_sources s
    INNER JOIN helpdesk_categories hc
        ON hc.id = s.helpdesk_category_id AND hc.deleted_at IS NULL
    INNER JOIN helpdesk_category_pics p
        ON p.helpdesk_category_id = s.helpdesk_category_id
) h
GROUP BY h.crf_category_id, h.user_id;
