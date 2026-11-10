-- =====================================================================
-- 028: Response Time per tahap (CMO dan Kepala Departemen Operasional)
-- ---------------------------------------------------------------------
-- Semua Response Time disatukan di satu tabel, satu baris per "putaran" tahap
-- (UAT dan verifikasi bisa berulang). Response Time PIC (kolom sla_respon_* di
-- crf_pengajuan, migrasi 026) tetap ditulis seperti sebelumnya dan disalin ke
-- tabel ini; targetnya tetap dari matriks kategori.
--
--   CMO_VERIFIKASI : CRF masuk antrean CMO  -> CMO mengambil keputusan pertama
--   KADEP          : CRF masuk antrean Kadep -> Kadep menyetujui / menolak
--   PIC_MULAI      : Kadep menyetujui        -> PIC menekan Mulai kerjakan
--   CMO_UAT        : CRF dikirim ke UAT      -> CMO menyatakan lulus / perbaikan
--   CMO_FINAL      : Pemohon mengisi PIR     -> CMO finalisasi
--
-- Tabel:
--   crf_sla_respon        : jam per putaran (mulai, tenggat, respon, hasil)
--   crf_sla_respon_target : target per tahap x urgensi (diatur Admin)
--
-- CMO dan Kadep hanya terukur untuk CRF baru; Response Time PIC CRF lama disalin. Aman dijalankan ulang. Backup dulu.
-- =====================================================================

USE crf_sistem;

