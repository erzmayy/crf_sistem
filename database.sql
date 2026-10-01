-- =====================================================================
-- CRF PROTOTYPE - DATABASE SCHEMA
-- PT Persona Prima Utama (PPU)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS crf_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE crf_system;

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
