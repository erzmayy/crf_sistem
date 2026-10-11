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
-- siap.tbl_user (lihat config/siap.php). Kolom pengguna_id di tabel lain
-- hanya menyimpan id user SIAP, tanpa foreign key (tbl_user MyISAM).
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Tabel: crf_pengajuan
-- Tabel utama, satu baris = satu pengajuan Change Request Form.
-- ---------------------------------------------------------------------
CREATE TABLE crf_pengajuan (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nomor_register              VARCHAR(50)     NULL UNIQUE,
    pengguna_id                     INT UNSIGNED    NOT NULL,

    nama_pemohon                   VARCHAR(150)    NOT NULL,
    telepon                       VARCHAR(25)     NOT NULL,
    email                       VARCHAR(150)    NOT NULL,

    tgl_pengajuan             DATE            NULL,

    dept_tujuan               VARCHAR(150)    NOT NULL,
    divisi_tujuan                 VARCHAR(150)    NULL,

    dept_asal             VARCHAR(150)    NOT NULL,
    divisi_asal               VARCHAR(150)    NULL,

    -- Catatan: deskripsi_perubahan, manfaat, dampak, alasan, dan
    -- kategori_perubahan dibuat NULLABLE di level database supaya
    -- validasi kelengkapan data dapat ditegakkan oleh kode PHP.
    -- Field-field tersebut WAJIB diisi ketika user menekan
    -- "Submit CRF" - aturan wajib ditegakkan di kode PHP
    -- (actions/submit_crf.php), bukan di skema database.
    deskripsi_perubahan          TEXT            NULL,
    manfaat                     TEXT            NULL,
    dampak                      TEXT            NULL,
    jenis_pengajuan                VARCHAR(50)     NULL,
    kategori_dampak             VARCHAR(50)     NULL,
    alasan                      TEXT            NULL,

    jenis_anggaran                 ENUM('rkap','boq_pks','anggaran_baru') NULL,
    nilai_anggaran                DECIMAL(18,2)  NULL,

    kategori_perubahan             ENUM('Aplikasi','Infrastruktur','Proses','Security','Lainnya') NULL,
    detail_kategori_perubahan      VARCHAR(255)    NULL,

    saran_alternatif      TEXT            NULL,

    tinjauan_pasca_implementasi  TEXT            NULL,
    implementasi              TEXT            NULL,
    tgl_implementasi         DATE            NULL,
    tgl_pir                    DATE            NULL,

    level                       ENUM('Tinggi','Normal','Rendah') NULL DEFAULT NULL,
    level_urgensi_final         ENUM('Tinggi','Normal','Rendah') NULL DEFAULT NULL,
    status                      ENUM(
                                    'Draft',
                                    'Belum Ditindak Lanjuti',
                                    'Perlu Revisi',
                                    'Dalam Proses',
                                    'Solve',
                                    'Cancel'
                                ) NOT NULL DEFAULT 'Belum Ditindak Lanjuti',

    tahap_alur              ENUM(
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
    pembahasan_forum_terbuka       TINYINT(1)      NOT NULL DEFAULT 0,

    sla_nilai                   DECIMAL(10,2) NULL,
    sla_satuan                    ENUM('Menit','Jam','Hari') NULL,
    sla_mulai              DATETIME NULL,
    sla_tenggat                  DATETIME NULL,
    otomasi_mulai       DATETIME NULL,
    otomasi_selesai     DATETIME NULL,
    -- UAT oleh CMO setelah eksekusi Otomasi (tidak dihitung dalam SLA).
    tgl_uat_lulus               DATETIME NULL,
    tgl_implementasi_diisi DATETIME NULL,

    tanggapan_tindak_lanjut     TEXT            NULL,

    tgl_persetujuan                 DATETIME        NULL,
    disetujui_kadep_oleh        INT UNSIGNED    NULL,
    tgl_disetujui_kadep        DATETIME        NULL,
    catatan_persetujuan_kadep      TEXT            NULL,
    tgl_selesai                   DATETIME        NULL,
    tgl_dibatalkan                DATETIME        NULL,

    tgl_dibuat                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tgl_diubah                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: crf_nomor_urut
-- Penghitung nomor register yang aman untuk pengajuan bersamaan.
-- ---------------------------------------------------------------------
CREATE TABLE crf_nomor_urut (
    tahun        CHAR(2) NOT NULL PRIMARY KEY,
    nomor_terakhir INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT INTO crf_nomor_urut (id, nomor_terakhir) VALUES (1, 0);

-- ---------------------------------------------------------------------
-- Tabel: attachments
-- Bukti dan informasi pendukung yang diupload user. Satu CRF bisa
-- punya beberapa file, karena itu dipisah ke tabel sendiri.
-- ---------------------------------------------------------------------
CREATE TABLE crf_lampiran (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pengajuan_id   INT UNSIGNED    NOT NULL,
    nama_asli       VARCHAR(255)    NOT NULL,
    nama_tersimpan         VARCHAR(255)    NOT NULL,
    path_file           VARCHAR(500)    NOT NULL,
    tipe_file           VARCHAR(100)    NULL,
    ukuran_file           INT UNSIGNED    NULL,
    kategori            VARCHAR(20)     NULL,   -- 'uat' = dokumen hasil UAT; NULL = lampiran pengajuan
    tgl_unggah         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_attachment_crf
        FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: crf_log_aktivitas
-- Timeline aktivitas setiap pengajuan CRF.
-- ---------------------------------------------------------------------
CREATE TABLE crf_log_aktivitas (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pengajuan_id   INT UNSIGNED NOT NULL,
    aktivitas            VARCHAR(100) NOT NULL,
    deskripsi         TEXT         NOT NULL,
    pelaksana               VARCHAR(150) NOT NULL,
    tgl_dibuat          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_activity_crf
        FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: crf_peran_pengguna
-- Mapping peran workflow CRF terhadap akun prototype.
-- ---------------------------------------------------------------------
CREATE TABLE crf_peran_pengguna (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pengguna_id             INT UNSIGNED NOT NULL,
    peran                ENUM('pemohon','cmo','otomasi','kadep_operasional','admin') NOT NULL,
    aktif           TINYINT(1) NOT NULL DEFAULT 1,
    tgl_dibuat          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crf_user_role_user (pengguna_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabel: crf_forum_komentar dan crf_forum_status_baca
-- Komentar diskusi CRF dan posisi baca terakhir per user.
-- ---------------------------------------------------------------------
CREATE TABLE crf_forum_komentar (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pengajuan_id       INT UNSIGNED NOT NULL,
    pengguna_id                 INT UNSIGNED NOT NULL,
    pengguna_nama               VARCHAR(150) NOT NULL,
    pengguna_peran               VARCHAR(50) NOT NULL,
    komentar                 TEXT NOT NULL,
    dari_sistem               TINYINT(1) NOT NULL DEFAULT 0,
    balasan_untuk_id     INT UNSIGNED NULL,
    tgl_dibuat              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_forum_comments_crf_created (pengajuan_id, tgl_dibuat, id),
    KEY idx_forum_comments_user (pengguna_id),
    CONSTRAINT fk_forum_comments_crf
        FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_forum_comments_reply
        FOREIGN KEY (balasan_untuk_id) REFERENCES crf_forum_komentar(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crf_forum_status_baca (
    pengguna_id                     INT UNSIGNED NOT NULL,
    pengajuan_id           INT UNSIGNED NOT NULL,
    komentar_terakhir_dibaca_id        INT UNSIGNED NOT NULL DEFAULT 0,
    tgl_diubah                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (pengguna_id, pengajuan_id),
    CONSTRAINT fk_forum_read_states_crf
        FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pembahasan Level Urgensi & SLA di Forum: CMO mengajukan, Admin mencatat hasil
-- (Tetap / Diubah). Satu pembahasan terbuka per CRF.
CREATE TABLE crf_forum_pembahasan (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pengajuan_id       INT UNSIGNED NOT NULL,
    status                  ENUM('menunggu','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
    -- Hasil pembahasan: tetap = nilai sistem dipakai, diubah = nilai kesepakatan.
    hasil                 ENUM('tetap','diubah') NULL,
    -- cmo = diajukan CMO saat screening, sistem = dibuka otomatis (SLA default kosong)
    sumber_pemicu          ENUM('cmo','sistem') NOT NULL DEFAULT 'cmo',

    dibuka_oleh               INT UNSIGNED NULL,
    dibuka_oleh_nama          VARCHAR(150) NOT NULL,
    dibuka_oleh_peran          VARCHAR(50)  NOT NULL,
    alasan                  TEXT NOT NULL,

    -- Nilai sebelum dan sesudah hasil pembahasan
    urgensi_sebelum          ENUM('Tinggi','Normal','Rendah') NULL,
    sla_nilai_sebelum        DECIMAL(10,2) NULL,
    sla_satuan_sebelum         ENUM('Menit','Jam','Hari') NULL,
    urgensi_final           ENUM('Tinggi','Normal','Rendah') NULL,
    sla_nilai_final         DECIMAL(10,2) NULL,
    sla_satuan_final          ENUM('Menit','Jam','Hari') NULL,

    diputuskan_oleh              INT UNSIGNED NULL,
    diputuskan_oleh_nama         VARCHAR(150) NULL,
    catatan_keputusan           TEXT NULL,
    tgl_diputuskan              DATETIME NULL,

    tgl_tenggat                  DATETIME NULL,
    tgl_diingatkan             DATETIME NULL,
    tgl_dibuat              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Diisi id CRF selama pembahasan terbuka dan di-NULL-kan saat ditutup.
    -- UNIQUE menjamin maksimal satu pembahasan terbuka per CRF.
    crf_terbuka_id             INT UNSIGNED NULL,

    UNIQUE KEY uq_forum_discussions_open (crf_terbuka_id),
    KEY idx_forum_discussions_crf (pengajuan_id, tgl_dibuat),
    KEY idx_forum_discussions_status_due (status, tgl_tenggat),
    CONSTRAINT fk_forum_discussions_crf
        FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan(id)
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
CREATE TABLE IF NOT EXISTS crf_kategori (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama                   VARCHAR(100) NOT NULL,
    deskripsi            VARCHAR(255) NULL,
    kategori_perubahan_lama ENUM('Aplikasi','Infrastruktur','Proses','Security','Lainnya') NOT NULL DEFAULT 'Lainnya',
    sla_nilai              DECIMAL(10,2) NULL,
    sla_satuan               ENUM('Menit','Jam','Hari') NULL,   -- SLA urgensi Normal
    sla_tinggi_nilai       DECIMAL(10,2) NULL,                -- kosong = ikut Normal
    sla_tinggi_satuan        ENUM('Menit','Jam','Hari') NULL,
    sla_rendah_nilai       DECIMAL(10,2) NULL,                -- kosong = ikut Normal
    sla_rendah_satuan        ENUM('Menit','Jam','Hari') NULL,
    aktif              TINYINT(1) NOT NULL DEFAULT 1,
    urutan             INT NOT NULL DEFAULT 0,
    tgl_dihapus             DATETIME NULL,
    tgl_dibuat             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tgl_diubah             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crf_categories_name (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PIC CRF per kategori (diisi Admin di Master Kategori CRF).
CREATE TABLE IF NOT EXISTS crf_kategori_pic (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kategori_id INT UNSIGNED NOT NULL,
    pengguna_id         INT UNSIGNED NOT NULL,
    pengguna_nama       VARCHAR(150) NULL,
    tgl_dibuat      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crf_category_handlers (kategori_id, pengguna_id),
    KEY idx_crf_category_handlers_user (pengguna_id),
    CONSTRAINT fk_crf_category_handlers_category
        FOREIGN KEY (kategori_id) REFERENCES crf_kategori (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO crf_kategori (nama, deskripsi, kategori_perubahan_lama, sla_nilai, sla_satuan, urutan)
SELECT * FROM (
    SELECT 'Aplikasi' AS nama, 'Perubahan aplikasi (SIAP, database, dan aplikasi lain)' AS deskripsi, 'Aplikasi' AS legacy, 2 AS sla_nilai, 'Hari' AS sla_satuan, 1 AS urutan
    UNION ALL SELECT 'Infrastruktur', 'Perubahan infrastruktur (jaringan, server, perangkat)', 'Infrastruktur', 3, 'Hari', 2
    UNION ALL SELECT 'Proses', 'Perubahan proses bisnis pada sistem', 'Proses', 3, 'Hari', 3
    UNION ALL SELECT 'Security', 'Perubahan kewenangan menu, user ID, password', 'Security', 1, 'Hari', 4
    UNION ALL SELECT 'Lainnya', 'Perubahan lain di luar kategori yang tersedia', 'Lainnya', 3, 'Hari', 5
) seed
WHERE NOT EXISTS (SELECT 1 FROM crf_kategori existing WHERE existing.nama = seed.nama);

-- ------------------------------------------------------------------
-- 2. Kategori dinamis, handler, hasil SLA
-- ------------------------------------------------------------------
CALL crf_add_column('crf_pengajuan', 'kategori_id', 'INT UNSIGNED NULL AFTER kategori_perubahan');
CALL crf_add_column('crf_pengajuan', 'jabatan_pemohon', 'VARCHAR(150) NULL AFTER email');
CALL crf_add_column('crf_pengajuan', 'pic_id', 'INT UNSIGNED NULL AFTER tahap_alur');
CALL crf_add_column('crf_pengajuan', 'pic_nama', 'VARCHAR(150) NULL AFTER pic_id');
CALL crf_add_column('crf_pengajuan', 'tgl_ditugaskan', 'DATETIME NULL AFTER pic_nama');
CALL crf_add_column('crf_pengajuan', 'sla_menit_aktual', 'INT UNSIGNED NULL AFTER sla_tenggat');
CALL crf_add_column('crf_pengajuan', 'sla_hasil', 'ENUM(''Sesuai SLA'',''Melebihi SLA'') NULL AFTER sla_menit_aktual');

CALL crf_add_index('crf_pengajuan', 'idx_change_requests_crf_category', 'kategori_id');
CALL crf_add_index('crf_pengajuan', 'idx_change_requests_assigned_handler', 'pic_id');

CALL crf_add_foreign_key('crf_pengajuan', 'fk_change_requests_crf_category',
    'FOREIGN KEY (kategori_id) REFERENCES crf_kategori (id) ON DELETE RESTRICT ON UPDATE CASCADE');

-- (Backfill kategori_id dari ENUM lama tidak diperlukan pada instalasi baru:
-- tabel crf_pengajuan masih kosong. Untuk database lama, lihat migrasi 010.)

-- ------------------------------------------------------------------
-- 3. Audit trail CRF: user & perubahan status
-- ------------------------------------------------------------------
CALL crf_add_column('crf_log_aktivitas', 'pengguna_id', 'INT UNSIGNED NULL AFTER pengajuan_id');
CALL crf_add_column('crf_log_aktivitas', 'status_lama', 'VARCHAR(50) NULL AFTER pelaksana');
CALL crf_add_column('crf_log_aktivitas', 'status_baru', 'VARCHAR(50) NULL AFTER status_lama');

-- ------------------------------------------------------------------
-- 4. Notifikasi in-app + status email
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crf_notifikasi (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengguna_id            INT UNSIGNED NOT NULL,
    judul              VARCHAR(150) NOT NULL,
    pesan            TEXT NULL,
    url                VARCHAR(255) NULL,
    pengajuan_id  INT UNSIGNED NULL,
    tgl_dibaca            DATETIME NULL,
    status_email       ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    tgl_dibuat         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read (pengguna_id, tgl_dibaca),
    KEY idx_notifications_email_status (status_email),
    CONSTRAINT fk_notifications_change_request
        FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- 5. Peringatan SLA untuk PIC CRF (anti kirim ganda)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crf_peringatan_sla (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id INT UNSIGNED NOT NULL,
    jenis_peringatan        ENUM('pengingat','mendesak','terlambat') NOT NULL,
    urutan_peringatan         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    tgl_dikirim           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crf_sla_alert (pengajuan_id, jenis_peringatan, urutan_peringatan),
    CONSTRAINT fk_crf_sla_alerts_crf
        FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE crf_add_column;
DROP PROCEDURE crf_add_index;
DROP PROCEDURE crf_add_foreign_key;
