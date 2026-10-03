-- =====================================================================
-- CRF PROTOTYPE - DATABASE MIGRATION
-- Jalankan sekali pada database crf_prototype yang sudah terlanjur dibuat
-- dengan versi schema lama.
-- =====================================================================

USE crf_sistem;

ALTER TABLE change_requests
    ADD COLUMN full_name VARCHAR(150) NULL AFTER user_id,
    ADD COLUMN phone VARCHAR(25) NULL AFTER full_name,
    ADD COLUMN email VARCHAR(150) NULL AFTER phone,
    MODIFY COLUMN submission_date DATE NULL,
    MODIFY COLUMN status ENUM(
        'Draft',
        'Belum Ditindak Lanjuti',
        'Perlu Revisi',
        'Dalam Proses',
        'Solve',
        'Cancel'
    ) NOT NULL DEFAULT 'Belum Ditindak Lanjuti';

UPDATE change_requests cr
INNER JOIN users u ON u.id = cr.user_id
SET
    cr.full_name = COALESCE(NULLIF(cr.full_name, ''), u.nama, ''),
    cr.phone = COALESCE(NULLIF(cr.phone, ''), u.no_wa, ''),
    cr.email = COALESCE(NULLIF(cr.email, ''), u.email, '');

ALTER TABLE change_requests
    MODIFY COLUMN full_name VARCHAR(150) NOT NULL,
    MODIFY COLUMN phone VARCHAR(25) NOT NULL,
    MODIFY COLUMN email VARCHAR(150) NOT NULL;

CREATE TABLE IF NOT EXISTS crf_sequence (
    id          TINYINT UNSIGNED PRIMARY KEY,
    last_number SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO crf_sequence (id, last_number)
SELECT 1, COALESCE(MAX(
    CAST(
        SUBSTRING_INDEX(
            SUBSTRING_INDEX(request_number, '.', 3),
            '.',
            -1
        ) AS UNSIGNED
    )
), 0)
FROM change_requests
WHERE request_number LIKE 'PPU-02.4.%'
ON DUPLICATE KEY UPDATE
    last_number = GREATEST(last_number, VALUES(last_number));

UPDATE crf_sequence
SET last_number = GREATEST(
    last_number,
    COALESCE((
        SELECT MAX(
            CAST(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(request_number, '.', 3),
                    '.',
                    -1
                ) AS UNSIGNED
            )
        )
        FROM change_requests
        WHERE request_number LIKE 'PPU-02.4.%'
    ), 0)
)
WHERE id = 1;

CREATE TABLE IF NOT EXISTS crf_activity_logs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id   INT UNSIGNED NOT NULL,
    activity            VARCHAR(100) NOT NULL,
    description         TEXT         NOT NULL,
    actor               VARCHAR(150) NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_activity_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
