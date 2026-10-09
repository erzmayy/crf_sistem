-- =====================================================================
-- CRF PROTOTYPE - DATABASE SCHEMA
-- PT Persona Prima Utama (PPU)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS crf_sistem
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE crf_sistem;

-- ---------------------------------------------------------------------
-- Data user TIDAK disimpan di database CRF. User dibaca dari
-- siap.tbl_user (lihat config/siap.php). Kolom user_id di tabel lain
-- hanya menyimpan id user SIAP, tanpa foreign key (tbl_user MyISAM).
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Tabel: change_requests
-- Tabel utama, satu baris = satu pengajuan Change Request Form.
-- ---------------------------------------------------------------------
CREATE TABLE change_requests (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number              VARCHAR(50)     NULL UNIQUE,
    user_id                     INT UNSIGNED    NOT NULL,

    full_name                   VARCHAR(150)    NOT NULL,
    phone                       VARCHAR(25)     NOT NULL,
    email                       VARCHAR(150)    NOT NULL,

    submission_date             DATE            NULL,

    to_department               VARCHAR(150)    NOT NULL,
    to_division                 VARCHAR(150)    NULL,

    from_department             VARCHAR(150)    NOT NULL,
    from_division               VARCHAR(150)    NULL,

    -- Catatan: change_description, benefit, impact, reason, dan
    -- change_category dibuat NULLABLE di level database supaya
    -- validasi kelengkapan data dapat ditegakkan oleh kode PHP.
    -- Field-field tersebut WAJIB diisi ketika user menekan
    -- "Submit CRF" - aturan wajib ditegakkan di kode PHP
    -- (actions/submit_crf.php), bukan di skema database.
    change_description          TEXT            NULL,
    benefit                     TEXT            NULL,
    impact                      TEXT            NULL,
    request_type                VARCHAR(50)     NULL,
    impact_category             VARCHAR(50)     NULL,
    reason                      TEXT            NULL,

    budget_type                 ENUM('rkap','boq_pks','anggaran_baru') NULL,
    budget_amount                DECIMAL(18,2)  NULL,

    change_category             ENUM('Aplikasi','Infrastruktur','Proses','Security','Lainnya') NULL,
    change_category_detail      VARCHAR(255)    NULL,

    alternative_suggestion      TEXT            NULL,

    post_implementation_review  TEXT            NULL,
    implementation              TEXT            NULL,
    implementation_date         DATE            NULL,
    pir_date                    DATE            NULL,

    level                       ENUM('Tinggi','Normal','Rendah') NULL DEFAULT NULL,
    final_urgency_level         ENUM('Tinggi','Normal','Rendah') NULL DEFAULT NULL,
    status                      ENUM(
                                    'Draft',
                                    'Belum Ditindak Lanjuti',
                                    'Perlu Revisi',
                                    'Dalam Proses',
                                    'Solve',
                                    'Cancel'
                                ) NOT NULL DEFAULT 'Belum Ditindak Lanjuti',

    workflow_stage              ENUM(
                                    'PEMOHON',
                                    'CMO_FILTER',
                                    'OTOMASI',
                                    'UAT',
                                    'PEMOHON_PIR',
                                    'kadep_operasional',
                                    'CMO_FINAL',
                                    'SELESAI'
                                ) NOT NULL DEFAULT 'PEMOHON',

    -- 1 selama CRF menunggu pembahasan Forum (CMO belum boleh meneruskan ke Kadep).
    forum_discussion_open       TINYINT(1)      NOT NULL DEFAULT 0,

    sla_value                   DECIMAL(10,2) NULL,
    sla_unit                    ENUM('Menit','Jam','Hari') NULL,
    sla_started_at              DATETIME NULL,
    sla_due_at                  DATETIME NULL,
    automation_started_at       DATETIME NULL,
    automation_completed_at     DATETIME NULL,
    -- UAT oleh CMO setelah eksekusi Otomasi (tidak dihitung dalam SLA).
    uat_passed_at               DATETIME NULL,
    implementation_submitted_at DATETIME NULL,

    tanggapan_tindak_lanjut     TEXT            NULL,

    approval_at                 DATETIME        NULL,
    kadep_operasional_approved_by        INT UNSIGNED    NULL,
    kadep_operasional_approved_at        DATETIME        NULL,
    kadep_operasional_approval_note      TEXT            NULL,
    solved_at                   DATETIME        NULL,
    cancelled_at                DATETIME        NULL,

    created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: crf_sequence
-- Penghitung nomor register yang aman untuk pengajuan bersamaan.
-- ---------------------------------------------------------------------
CREATE TABLE crf_sequence (
    year        CHAR(2) NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT INTO crf_sequence (id, last_number) VALUES (1, 0);

-- ---------------------------------------------------------------------
-- Tabel: attachments
-- Bukti dan informasi pendukung yang diupload user. Satu CRF bisa
-- punya beberapa file, karena itu dipisah ke tabel sendiri.
-- ---------------------------------------------------------------------
CREATE TABLE attachments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id   INT UNSIGNED    NOT NULL,
    original_name       VARCHAR(255)    NOT NULL,
    stored_name         VARCHAR(255)    NOT NULL,
    file_path           VARCHAR(500)    NOT NULL,
    file_type           VARCHAR(100)    NULL,
    file_size           INT UNSIGNED    NULL,
    category            VARCHAR(20)     NULL,   -- 'uat' = dokumen hasil UAT; NULL = lampiran pengajuan
    uploaded_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_attachment_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: crf_activity_logs
-- Timeline aktivitas setiap pengajuan CRF.
-- ---------------------------------------------------------------------
CREATE TABLE crf_activity_logs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id   INT UNSIGNED NOT NULL,
    activity            VARCHAR(100) NOT NULL,
    description         TEXT         NOT NULL,
    actor               VARCHAR(150) NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_activity_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: crf_user_roles
-- Mapping role workflow CRF terhadap akun prototype.
-- ---------------------------------------------------------------------
CREATE TABLE crf_user_roles (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    role                ENUM('pemohon','cmo','otomasi','kadep_operasional','admin') NOT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crf_user_role_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: forum_comments dan forum_read_states
-- Komentar diskusi CRF dan posisi baca terakhir per user.
-- ---------------------------------------------------------------------
CREATE TABLE forum_comments (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id       INT UNSIGNED NOT NULL,
    user_id                 INT UNSIGNED NOT NULL,
    user_name               VARCHAR(150) NOT NULL,
    user_role               VARCHAR(50) NOT NULL,
    comment                 TEXT NOT NULL,
    is_system               TINYINT(1) NOT NULL DEFAULT 0,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_read_states (
    user_id                     INT UNSIGNED NOT NULL,
    change_request_id           INT UNSIGNED NOT NULL,
    last_read_comment_id        INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, change_request_id),
    CONSTRAINT fk_forum_read_states_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pembahasan Level Urgensi & SLA di Forum: CMO mengajukan, Admin mencatat hasil
-- (Tetap / Diubah). Satu pembahasan terbuka per CRF.
CREATE TABLE forum_discussions (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id       INT UNSIGNED NOT NULL,
    status                  ENUM('menunggu','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
    -- Hasil pembahasan: tetap = nilai sistem dipakai, diubah = nilai kesepakatan.
    outcome                 ENUM('tetap','diubah') NULL,
    -- cmo = diajukan CMO saat screening, sistem = dibuka otomatis (SLA default kosong)
    trigger_source          ENUM('cmo','sistem') NOT NULL DEFAULT 'cmo',

    opened_by               INT UNSIGNED NULL,
    opened_by_name          VARCHAR(150) NOT NULL,
    opened_by_role          VARCHAR(50)  NOT NULL,
    reason                  TEXT NOT NULL,

    -- Nilai sebelum dan sesudah hasil pembahasan
    before_urgency          ENUM('Tinggi','Normal','Rendah') NULL,
    before_sla_value        DECIMAL(10,2) NULL,
    before_sla_unit         ENUM('Menit','Jam','Hari') NULL,
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

    -- Diisi id CRF selama pembahasan terbuka dan di-NULL-kan saat ditutup.
    -- UNIQUE menjamin maksimal satu pembahasan terbuka per CRF.
    open_crf_id             INT UNSIGNED NULL,

    UNIQUE KEY uq_forum_discussions_open (open_crf_id),
    KEY idx_forum_discussions_crf (change_request_id, created_at),
    KEY idx_forum_discussions_status_due (status, due_at),
    CONSTRAINT fk_forum_discussions_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- KATEGORI CRF, HANDLER, NOTIFIKASI, SLA
-- =====================================================================
-- Master Kategori CRF + PIC CRF, notifikasi, assignment handler,
-- dan hasil SLA. Aman dijalankan ulang pada database crf_sistem.

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_add_column;
DROP PROCEDURE IF EXISTS crf_add_index;
DROP PROCEDURE IF EXISTS crf_add_foreign_key;

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

CREATE PROCEDURE crf_add_foreign_key(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND CONSTRAINT_NAME = p_name
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) THEN
        SET @crf_sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_name, '` ', p_definition);
        PREPARE crf_stmt FROM @crf_sql;
        EXECUTE crf_stmt;
        DEALLOCATE PREPARE crf_stmt;
    END IF;
END//

DELIMITER ;

-- ------------------------------------------------------------------
-- 1. Master Kategori CRF + PIC CRF
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crf_categories (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                   VARCHAR(100) NOT NULL,
    description            VARCHAR(255) NULL,
    legacy_change_category ENUM('Aplikasi','Infrastruktur','Proses','Security','Lainnya') NOT NULL DEFAULT 'Lainnya',
    sla_value              DECIMAL(10,2) NULL,
    sla_unit               ENUM('Menit','Jam','Hari') NULL,   -- SLA urgensi Normal
    sla_tinggi_value       DECIMAL(10,2) NULL,                -- kosong = ikut Normal
    sla_tinggi_unit        ENUM('Menit','Jam','Hari') NULL,
    sla_rendah_value       DECIMAL(10,2) NULL,                -- kosong = ikut Normal
    sla_rendah_unit        ENUM('Menit','Jam','Hari') NULL,
    is_active              TINYINT(1) NOT NULL DEFAULT 1,
    sort_order             INT NOT NULL DEFAULT 0,
    deleted_at             DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crf_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PIC CRF per kategori (diisi Admin di Master Kategori CRF).
CREATE TABLE IF NOT EXISTS crf_category_handlers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    crf_category_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    user_name       VARCHAR(150) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crf_category_handlers (crf_category_id, user_id),
    KEY idx_crf_category_handlers_user (user_id),
    CONSTRAINT fk_crf_category_handlers_category
        FOREIGN KEY (crf_category_id) REFERENCES crf_categories (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO crf_categories (name, description, legacy_change_category, sla_value, sla_unit, sort_order)
SELECT * FROM (
    SELECT 'Aplikasi' AS name, 'Perubahan aplikasi (SIAP, database, dan aplikasi lain)' AS description, 'Aplikasi' AS legacy, 2 AS sla_value, 'Hari' AS sla_unit, 1 AS sort_order
    UNION ALL SELECT 'Infrastruktur', 'Perubahan infrastruktur (jaringan, server, perangkat)', 'Infrastruktur', 3, 'Hari', 2
    UNION ALL SELECT 'Proses', 'Perubahan proses bisnis pada sistem', 'Proses', 3, 'Hari', 3
    UNION ALL SELECT 'Security', 'Perubahan kewenangan menu, user ID, password', 'Security', 1, 'Hari', 4
    UNION ALL SELECT 'Lainnya', 'Perubahan lain di luar kategori yang tersedia', 'Lainnya', 3, 'Hari', 5
) seed
WHERE NOT EXISTS (SELECT 1 FROM crf_categories existing WHERE existing.name = seed.name);

-- ------------------------------------------------------------------
-- 2. Kategori dinamis, handler, hasil SLA
-- ------------------------------------------------------------------
CALL crf_add_column('change_requests', 'crf_category_id', 'INT UNSIGNED NULL AFTER change_category');
CALL crf_add_column('change_requests', 'requester_position', 'VARCHAR(150) NULL AFTER email');
CALL crf_add_column('change_requests', 'assigned_handler_id', 'INT UNSIGNED NULL AFTER workflow_stage');
CALL crf_add_column('change_requests', 'assigned_handler_name', 'VARCHAR(150) NULL AFTER assigned_handler_id');
CALL crf_add_column('change_requests', 'assigned_at', 'DATETIME NULL AFTER assigned_handler_name');
CALL crf_add_column('change_requests', 'sla_actual_minutes', 'INT UNSIGNED NULL AFTER sla_due_at');
CALL crf_add_column('change_requests', 'sla_result', 'ENUM(''Sesuai SLA'',''Melebihi SLA'') NULL AFTER sla_actual_minutes');

CALL crf_add_index('change_requests', 'idx_change_requests_crf_category', 'crf_category_id');
CALL crf_add_index('change_requests', 'idx_change_requests_assigned_handler', 'assigned_handler_id');

CALL crf_add_foreign_key('change_requests', 'fk_change_requests_crf_category',
    'FOREIGN KEY (crf_category_id) REFERENCES crf_categories (id) ON DELETE RESTRICT ON UPDATE CASCADE');

-- Backfill kategori dinamis dari ENUM lama.
UPDATE change_requests cr
JOIN crf_categories c
  ON c.name = CASE cr.change_category
                WHEN 'Aplikasi' THEN 'Aplikasi'
                WHEN 'Infrastruktur' THEN 'Infrastruktur'
                WHEN 'Proses' THEN 'Proses'
                WHEN 'Security' THEN 'Security'
                WHEN 'Lainnya' THEN 'Lainnya'
              END
SET cr.crf_category_id = c.id
WHERE cr.crf_category_id IS NULL
  AND cr.change_category IS NOT NULL;

-- ------------------------------------------------------------------
-- 3. Audit trail CRF: user & perubahan status
-- ------------------------------------------------------------------
CALL crf_add_column('crf_activity_logs', 'user_id', 'INT UNSIGNED NULL AFTER change_request_id');
CALL crf_add_column('crf_activity_logs', 'old_status', 'VARCHAR(50) NULL AFTER actor');
CALL crf_add_column('crf_activity_logs', 'new_status', 'VARCHAR(50) NULL AFTER old_status');

-- ------------------------------------------------------------------
-- 4. Notifikasi in-app + status email
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    title              VARCHAR(150) NOT NULL,
    message            TEXT NULL,
    url                VARCHAR(255) NULL,
    change_request_id  INT UNSIGNED NULL,
    read_at            DATETIME NULL,
    email_status       ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read (user_id, read_at),
    KEY idx_notifications_email_status (email_status),
    CONSTRAINT fk_notifications_change_request
        FOREIGN KEY (change_request_id) REFERENCES change_requests (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- 5. Peringatan SLA untuk PIC CRF (anti kirim ganda)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crf_sla_alerts (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    change_request_id INT UNSIGNED NOT NULL,
    alert_type        ENUM('pengingat','mendesak','terlambat') NOT NULL,
    alert_seq         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sent_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crf_sla_alert (change_request_id, alert_type, alert_seq),
    CONSTRAINT fk_crf_sla_alerts_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE crf_add_column;
DROP PROCEDURE crf_add_index;
DROP PROCEDURE crf_add_foreign_key;
