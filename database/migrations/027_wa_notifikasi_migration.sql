-- =====================================================================
-- Migrasi 027: notifikasi WhatsApp CRF
--
-- Menambah kolom status_wa pada crf_notifikasi. NULL = notifikasi ini tidak
-- dikirim lewat WhatsApp (perilaku lama). 'pending' = menunggu dimasukkan ke
-- antrean WA SIAP (tbl_wa_send) setelah transaksi commit.
--   sent    : sudah masuk antrean tbl_wa_send
--   skipped : WA mati / bukan userid uji / nomor tidak ada / duplikat
--   failed  : gagal menulis ke antrean
--
-- Aman dijalankan ulang. Di server SIAP, jalankan pada database "siap"
-- (hapus baris USE di bawah bila database CRF bukan crf_sistem).
-- =====================================================================

USE crf_sistem;

DROP PROCEDURE IF EXISTS crf_tambah_kolom_status_wa;

DELIMITER //

CREATE PROCEDURE crf_tambah_kolom_status_wa()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'crf_notifikasi' AND column_name = 'status_wa'
    ) THEN
        ALTER TABLE crf_notifikasi
            ADD COLUMN status_wa ENUM('pending','sent','failed','skipped') NULL DEFAULT NULL AFTER status_email,
            ADD KEY idx_notifications_wa_status (status_wa);
    END IF;
END//

DELIMITER ;

CALL crf_tambah_kolom_status_wa();
DROP PROCEDURE IF EXISTS crf_tambah_kolom_status_wa;
