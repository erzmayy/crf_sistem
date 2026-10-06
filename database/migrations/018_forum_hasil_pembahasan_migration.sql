-- Forum: pembahasan Urgensi & SLA dengan hasil "Tetap" / "Diubah".
--
--   * CMO membuka pembahasan saat screening ("Ajukan Pembahasan Forum").
--     Selama pembahasan terbuka, CRF bertanda "Menunggu Pembahasan Forum" dan
--     CMO tidak dapat meneruskannya ke Kepala Departemen Operasional.
--   * Pengguna Forum hanya berkomentar. Admin mencatat hasilnya: Tetap (nilai
--     sistem dipakai) atau Diubah (nilai kesepakatan), lengkap sebelum/sesudah.
--   * Tidak ada lagi penetapan langsung, usulan oleh pengguna lain, ataupun
--     perpanjangan SLA.
--   * SLA default terisi saat pemohon submit; SLA mulai dihitung saat Kepala
--     Departemen Operasional menyetujui (antrean "Mulai Kerjakan" dihapus).
--
-- Perubahan struktur:
--   forum_proposals  -> forum_discussions  (kolom proposed_* -> opened_*,
--                       kolom usulan nilai & kind dihapus, tambah outcome)
--   change_requests  + forum_discussion_open (penanda "Menunggu Pembahasan Forum")
--
-- Aman dijalankan ulang. Setelah ini jalankan skrip pemeliharaan
-- database/maintenance/hitung_tenggat_sla.php bila ada CRF yang sedang berjalan
-- (menghitung tenggat SLA untuk CRF yang SLA-nya baru dimulai di sini).

SET NAMES utf8mb4;
USE crf_sistem;

DROP PROCEDURE IF EXISTS forum_hasil_pembahasan_migration;

DELIMITER //

CREATE PROCEDURE forum_hasil_pembahasan_migration()
BEGIN
    -- 1. Penanda di CRF.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'change_requests'
          AND COLUMN_NAME = 'forum_discussion_open'
    ) THEN
        ALTER TABLE change_requests
            ADD COLUMN forum_discussion_open TINYINT(1) NOT NULL DEFAULT 0 AFTER workflow_stage;
    END IF;

    -- 2. Tabel usulan lama -> tabel pembahasan.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_proposals'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_discussions'
    ) THEN
        ALTER TABLE forum_proposals
            MODIFY COLUMN status ENUM('menunggu','disetujui','ditolak','dibatalkan','kedaluwarsa','selesai')
                NOT NULL DEFAULT 'menunggu',
            MODIFY COLUMN trigger_source ENUM('manual','sistem','langsung','cmo')
                NOT NULL DEFAULT 'manual',
            ADD COLUMN outcome ENUM('tetap','diubah') NULL AFTER status;

        -- Usulan terbuka hanya bertahan bila CRF masih di verifikasi CMO dan
        -- bukan perpanjangan; selebihnya ditutup karena alurnya sudah berubah.
        UPDATE forum_proposals p
        INNER JOIN change_requests cr ON cr.id = p.change_request_id
        SET p.status = 'dibatalkan',
            p.open_crf_id = NULL,
            p.decided_by_name = COALESCE(p.decided_by_name, 'Sistem'),
            p.decision_note = COALESCE(p.decision_note, 'Ditutup: alur Forum diperbarui (pembahasan hanya diajukan CMO saat verifikasi).'),
            p.decided_at = COALESCE(p.decided_at, NOW())
        WHERE p.status = 'menunggu'
          AND (cr.workflow_stage <> 'CMO_FILTER' OR p.kind = 'perpanjangan_sla');

        UPDATE forum_proposals SET reason = CONCAT('[Perpanjangan SLA] ', reason) WHERE kind = 'perpanjangan_sla';
        UPDATE forum_proposals SET status = 'selesai', outcome = 'diubah' WHERE status = 'disetujui';
        UPDATE forum_proposals SET status = 'selesai', outcome = 'tetap'  WHERE status = 'ditolak';
        UPDATE forum_proposals SET status = 'dibatalkan' WHERE status = 'kedaluwarsa';
        UPDATE forum_proposals SET trigger_source = 'cmo' WHERE trigger_source IN ('manual','langsung');

        RENAME TABLE forum_proposals TO forum_discussions;

        ALTER TABLE forum_discussions
            MODIFY COLUMN status ENUM('menunggu','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
            MODIFY COLUMN trigger_source ENUM('cmo','sistem') NOT NULL DEFAULT 'cmo',
            CHANGE COLUMN proposed_by opened_by INT UNSIGNED NULL,
            CHANGE COLUMN proposed_by_name opened_by_name VARCHAR(150) NOT NULL,
            CHANGE COLUMN proposed_by_role opened_by_role VARCHAR(50) NOT NULL,
            DROP COLUMN proposed_urgency,
            DROP COLUMN proposed_sla_value,
            DROP COLUMN proposed_sla_unit,
            DROP COLUMN kind;
    END IF;
END//

DELIMITER ;

CALL forum_hasil_pembahasan_migration();
DROP PROCEDURE forum_hasil_pembahasan_migration;

-- Instalasi yang belum pernah punya tabel usulan.
CREATE TABLE IF NOT EXISTS forum_discussions (
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
) ENGINE=InnoDB;

-- Penanda "Menunggu Pembahasan Forum" mengikuti pembahasan yang terbuka.
UPDATE change_requests cr
SET cr.forum_discussion_open = IF(
    EXISTS (
        SELECT 1 FROM forum_discussions d
        WHERE d.change_request_id = cr.id AND d.status = 'menunggu'
    ), 1, 0
);

-- SLA default terisi sejak pemohon submit: lengkapi CRF yang belum disetujui
-- Kepala Departemen Operasional tetapi SLA-nya masih kosong.
UPDATE change_requests cr
INNER JOIN crf_categories c ON c.id = cr.crf_category_id
SET
    cr.sla_value = CASE COALESCE(cr.final_urgency_level, cr.level)
        WHEN 'Tinggi' THEN COALESCE(c.sla_tinggi_value, c.sla_value)
        WHEN 'Rendah' THEN COALESCE(c.sla_rendah_value, c.sla_value)
        ELSE c.sla_value
    END,
    cr.sla_unit = CASE COALESCE(cr.final_urgency_level, cr.level)
        WHEN 'Tinggi' THEN IF(c.sla_tinggi_value IS NULL, c.sla_unit, c.sla_tinggi_unit)
        WHEN 'Rendah' THEN IF(c.sla_rendah_value IS NULL, c.sla_unit, c.sla_rendah_unit)
        ELSE c.sla_unit
    END
WHERE cr.status <> 'Draft'
  AND cr.workflow_stage IN ('PEMOHON', 'CMO_FILTER')
  AND cr.kadep_operasional_approved_at IS NULL
  AND cr.sla_value IS NULL;

-- SLA kini mulai saat persetujuan Kepala Departemen Operasional. CRF yang
-- sempat masuk "antrean" (disetujui tetapi belum dimulai) dihitung sejak
-- persetujuannya. Tenggatnya dihitung skrip hitung_tenggat_sla.php.
UPDATE change_requests
SET sla_started_at = kadep_operasional_approved_at,
    automation_started_at = COALESCE(automation_started_at, kadep_operasional_approved_at)
WHERE workflow_stage = 'OTOMASI'
  AND status = 'Dalam Proses'
  AND kadep_operasional_approved_at IS NOT NULL
  AND automation_started_at IS NULL;
