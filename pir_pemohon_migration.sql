-- PIR diisi oleh Pemohon setelah Otomasi mencatat implementasi.
-- Alur: OTOMASI -> PEMOHON_PIR -> CMO_FINAL -> SELESAI.
-- Memastikan enum workflow_stage memuat PEMOHON_PIR.
-- Aman dijalankan ulang.

USE crf_sistem;

ALTER TABLE change_requests
    MODIFY COLUMN workflow_stage ENUM(
        'PEMOHON',
        'CMO_FILTER',
        'OTOMASI',
        'PEMOHON_PIR',
        'kadep_operasional',
        'CMO_FINAL',
        'SELESAI'
    ) NOT NULL DEFAULT 'PEMOHON';
