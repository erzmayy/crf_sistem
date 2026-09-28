-- Menyelaraskan tahap approval Kepala Departemen Operasional
-- pada database yang masih menggunakan nilai enum PAK_JOKO.

USE crf_system;

ALTER TABLE change_requests
    MODIFY COLUMN workflow_stage ENUM(
        'PEMOHON',
        'CMO_FILTER',
        'OTOMASI',
        'PEMOHON_PIR',
        'PAK_JOKO',
        'kadep_operasional',
        'CMO_FINAL',
        'SELESAI'
    ) NOT NULL DEFAULT 'PEMOHON';

UPDATE change_requests
SET workflow_stage = 'kadep_operasional'
WHERE workflow_stage = 'PAK_JOKO';

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
