-- =====================================================================
-- 024: Nama kolom berbahasa Indonesia (standar tim dan SIAP)
-- ---------------------------------------------------------------------
-- Mengganti nama kolom di semua tabel crf_* ke bahasa Indonesia, mis.
--   crf_pengajuan.request_number  -> nomor_register
--   crf_pengajuan.workflow_stage  -> tahap_alur
--   *.change_request_id           -> pengajuan_id
--   *.user_id                     -> pengguna_id
--   *.created_at / updated_at     -> tgl_dibuat / tgl_diubah
-- Daftar lengkap ada pada pernyataan CALL di bawah. Kolom id, status,
-- level, email, dan url tidak diubah.
--
-- Hanya untuk database yang sudah berjalan. Instalasi baru cukup memakai
-- schema.sql (sudah memakai nama baru). RENAME COLUMN mempertahankan data,
-- index, dan foreign key; membutuhkan MySQL 8.0+ atau MariaDB 10.5.2+.
-- Aman dijalankan ulang. Backup dulu, dan WAJIB dijalankan bersamaan
-- dengan kode aplikasi versi baru. Jalankan 023 lebih dulu.
-- =====================================================================

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_rename_column_if_exists;

DELIMITER //

CREATE PROCEDURE crf_rename_column_if_exists(IN p_table VARCHAR(64), IN p_old VARCHAR(64), IN p_new VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_old
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_new
    ) THEN
        SET @crf_rename_sql = CONCAT('ALTER TABLE `', p_table, '` RENAME COLUMN `', p_old, '` TO `', p_new, '`');
        PREPARE crf_rename_stmt FROM @crf_rename_sql;
        EXECUTE crf_rename_stmt;
        DEALLOCATE PREPARE crf_rename_stmt;
    END IF;
END//

DELIMITER ;

