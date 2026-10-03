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
    sla_unit               ENUM('Menit','Jam','Hari') NULL,
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
    SELECT 'Aplikasi SIAP' AS name, 'Perubahan terkait aplikasi SIAP' AS description, 'Aplikasi' AS legacy, 2 AS sla_value, 'Hari' AS sla_unit, 1 AS sort_order
    UNION ALL SELECT 'Jaringan', 'Perubahan jaringan (LAN/WAN/Internet)', 'Infrastruktur', 1, 'Hari', 2
    UNION ALL SELECT 'Infrastruktur', 'Perubahan infrastruktur', 'Infrastruktur', 3, 'Hari', 3
    UNION ALL SELECT 'Server', 'Perubahan server', 'Infrastruktur', 2, 'Hari', 4
    UNION ALL SELECT 'Database', 'Perubahan struktur atau data database', 'Aplikasi', 2, 'Hari', 5
    UNION ALL SELECT 'Security', 'Perubahan kewenangan menu, user ID, password', 'Security', 1, 'Hari', 6
    UNION ALL SELECT 'Proses', 'Perubahan proses bisnis pada sistem', 'Proses', 3, 'Hari', 7
    UNION ALL SELECT 'Lainnya', 'Perubahan lain di luar kategori yang tersedia', 'Lainnya', 3, 'Hari', 8
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
    SELECT 'Aplikasi SIAP' AS name, 'Permintaan perubahan aplikasi SIAP (diteruskan ke CRF)' AS description, 'bi-window-stack' AS icon, 1 AS requires_crf, 'Aplikasi SIAP' AS crf_category, 2 AS sla_value, 'Hari' AS sla_unit, 1 AS sort_order
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
    ticket_number        VARCHAR(30) NOT NULL,
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
    UNIQUE KEY uq_helpdesk_tickets_number (ticket_number),
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
                WHEN 'Aplikasi' THEN 'Aplikasi SIAP'
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
