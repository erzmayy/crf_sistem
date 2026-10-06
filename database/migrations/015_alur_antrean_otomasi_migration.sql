-- Alur baru CRF:
--   Pemohon -> CMO (filter) -> Kepala Departemen Operasional (approve)
--   -> Antrean Otomasi -> Otomasi "Mulai Kerjakan" (SLA mulai) -> Selesai
--   -> PIR Pemohon -> CMO (final)
--
-- Tahap Otomasi sebelum persetujuan (penetapan SLA oleh Otomasi) dihapus.
-- CRF lama yang masih berada di tahap itu dipindah ke antrean persetujuan
-- Kepala Departemen Operasional. Tidak ada perubahan struktur tabel.
-- Aman dijalankan ulang.

USE crf_sistem;

-- 1. Lengkapi SLA dari matriks kategori bila belum ada (dan belum ada SLA final Forum).
UPDATE change_requests cr
JOIN crf_categories c ON c.id = cr.crf_category_id
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
WHERE cr.workflow_stage = 'OTOMASI'
  AND cr.kadep_operasional_approved_at IS NULL
  AND cr.sla_value IS NULL
  AND cr.final_urgency_level IS NULL;

-- 2. Catat di timeline sebelum tahap dipindah.
INSERT INTO crf_activity_logs
    (change_request_id, activity, description, actor, old_status, new_status)
SELECT
    cr.id,
    'Penyesuaian Alur',
    'Alur CRF diperbarui: penetapan SLA oleh Otomasi dihapus. CRF diteruskan ke Kepala Departemen Operasional untuk persetujuan.',
    'Sistem',
    'Diproses',
    'Menunggu Persetujuan'
FROM change_requests cr
WHERE cr.workflow_stage = 'OTOMASI'
  AND cr.kadep_operasional_approved_at IS NULL
  AND cr.status = 'Dalam Proses';

-- 3. Pindahkan ke antrean persetujuan Kepala Departemen Operasional.
UPDATE change_requests
SET
    workflow_stage = 'kadep_operasional',
    automation_started_at = NULL,
    sla_started_at = NULL,
    sla_due_at = NULL
WHERE workflow_stage = 'OTOMASI'
  AND kadep_operasional_approved_at IS NULL
  AND status = 'Dalam Proses';

-- CRF yang sudah disetujui dan sedang dikerjakan (automation_started_at terisi)
-- tetap berjalan seperti biasa; SLA-nya tidak diubah.
