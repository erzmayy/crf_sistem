-- Fase UAT (User Acceptance Test) oleh CMO setelah Otomasi selesai mengeksekusi perubahan.
--
-- Alur:  Kepala Departemen setuju (SLA mulai) -> Otomasi eksekusi (SLA berhenti) -> UAT oleh CMO
--        -> [perlu perbaikan: kembali ke Otomasi lalu UAT ulang] -> lulus -> Otomasi isi implementasi
--        -> Pemohon isi PIR -> CMO finalisasi.
--
--   1. Tahap baru 'UAT' pada change_requests.workflow_stage (milik CMO).
--      Tahap 'OTOMASI' dipakai ulang untuk tiga fase Otomasi yang dibedakan lewat kolom waktu:
--        automation_completed_at kosong                        = eksekusi
--        automation_completed_at ada, uat_passed_at kosong     = perbaikan hasil UAT
--        uat_passed_at ada                                     = isi implementasi
--   2. change_requests.uat_passed_at          : waktu CMO menyatakan UAT lulus.
--   3. change_requests.implementation_submitted_at : waktu Otomasi mengisi implementasi
--      (awal penantian PIR Pemohon).
--   4. attachments.category : 'uat' untuk dokumen hasil UAT (NULL = lampiran pengajuan).
-- Aman dijalankan ulang.

USE crf_sistem;

ALTER TABLE change_requests
    MODIFY COLUMN workflow_stage ENUM(
        'PEMOHON',
        'CMO_FILTER',
        'OTOMASI',
        'UAT',
        'PEMOHON_PIR',
        'kadep_operasional',
        'CMO_FINAL',
        'SELESAI'
    ) NOT NULL DEFAULT 'PEMOHON';

DROP PROCEDURE IF EXISTS crf_uat_add_columns;

DELIMITER //

CREATE PROCEDURE crf_uat_add_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'change_requests' AND COLUMN_NAME = 'uat_passed_at'
    ) THEN
        ALTER TABLE change_requests ADD COLUMN uat_passed_at DATETIME NULL AFTER automation_completed_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'change_requests' AND COLUMN_NAME = 'implementation_submitted_at'
    ) THEN
        ALTER TABLE change_requests ADD COLUMN implementation_submitted_at DATETIME NULL AFTER uat_passed_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'category'
    ) THEN
        ALTER TABLE attachments ADD COLUMN category VARCHAR(20) NULL AFTER file_size;
    END IF;
END//

DELIMITER ;

CALL crf_uat_add_columns();
DROP PROCEDURE crf_uat_add_columns;
