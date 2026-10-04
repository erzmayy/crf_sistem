-- Kategori Perubahan CRF diseragamkan menjadi 5:
-- Aplikasi, Infrastruktur, Proses, Security, Lainnya.
--
-- - "Aplikasi SIAP" diganti nama menjadi "Aplikasi".
-- - "Jaringan", "Server", "Database" dihapus (soft delete) bila belum
--   dipakai CRF mana pun; bila sudah dipakai, hanya dinonaktifkan agar
--   riwayat CRF lama tetap utuh.
-- Aman dijalankan ulang.

USE crf_sistem;

UPDATE crf_categories
SET name = 'Aplikasi', legacy_change_category = 'Aplikasi'
WHERE name = 'Aplikasi SIAP'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM crf_categories WHERE name = 'Aplikasi') AS existing);

UPDATE crf_categories c
SET c.deleted_at = NOW(), c.is_active = 0
WHERE c.name IN ('Jaringan', 'Server', 'Database')
  AND c.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM change_requests r WHERE r.crf_category_id = c.id);

UPDATE crf_categories
SET is_active = 0
WHERE name IN ('Jaringan', 'Server', 'Database')
  AND deleted_at IS NULL;

UPDATE crf_categories SET sort_order = 1 WHERE name = 'Aplikasi' AND deleted_at IS NULL;
UPDATE crf_categories SET sort_order = 2 WHERE name = 'Infrastruktur' AND deleted_at IS NULL;
UPDATE crf_categories SET sort_order = 3 WHERE name = 'Proses' AND deleted_at IS NULL;
UPDATE crf_categories SET sort_order = 4 WHERE name = 'Security' AND deleted_at IS NULL;
UPDATE crf_categories SET sort_order = 5 WHERE name = 'Lainnya' AND deleted_at IS NULL;
