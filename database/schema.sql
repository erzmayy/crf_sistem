-- =====================================================================
-- CRF PROTOTYPE - DATABASE SCHEMA
-- PT Persona Prima Utama (PPU)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS crf_sistem
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE crf_sistem;

-- ---------------------------------------------------------------------
-- Tabel: users
-- prototype CRF.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    userid          VARCHAR(50)     NULL,
    password        VARCHAR(255)    NOT NULL,
    password_new    VARCHAR(75)     NULL,

    nama            VARCHAR(75)     NULL,
    dept            VARCHAR(75)     NULL,
    divisi          VARCHAR(50)     NULL,

    email           VARCHAR(150)    NULL,
    no_wa           VARCHAR(25)     NULL,

    tgl_insert      TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
    lastlogin       DATETIME        NULL,

    ganti_password  ENUM('1','2')   NULL,
    gender          VARCHAR(7)      NULL,

    atasan_id       VARCHAR(10)     NULL,
    atasan_nama     VARCHAR(75)     NULL,
    atasan_telp     VARCHAR(25)     NULL,

    kpu_kode        VARCHAR(3)      NULL,
    kpu_nama        VARCHAR(75)     NULL,
    npp             VARCHAR(50)     NULL,

    status_wa       ENUM('BLM','SDH') NOT NULL DEFAULT 'BLM',
    pusat           ENUM('YES','NO')  NOT NULL DEFAULT 'NO',

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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
                                    'PEMOHON_PIR',
                                    'kadep_operasional',
                                    'CMO_FINAL',
                                    'SELESAI'
                                ) NOT NULL DEFAULT 'PEMOHON',

    sla_value                   DECIMAL(10,2) NULL,
    sla_unit                    ENUM('Menit','Jam','Hari') NULL,
    sla_started_at              DATETIME NULL,
    sla_due_at                  DATETIME NULL,
    automation_started_at       DATETIME NULL,
    automation_completed_at     DATETIME NULL,

    tanggapan_tindak_lanjut     TEXT            NULL,

    approval_at                 DATETIME        NULL,
    kadep_operasional_approved_by        INT UNSIGNED    NULL,
    kadep_operasional_approved_at        DATETIME        NULL,
    kadep_operasional_approval_note      TEXT            NULL,
    solved_at                   DATETIME        NULL,
    cancelled_at                DATETIME        NULL,

    forum_resolved_at           DATETIME        NULL,
    forum_resolved_by_name      VARCHAR(150)    NULL,

    created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_crf_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_crf_kadep_operasional_approved_by
        FOREIGN KEY (kadep_operasional_approved_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Tabel: crf_sequence
-- Penghitung nomor register yang aman untuk pengajuan bersamaan.
-- ---------------------------------------------------------------------
CREATE TABLE crf_sequence (
    year        CHAR(2) NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

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
    uploaded_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_attachment_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
    UNIQUE KEY uq_crf_user_role_user (user_id),
    CONSTRAINT fk_crf_user_roles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

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
    reply_to_comment_id     INT UNSIGNED NULL,
    is_system               TINYINT(1) NOT NULL DEFAULT 0,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at               DATETIME NULL,
    deleted_at              DATETIME NULL,
    deleted_by_name         VARCHAR(150) NULL,

    KEY idx_forum_comments_crf_created (change_request_id, created_at, id),
    KEY idx_forum_comments_crf_id (change_request_id, id),
    KEY idx_forum_comments_user (user_id),
    CONSTRAINT fk_forum_comments_crf
        FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_forum_comments_reply
        FOREIGN KEY (reply_to_comment_id) REFERENCES forum_comments(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

CREATE TABLE forum_comment_mentions (
    forum_comment_id    INT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NOT NULL,

    PRIMARY KEY (forum_comment_id, user_id),
    KEY idx_forum_comment_mentions_user (user_id),
    CONSTRAINT fk_forum_comment_mentions_comment
        FOREIGN KEY (forum_comment_id) REFERENCES forum_comments(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE forum_attachments (
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

-- ---------------------------------------------------------------------
-- DATA DUMMY
-- ---------------------------------------------------------------------

-- User 
INSERT INTO users (
    id,
    userid,
    password,
    nama,
    dept,
    divisi,
    email,
    no_wa
) VALUES (
    1,
    'USER001',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i',
    'User Demo',
    'Departemen Teknologi Informasi',
    'IT Support',
    'user@ppu.test',
    '081234567890'
);

-- Akun demo kedua
INSERT INTO users (
    id,
    userid,
    password,
    nama,
    dept,
    divisi,
    email,
    no_wa
) VALUES (
    2,
    'ADMIN001',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i',
    'Admin CRF',
    'Departemen Operasional',
    'Divisi Otomasi',
    'admin@ppu.test',
    NULL
);

-- Akun demo CMO
INSERT INTO users (id, userid, password, nama, dept, divisi, email, no_wa) VALUES
(3, 'CMO001', '$2y$10$92IXUNpkj0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i', 'CMO Demo', 'CMO', 'Pemimpin Divisi', 'cmo@ppu.test', '081234567893');

-- Akun demo Otomasi
INSERT INTO users (id, userid, password, nama, dept, divisi, email, no_wa) VALUES
(4, 'OTOMASI001', '$2y$10$92IXUNpkj0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i', 'Otomasi Demo', 'Departemen Operasional', 'Divisi Otomasi', 'otomasi@ppu.test', '081234567894');

-- Akun demo Pak Joko
INSERT INTO users (id, userid, password, nama, dept, divisi, email, no_wa) VALUES
(5, 'JOKO001', '$2y$10$92IXUNpkj0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i', 'Pak Joko Demo', 'Departemen Operasional', 'Pemimpin Departemen', 'joko@ppu.test', '081234567895');

INSERT INTO crf_user_roles (user_id, role) VALUES
(1, 'pemohon'),
(2, 'admin'),
(3, 'cmo'),
(4, 'otomasi'),
(5, 'kadep_operasional');


-- =====================================================================
-- INTEGRASI HELPDESK & CRF
-- (isi sama dengan helpdesk_crf_integration_migration.sql)
-- =====================================================================
-- Integrasi CRF dengan modul Helpdesk.
-- Menambahkan: master Kategori Helpdesk + PIC, ticket Helpdesk,
-- master Kategori CRF + Handling Kategori, notifikasi, relasi
-- ticket <-> CRF, assignment handler, dan hasil SLA.
-- Aman dijalankan ulang pada database crf_sistem.

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
-- 1. Penghitung nomor umum (nomor ticket Helpdesk HD-YYYY-NNNNN)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_sequences (
    name        VARCHAR(30) NOT NULL,
    year        CHAR(4) NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- 2. Master Kategori CRF + Handling Kategori
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
-- 3. Master Kategori Helpdesk + PIC
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS helpdesk_categories (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                    VARCHAR(100) NOT NULL,
    description             VARCHAR(255) NULL,
    icon                    VARCHAR(50) NOT NULL DEFAULT 'bi-tag',
    requires_crf            TINYINT(1) NOT NULL DEFAULT 0,
    default_crf_category_id INT UNSIGNED NULL,
    sla_value               DECIMAL(10,2) NULL,
    sla_unit                ENUM('Menit','Jam','Hari') NULL,
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    sort_order              INT NOT NULL DEFAULT 0,
    deleted_at              DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_helpdesk_categories_name (name),
    CONSTRAINT fk_helpdesk_categories_crf_category
        FOREIGN KEY (default_crf_category_id) REFERENCES crf_categories (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS helpdesk_category_pics (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    helpdesk_category_id INT UNSIGNED NOT NULL,
    user_id              INT UNSIGNED NOT NULL,
    user_name            VARCHAR(150) NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_helpdesk_category_pics (helpdesk_category_id, user_id),
    KEY idx_helpdesk_category_pics_user (user_id),
    CONSTRAINT fk_helpdesk_category_pics_category
        FOREIGN KEY (helpdesk_category_id) REFERENCES helpdesk_categories (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO helpdesk_categories (name, description, icon, requires_crf, default_crf_category_id, sla_value, sla_unit, sort_order)
SELECT seed.name, seed.description, seed.icon, seed.requires_crf,
       (SELECT id FROM crf_categories WHERE name = seed.crf_category),
       seed.sla_value, seed.sla_unit, seed.sort_order
FROM (
    SELECT 'Aplikasi SIAP' AS name, 'Permintaan perubahan aplikasi SIAP (diteruskan ke CRF)' AS description, 'bi-window-stack' AS icon, 1 AS requires_crf, 'Aplikasi' AS crf_category, 2 AS sla_value, 'Hari' AS sla_unit, 1 AS sort_order
    UNION ALL SELECT 'User ID dan Reset Password', 'Pembuatan user ID dan reset password', 'bi-lock', 0, NULL, 4, 'Jam', 2
    UNION ALL SELECT 'BPJS Managemen', 'Permintaan terkait BPJS', 'bi-heart-pulse', 0, NULL, 1, 'Hari', 3
    UNION ALL SELECT 'Tele Sales & Call Center', 'Permintaan tele sales & call center', 'bi-telephone', 0, NULL, 1, 'Hari', 4
    UNION ALL SELECT 'Penggunaan Ruang Rapat', 'Peminjaman ruang rapat', 'bi-building', 0, NULL, 4, 'Jam', 5
    UNION ALL SELECT 'Penggunaan Mobil PPU', 'Peminjaman kendaraan operasional', 'bi-truck', 0, NULL, 4, 'Jam', 6
    UNION ALL SELECT 'Peminjaman Barang IT', 'Peminjaman laptop, proyektor, dan perangkat IT', 'bi-laptop', 0, NULL, 4, 'Jam', 7
    UNION ALL SELECT 'Pembuatan Link ZOOM', 'Pembuatan link meeting online', 'bi-camera-video', 0, NULL, 2, 'Jam', 8
    UNION ALL SELECT 'Jaringan & Internet', 'Gangguan jaringan dan internet', 'bi-wifi', 0, NULL, 4, 'Jam', 9
    UNION ALL SELECT 'Email Perusahaan', 'Pembuatan atau kendala email perusahaan', 'bi-envelope', 0, NULL, 1, 'Hari', 10
    UNION ALL SELECT 'Printer & Perangkat', 'Kendala printer dan perangkat kantor', 'bi-printer', 0, NULL, 1, 'Hari', 11
    UNION ALL SELECT 'Departemen Komersial', 'Permintaan ke Departemen Komersial', 'bi-briefcase', 0, NULL, 2, 'Hari', 12
    UNION ALL SELECT 'Lainnya', 'Permintaan lain', 'bi-three-dots', 0, NULL, 2, 'Hari', 13
) seed
WHERE NOT EXISTS (SELECT 1 FROM helpdesk_categories existing WHERE existing.name = seed.name);

-- ------------------------------------------------------------------
-- 4. Ticket Helpdesk
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS helpdesk_tickets (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id              INT UNSIGNED NOT NULL,
    full_name            VARCHAR(150) NOT NULL,
    phone                VARCHAR(30) NULL,
    email                VARCHAR(150) NULL,
    department           VARCHAR(150) NULL,
    division             VARCHAR(150) NULL,
    helpdesk_category_id INT UNSIGNED NOT NULL,
    request_kind         ENUM('maintenance','request','komplain') NOT NULL DEFAULT 'request',
    report_time          TIME NULL,
    message              TEXT NOT NULL,
    level                ENUM('Tinggi','Sedang','Rendah') NULL,
    follow_up            TEXT NULL,
    status               ENUM('Belum Ditindaklanjuti','Dalam Proses','Diteruskan ke CRF','Selesai','Dibatalkan') NOT NULL DEFAULT 'Belum Ditindaklanjuti',
    sla_value            DECIMAL(10,2) NULL,
    sla_unit             ENUM('Menit','Jam','Hari') NULL,
    handled_by           INT UNSIGNED NULL,
    handled_by_name      VARCHAR(150) NULL,
    handled_at           DATETIME NULL,
    completed_at         DATETIME NULL,
    cancelled_at         DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_helpdesk_tickets_user (user_id),
    KEY idx_helpdesk_tickets_category_status (helpdesk_category_id, status),
    KEY idx_helpdesk_tickets_created (created_at),
    CONSTRAINT fk_helpdesk_tickets_category
        FOREIGN KEY (helpdesk_category_id) REFERENCES helpdesk_categories (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS helpdesk_attachments (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    helpdesk_ticket_id INT UNSIGNED NOT NULL,
    original_name      VARCHAR(255) NOT NULL,
    stored_name        VARCHAR(255) NOT NULL,
    file_path          VARCHAR(500) NOT NULL,
    file_type          VARCHAR(100) NULL,
    file_size          INT UNSIGNED NULL,
    uploaded_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_helpdesk_attachments_ticket (helpdesk_ticket_id),
    CONSTRAINT fk_helpdesk_attachments_ticket
        FOREIGN KEY (helpdesk_ticket_id) REFERENCES helpdesk_tickets (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS helpdesk_activity_logs (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    helpdesk_ticket_id INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NULL,
    actor              VARCHAR(150) NULL,
    activity           VARCHAR(100) NOT NULL,
    old_status         VARCHAR(50) NULL,
    new_status         VARCHAR(50) NULL,
    description        TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_helpdesk_activity_logs_ticket (helpdesk_ticket_id, created_at),
    CONSTRAINT fk_helpdesk_activity_logs_ticket
        FOREIGN KEY (helpdesk_ticket_id) REFERENCES helpdesk_tickets (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- 5. Relasi CRF <-> Helpdesk, kategori dinamis, handler, hasil SLA
-- ------------------------------------------------------------------
CALL crf_add_column('change_requests', 'helpdesk_ticket_id', 'INT UNSIGNED NULL AFTER user_id');
CALL crf_add_column('change_requests', 'crf_category_id', 'INT UNSIGNED NULL AFTER change_category');
CALL crf_add_column('change_requests', 'requester_position', 'VARCHAR(150) NULL AFTER email');
CALL crf_add_column('change_requests', 'assigned_handler_id', 'INT UNSIGNED NULL AFTER workflow_stage');
CALL crf_add_column('change_requests', 'assigned_handler_name', 'VARCHAR(150) NULL AFTER assigned_handler_id');
CALL crf_add_column('change_requests', 'assigned_at', 'DATETIME NULL AFTER assigned_handler_name');
CALL crf_add_column('change_requests', 'sla_actual_minutes', 'INT UNSIGNED NULL AFTER sla_due_at');
CALL crf_add_column('change_requests', 'sla_result', 'ENUM(''Sesuai SLA'',''Melebihi SLA'') NULL AFTER sla_actual_minutes');

CALL crf_add_index('change_requests', 'idx_change_requests_helpdesk_ticket', 'helpdesk_ticket_id');
CALL crf_add_index('change_requests', 'idx_change_requests_crf_category', 'crf_category_id');
CALL crf_add_index('change_requests', 'idx_change_requests_assigned_handler', 'assigned_handler_id');

CALL crf_add_foreign_key('change_requests', 'fk_change_requests_helpdesk_ticket',
    'FOREIGN KEY (helpdesk_ticket_id) REFERENCES helpdesk_tickets (id) ON DELETE SET NULL ON UPDATE CASCADE');
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
-- 6. Audit trail CRF: user & perubahan status
-- ------------------------------------------------------------------
CALL crf_add_column('crf_activity_logs', 'user_id', 'INT UNSIGNED NULL AFTER change_request_id');
CALL crf_add_column('crf_activity_logs', 'old_status', 'VARCHAR(50) NULL AFTER actor');
CALL crf_add_column('crf_activity_logs', 'new_status', 'VARCHAR(50) NULL AFTER old_status');

-- ------------------------------------------------------------------
-- 7. Notifikasi in-app + status email
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    title              VARCHAR(150) NOT NULL,
    message            TEXT NULL,
    url                VARCHAR(255) NULL,
    change_request_id  INT UNSIGNED NULL,
    helpdesk_ticket_id INT UNSIGNED NULL,
    read_at            DATETIME NULL,
    email_status       ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read (user_id, read_at),
    KEY idx_notifications_email_status (email_status),
    CONSTRAINT fk_notifications_change_request
        FOREIGN KEY (change_request_id) REFERENCES change_requests (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_helpdesk_ticket
        FOREIGN KEY (helpdesk_ticket_id) REFERENCES helpdesk_tickets (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE crf_add_column;
DROP PROCEDURE crf_add_index;
DROP PROCEDURE crf_add_foreign_key;
