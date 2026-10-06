-- Peringatan SLA untuk PIC CRF.
-- Tabel pencatat peringatan yang sudah dikirim, supaya skrip terjadwal
-- (database/maintenance/kirim_peringatan_sla.php) tidak mengirim ganda.
-- Aman dijalankan ulang.

USE crf_sistem;

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
