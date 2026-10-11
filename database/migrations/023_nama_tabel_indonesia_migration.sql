-- =====================================================================
-- 023: Nama tabel berbahasa Indonesia (standar tim dan SIAP)
-- ---------------------------------------------------------------------
-- Semua tabel CRF diberi awalan crf_ dan nama Indonesia:
--
--   change_requests        -> crf_pengajuan
--   attachments            -> crf_lampiran
--   crf_activity_logs      -> crf_log_aktivitas
--   crf_categories         -> crf_kategori
--   crf_category_handlers  -> crf_kategori_pic
--   crf_sequence           -> crf_nomor_urut
--   crf_sla_alerts         -> crf_peringatan_sla
--   crf_user_roles         -> crf_peran_pengguna
--   forum_comments         -> crf_forum_komentar
--   forum_discussions      -> crf_forum_pembahasan
--   forum_read_states      -> crf_forum_status_baca
--   notifications          -> crf_notifikasi
--
-- Hanya untuk database yang sudah berjalan. Instalasi baru cukup memakai
-- schema.sql (sudah memakai nama baru). RENAME TABLE mempertahankan data,
-- index, dan foreign key. Aman dijalankan ulang. Backup dulu.
--
-- Setelah migrasi ini, kode aplikasi versi baru WAJIB dipakai (kode lama
-- masih mencari nama tabel yang lama).
-- =====================================================================

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_rename_table_if_exists;

DELIMITER //

CREATE PROCEDURE crf_rename_table_if_exists(IN p_old VARCHAR(64), IN p_new VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = p_old
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = p_new
    ) THEN
        SET @crf_rename_sql = CONCAT('RENAME TABLE `', p_old, '` TO `', p_new, '`');
        PREPARE crf_rename_stmt FROM @crf_rename_sql;
        EXECUTE crf_rename_stmt;
        DEALLOCATE PREPARE crf_rename_stmt;
    END IF;
END//

DELIMITER ;

CALL crf_rename_table_if_exists('change_requests',       'crf_pengajuan');
CALL crf_rename_table_if_exists('attachments',           'crf_lampiran');
CALL crf_rename_table_if_exists('crf_activity_logs',     'crf_log_aktivitas');
CALL crf_rename_table_if_exists('crf_categories',        'crf_kategori');
CALL crf_rename_table_if_exists('crf_category_handlers', 'crf_kategori_pic');
CALL crf_rename_table_if_exists('crf_sequence',          'crf_nomor_urut');
CALL crf_rename_table_if_exists('crf_sla_alerts',        'crf_peringatan_sla');
CALL crf_rename_table_if_exists('crf_user_roles',        'crf_peran_pengguna');
CALL crf_rename_table_if_exists('forum_comments',        'crf_forum_komentar');
CALL crf_rename_table_if_exists('forum_discussions',     'crf_forum_pembahasan');
CALL crf_rename_table_if_exists('forum_read_states',     'crf_forum_status_baca');
CALL crf_rename_table_if_exists('notifications',         'crf_notifikasi');

DROP PROCEDURE IF EXISTS crf_rename_table_if_exists;