CALL crf_rename_column_if_exists('crf_forum_komentar', 'change_request_id', 'pengajuan_id');
CALL crf_rename_column_if_exists('crf_forum_komentar', 'user_id', 'pengguna_id');
CALL crf_rename_column_if_exists('crf_forum_komentar', 'user_name', 'pengguna_nama');
CALL crf_rename_column_if_exists('crf_forum_komentar', 'user_role', 'pengguna_peran');
CALL crf_rename_column_if_exists('crf_forum_komentar', 'comment', 'komentar');
CALL crf_rename_column_if_exists('crf_forum_komentar', 'is_system', 'dari_sistem');
CALL crf_rename_column_if_exists('crf_forum_komentar', 'reply_to_comment_id', 'balasan_untuk_id');
CALL crf_rename_column_if_exists('crf_forum_komentar', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'change_request_id', 'pengajuan_id');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'outcome', 'hasil');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'trigger_source', 'sumber_pemicu');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'opened_by', 'dibuka_oleh');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'opened_by_name', 'dibuka_oleh_nama');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'opened_by_role', 'dibuka_oleh_peran');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'reason', 'alasan');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'before_urgency', 'urgensi_sebelum');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'before_sla_value', 'sla_nilai_sebelum');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'before_sla_unit', 'sla_satuan_sebelum');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'final_urgency', 'urgensi_final');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'final_sla_value', 'sla_nilai_final');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'final_sla_unit', 'sla_satuan_final');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'decided_by', 'diputuskan_oleh');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'decided_by_name', 'diputuskan_oleh_nama');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'decision_note', 'catatan_keputusan');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'decided_at', 'tgl_diputuskan');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'due_at', 'tgl_tenggat');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'reminded_at', 'tgl_diingatkan');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_forum_pembahasan', 'open_crf_id', 'crf_terbuka_id');
CALL crf_rename_column_if_exists('crf_forum_status_baca', 'user_id', 'pengguna_id');
CALL crf_rename_column_if_exists('crf_forum_status_baca', 'change_request_id', 'pengajuan_id');
CALL crf_rename_column_if_exists('crf_forum_status_baca', 'last_read_comment_id', 'komentar_terakhir_dibaca_id');
CALL crf_rename_column_if_exists('crf_forum_status_baca', 'updated_at', 'tgl_diubah');
CALL crf_rename_column_if_exists('crf_kategori', 'name', 'nama');
CALL crf_rename_column_if_exists('crf_kategori', 'description', 'deskripsi');
CALL crf_rename_column_if_exists('crf_kategori', 'legacy_change_category', 'kategori_perubahan_lama');
CALL crf_rename_column_if_exists('crf_kategori', 'sla_value', 'sla_nilai');
CALL crf_rename_column_if_exists('crf_kategori', 'sla_unit', 'sla_satuan');
CALL crf_rename_column_if_exists('crf_kategori', 'sla_tinggi_value', 'sla_tinggi_nilai');
CALL crf_rename_column_if_exists('crf_kategori', 'sla_tinggi_unit', 'sla_tinggi_satuan');
CALL crf_rename_column_if_exists('crf_kategori', 'sla_rendah_value', 'sla_rendah_nilai');
CALL crf_rename_column_if_exists('crf_kategori', 'sla_rendah_unit', 'sla_rendah_satuan');
CALL crf_rename_column_if_exists('crf_kategori', 'is_active', 'aktif');
CALL crf_rename_column_if_exists('crf_kategori', 'sort_order', 'urutan');
CALL crf_rename_column_if_exists('crf_kategori', 'deleted_at', 'tgl_dihapus');
CALL crf_rename_column_if_exists('crf_kategori', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_kategori', 'updated_at', 'tgl_diubah');
CALL crf_rename_column_if_exists('crf_kategori_pic', 'crf_category_id', 'kategori_id');
CALL crf_rename_column_if_exists('crf_kategori_pic', 'user_id', 'pengguna_id');
CALL crf_rename_column_if_exists('crf_kategori_pic', 'user_name', 'pengguna_nama');
CALL crf_rename_column_if_exists('crf_kategori_pic', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_lampiran', 'change_request_id', 'pengajuan_id');
CALL crf_rename_column_if_exists('crf_lampiran', 'original_name', 'nama_asli');
CALL crf_rename_column_if_exists('crf_lampiran', 'stored_name', 'nama_tersimpan');
CALL crf_rename_column_if_exists('crf_lampiran', 'file_path', 'path_file');
CALL crf_rename_column_if_exists('crf_lampiran', 'file_type', 'tipe_file');
CALL crf_rename_column_if_exists('crf_lampiran', 'file_size', 'ukuran_file');
CALL crf_rename_column_if_exists('crf_lampiran', 'category', 'kategori');
CALL crf_rename_column_if_exists('crf_lampiran', 'uploaded_at', 'tgl_unggah');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'change_request_id', 'pengajuan_id');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'user_id', 'pengguna_id');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'activity', 'aktivitas');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'description', 'deskripsi');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'actor', 'pelaksana');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'old_status', 'status_lama');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'new_status', 'status_baru');
CALL crf_rename_column_if_exists('crf_log_aktivitas', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_nomor_urut', 'year', 'tahun');
CALL crf_rename_column_if_exists('crf_nomor_urut', 'last_number', 'nomor_terakhir');
CALL crf_rename_column_if_exists('crf_notifikasi', 'user_id', 'pengguna_id');
CALL crf_rename_column_if_exists('crf_notifikasi', 'title', 'judul');
CALL crf_rename_column_if_exists('crf_notifikasi', 'message', 'pesan');
CALL crf_rename_column_if_exists('crf_notifikasi', 'change_request_id', 'pengajuan_id');
CALL crf_rename_column_if_exists('crf_notifikasi', 'read_at', 'tgl_dibaca');
CALL crf_rename_column_if_exists('crf_notifikasi', 'email_status', 'status_email');
CALL crf_rename_column_if_exists('crf_notifikasi', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_pengajuan', 'request_number', 'nomor_register');
CALL crf_rename_column_if_exists('crf_pengajuan', 'user_id', 'pengguna_id');
CALL crf_rename_column_if_exists('crf_pengajuan', 'full_name', 'nama_pemohon');
CALL crf_rename_column_if_exists('crf_pengajuan', 'phone', 'telepon');
CALL crf_rename_column_if_exists('crf_pengajuan', 'requester_position', 'jabatan_pemohon');
CALL crf_rename_column_if_exists('crf_pengajuan', 'submission_date', 'tgl_pengajuan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'to_department', 'dept_tujuan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'to_division', 'divisi_tujuan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'from_department', 'dept_asal');
CALL crf_rename_column_if_exists('crf_pengajuan', 'from_division', 'divisi_asal');
CALL crf_rename_column_if_exists('crf_pengajuan', 'change_description', 'deskripsi_perubahan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'benefit', 'manfaat');
CALL crf_rename_column_if_exists('crf_pengajuan', 'impact', 'dampak');
CALL crf_rename_column_if_exists('crf_pengajuan', 'request_type', 'jenis_pengajuan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'impact_category', 'kategori_dampak');
CALL crf_rename_column_if_exists('crf_pengajuan', 'reason', 'alasan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'budget_type', 'jenis_anggaran');
CALL crf_rename_column_if_exists('crf_pengajuan', 'budget_amount', 'nilai_anggaran');
CALL crf_rename_column_if_exists('crf_pengajuan', 'change_category', 'kategori_perubahan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'crf_category_id', 'kategori_id');
CALL crf_rename_column_if_exists('crf_pengajuan', 'change_category_detail', 'detail_kategori_perubahan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'alternative_suggestion', 'saran_alternatif');
CALL crf_rename_column_if_exists('crf_pengajuan', 'post_implementation_review', 'tinjauan_pasca_implementasi');
CALL crf_rename_column_if_exists('crf_pengajuan', 'implementation', 'implementasi');
CALL crf_rename_column_if_exists('crf_pengajuan', 'implementation_date', 'tgl_implementasi');
CALL crf_rename_column_if_exists('crf_pengajuan', 'pir_date', 'tgl_pir');
CALL crf_rename_column_if_exists('crf_pengajuan', 'final_urgency_level', 'level_urgensi_final');
CALL crf_rename_column_if_exists('crf_pengajuan', 'sla_value', 'sla_nilai');
CALL crf_rename_column_if_exists('crf_pengajuan', 'sla_unit', 'sla_satuan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'sla_started_at', 'sla_mulai');
CALL crf_rename_column_if_exists('crf_pengajuan', 'sla_due_at', 'sla_tenggat');
CALL crf_rename_column_if_exists('crf_pengajuan', 'sla_actual_minutes', 'sla_menit_aktual');
CALL crf_rename_column_if_exists('crf_pengajuan', 'sla_result', 'sla_hasil');
CALL crf_rename_column_if_exists('crf_pengajuan', 'automation_started_at', 'otomasi_mulai');
CALL crf_rename_column_if_exists('crf_pengajuan', 'automation_completed_at', 'otomasi_selesai');
CALL crf_rename_column_if_exists('crf_pengajuan', 'uat_passed_at', 'tgl_uat_lulus');
CALL crf_rename_column_if_exists('crf_pengajuan', 'implementation_submitted_at', 'tgl_implementasi_diisi');
CALL crf_rename_column_if_exists('crf_pengajuan', 'workflow_stage', 'tahap_alur');
CALL crf_rename_column_if_exists('crf_pengajuan', 'forum_discussion_open', 'pembahasan_forum_terbuka');
CALL crf_rename_column_if_exists('crf_pengajuan', 'assigned_handler_id', 'pic_id');
CALL crf_rename_column_if_exists('crf_pengajuan', 'assigned_handler_name', 'pic_nama');
CALL crf_rename_column_if_exists('crf_pengajuan', 'assigned_at', 'tgl_ditugaskan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'approval_at', 'tgl_persetujuan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'kadep_operasional_approved_by', 'disetujui_kadep_oleh');
CALL crf_rename_column_if_exists('crf_pengajuan', 'kadep_operasional_approved_at', 'tgl_disetujui_kadep');
CALL crf_rename_column_if_exists('crf_pengajuan', 'kadep_operasional_approval_note', 'catatan_persetujuan_kadep');
CALL crf_rename_column_if_exists('crf_pengajuan', 'solved_at', 'tgl_selesai');
CALL crf_rename_column_if_exists('crf_pengajuan', 'cancelled_at', 'tgl_dibatalkan');
CALL crf_rename_column_if_exists('crf_pengajuan', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_pengajuan', 'updated_at', 'tgl_diubah');
CALL crf_rename_column_if_exists('crf_peran_pengguna', 'user_id', 'pengguna_id');
CALL crf_rename_column_if_exists('crf_peran_pengguna', 'role', 'peran');
CALL crf_rename_column_if_exists('crf_peran_pengguna', 'is_active', 'aktif');
CALL crf_rename_column_if_exists('crf_peran_pengguna', 'created_at', 'tgl_dibuat');
CALL crf_rename_column_if_exists('crf_peringatan_sla', 'change_request_id', 'pengajuan_id');
CALL crf_rename_column_if_exists('crf_peringatan_sla', 'alert_type', 'jenis_peringatan');
CALL crf_rename_column_if_exists('crf_peringatan_sla', 'alert_seq', 'urutan_peringatan');
CALL crf_rename_column_if_exists('crf_peringatan_sla', 'sent_at', 'tgl_dikirim');

DROP PROCEDURE IF EXISTS crf_rename_column_if_exists;