CREATE TABLE IF NOT EXISTS crf_sla_respon (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pengajuan_id    INT UNSIGNED NOT NULL,
    tahap           ENUM('CMO_VERIFIKASI','KADEP','PIC_MULAI','CMO_UAT','CMO_FINAL') NOT NULL,
    putaran         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    urgensi         ENUM('Tinggi','Normal','Rendah') NULL,
    target_nilai    DECIMAL(10,2) NULL,
    target_satuan   ENUM('Menit','Jam','Hari') NULL,
    mulai           DATETIME NOT NULL,
    tenggat         DATETIME NULL,
    respon_at       DATETIME NULL,
    respon_oleh     INT UNSIGNED NULL,
    respon_oleh_nama VARCHAR(150) NULL,
    hasil           ENUM('Sesuai SLA','Melebihi SLA','Dibatalkan','Tanpa target') NULL,
    peringatan_pengingat_at DATETIME NULL,   -- anti kirim ganda peringatan (50%, mendesak, terlambat)
    peringatan_mendesak_at  DATETIME NULL,
    peringatan_terlambat_at DATETIME NULL,
    peringatan_terlambat_urutan SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- "terlambat" diulang tiap hari kerja
    PRIMARY KEY (id),
    KEY idx_sla_respon_pengajuan (pengajuan_id, tahap, putaran),
    KEY idx_sla_respon_terbuka (respon_at, tenggat),
    CONSTRAINT fk_sla_respon_crf FOREIGN KEY (pengajuan_id) REFERENCES crf_pengajuan (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crf_sla_respon_target (
    tahap     ENUM('CMO_VERIFIKASI','KADEP','PIC_MULAI','CMO_UAT','CMO_FINAL') NOT NULL,
    urgensi   ENUM('Tinggi','Normal','Rendah') NOT NULL,
    nilai     DECIMAL(10,2) NOT NULL,
    satuan    ENUM('Menit','Jam','Hari') NOT NULL,
    PRIMARY KEY (tahap, urgensi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database yang sempat menjalankan versi awal 028 (tabel target tanpa PIC_MULAI)
ALTER TABLE crf_sla_respon_target
    MODIFY COLUMN tahap ENUM('CMO_VERIFIKASI','KADEP','PIC_MULAI','CMO_UAT','CMO_FINAL') NOT NULL;

-- Usulan awal (perlu persetujuan atasan). UAT dan finalisasi sengaja tanpa target: yang diukur di sana
-- adalah keputusan akhir (lulus/perbaikan, ditutup), bukan sekadar mulai; lamanya tetap dicatat.
-- Target PIC berlaku untuk semua kategori dan harus lebih kecil dari Resolution Time terkecil.
INSERT IGNORE INTO crf_sla_respon_target (tahap, urgensi, nilai, satuan) VALUES
    ('CMO_VERIFIKASI','Tinggi',30,'Menit'), ('CMO_VERIFIKASI','Normal',2,'Jam'), ('CMO_VERIFIKASI','Rendah',4,'Jam'),
    ('KADEP','Tinggi',1,'Jam'),             ('KADEP','Normal',4,'Jam'),           ('KADEP','Rendah',1,'Hari'),
    ('PIC_MULAI','Tinggi',1,'Jam'),         ('PIC_MULAI','Normal',2,'Jam'),       ('PIC_MULAI','Rendah',4,'Jam');

-- Database yang sempat menjalankan versi awal 028 (tanpa PIC_MULAI)
ALTER TABLE crf_sla_respon
    MODIFY COLUMN tahap ENUM('CMO_VERIFIKASI','KADEP','PIC_MULAI','CMO_UAT','CMO_FINAL') NOT NULL;

-- Kolom penanda peringatan tambahan untuk tabel yang dibuat versi awal 028
DROP PROCEDURE IF EXISTS crf_tambah_kolom_peringatan_respon;

DELIMITER //

CREATE PROCEDURE crf_tambah_kolom_peringatan_respon()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'crf_sla_respon' AND column_name = 'peringatan_pengingat_at'
    ) THEN
        ALTER TABLE crf_sla_respon
            ADD COLUMN peringatan_pengingat_at DATETIME NULL AFTER hasil;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'crf_sla_respon' AND column_name = 'peringatan_terlambat_urutan'
    ) THEN
        ALTER TABLE crf_sla_respon
            ADD COLUMN peringatan_terlambat_urutan SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER peringatan_terlambat_at;
    END IF;
END//

DELIMITER ;

CALL crf_tambah_kolom_peringatan_respon();
DROP PROCEDURE crf_tambah_kolom_peringatan_respon;

-- Salin Response Time PIC yang sudah ada (selesai direspons, atau masih menunggu).
-- Penanda peringatan yang sudah terkirim ikut disalin agar tidak terkirim ulang.
INSERT INTO crf_sla_respon
    (pengajuan_id, tahap, putaran, urgensi, target_nilai, target_satuan, mulai, tenggat,
     respon_at, respon_oleh, respon_oleh_nama, hasil, peringatan_mendesak_at, peringatan_terlambat_at)
SELECT cr.id, 'PIC_MULAI', 1, COALESCE(cr.level_urgensi_final, cr.level, 'Normal'),
       cr.sla_respon_nilai, cr.sla_respon_satuan, cr.sla_mulai, cr.sla_respon_tenggat,
       cr.sla_respon_at,
       IF(cr.sla_respon_at IS NULL, NULL, cr.pic_id),
       IF(cr.sla_respon_at IS NULL, NULL, cr.pic_nama),
       CASE WHEN cr.sla_respon_at IS NULL THEN NULL
            WHEN cr.sla_respon_hasil = 'Sesuai SLA' THEN 'Sesuai SLA'
            ELSE 'Melebihi SLA' END,
       (SELECT MIN(p.tgl_dikirim) FROM crf_peringatan_sla p WHERE p.pengajuan_id = cr.id AND p.jenis_peringatan = 'respon_mendesak'),
       (SELECT MIN(p.tgl_dikirim) FROM crf_peringatan_sla p WHERE p.pengajuan_id = cr.id AND p.jenis_peringatan = 'respon_terlambat')
FROM crf_pengajuan cr
WHERE cr.sla_respon_tenggat IS NOT NULL
  AND cr.sla_mulai IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM crf_sla_respon r WHERE r.pengajuan_id = cr.id AND r.tahap = 'PIC_MULAI')
  AND (cr.sla_respon_at IS NOT NULL
       OR (cr.status = 'Dalam Proses' AND cr.tahap_alur = 'OTOMASI' AND cr.pic_id IS NULL AND cr.otomasi_selesai IS NULL));

-- Peringatan "terlambat" yang sudah terkirim dihitung sebagai urutan pertama (tidak terkirim ulang hari yang sama).
UPDATE crf_sla_respon
SET peringatan_terlambat_urutan = 1
WHERE peringatan_terlambat_at IS NOT NULL AND peringatan_terlambat_urutan = 0;
