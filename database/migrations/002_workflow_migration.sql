-- =====================================================================
-- CRF PROTOTYPE - WORKFLOW MIGRATION
-- Jalankan sekali pada database crf_sistem.
-- =====================================================================

USE crf_sistem;

ALTER TABLE change_requests
    ADD COLUMN workflow_stage ENUM(
        'PEMOHON',
        'CMO_FILTER',
        'OTOMASI',
        'PEMOHON_PIR',
        'kadep_operasional',
        'CMO_FINAL',
        'SELESAI'
    ) NOT NULL DEFAULT 'PEMOHON' AFTER status,
    ADD COLUMN sla_value DECIMAL(10,2) NULL AFTER level,
    ADD COLUMN sla_unit ENUM('Menit','Jam','Hari') NULL AFTER sla_value,
    ADD COLUMN sla_started_at DATETIME NULL AFTER sla_unit,
    ADD COLUMN sla_due_at DATETIME NULL AFTER sla_started_at,
    ADD COLUMN automation_started_at DATETIME NULL AFTER sla_due_at,
    ADD COLUMN automation_completed_at DATETIME NULL AFTER automation_started_at,
    ADD COLUMN kadep_operasional_approved_by INT UNSIGNED NULL AFTER approval_at,
    ADD COLUMN kadep_operasional_approved_at DATETIME NULL AFTER kadep_operasional_approved_by,
    ADD COLUMN kadep_operasional_approval_note TEXT NULL AFTER kadep_operasional_approved_at;

ALTER TABLE change_requests
    ADD CONSTRAINT fk_crf_kadep_operasional_approved_by
        FOREIGN KEY (kadep_operasional_approved_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS crf_user_roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('pemohon','cmo','otomasi','kadep_operasional','admin') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crf_user_role_user (user_id),
    CONSTRAINT fk_crf_user_roles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesuaikan tahap data lama agar antreannya masuk ke alur baru.
UPDATE change_requests
SET workflow_stage = CASE
    WHEN status = 'Draft' THEN 'PEMOHON'
    WHEN status = 'Perlu Revisi' THEN 'PEMOHON'
    WHEN status = 'Belum Ditindak Lanjuti' THEN 'CMO_FILTER'
    WHEN status = 'Dalam Proses' THEN 'OTOMASI'
    WHEN status IN ('Solve','Cancel') THEN 'SELESAI'
    ELSE 'PEMOHON'
END;

-- Akun demo workflow (hanya dibuat jika belum ada).
INSERT INTO users (userid, password, nama, dept, divisi, email, no_wa)
SELECT 'CMO001', '$2y$10$92IXUNpkj0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i', 'CMO Demo', 'CMO', 'Pemimpin Divisi', 'cmo@ppu.test', '081234567893'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE userid = 'CMO001');

INSERT INTO users (userid, password, nama, dept, divisi, email, no_wa)
SELECT 'OTOMASI001', '$2y$10$92IXUNpkj0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i', 'Otomasi Demo', 'Departemen Operasional', 'Divisi Otomasi', 'otomasi@ppu.test', '081234567894'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE userid = 'OTOMASI001');

INSERT INTO users (userid, password, nama, dept, divisi, email, no_wa)
SELECT 'JOKO001', '$2y$10$92IXUNpkj0rOQ5byMi.Ye4oKoEa3Ro9llC8T9hS2YqJYQ1Q8q7i', 'Pak Joko Demo', 'Departemen Operasional', 'Pemimpin Departemen', 'joko@ppu.test', '081234567895'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE userid = 'JOKO001');

-- Mapping role untuk data yang sudah ada.
INSERT INTO crf_user_roles (user_id, role)
SELECT u.id, 'pemohon'
FROM users u
WHERE u.userid = 'USER001'
  AND NOT EXISTS (SELECT 1 FROM crf_user_roles r WHERE r.user_id = u.id);

INSERT INTO crf_user_roles (user_id, role)
SELECT u.id, 'admin'
FROM users u
WHERE u.userid = 'ADMIN001'
ON DUPLICATE KEY UPDATE role = VALUES(role), is_active = 1;

INSERT INTO crf_user_roles (user_id, role)
SELECT u.id, 'cmo'
FROM users u
WHERE LOWER(TRIM(u.dept)) = 'cmo'
  AND NOT EXISTS (SELECT 1 FROM crf_user_roles r WHERE r.user_id = u.id);

INSERT INTO crf_user_roles (user_id, role)
SELECT u.id, 'kadep_operasional'
FROM users u
WHERE (u.userid = '3736' OR LOWER(TRIM(u.nama)) = 'joko sri purwoko')
  AND NOT EXISTS (SELECT 1 FROM crf_user_roles r WHERE r.user_id = u.id);

INSERT INTO crf_user_roles (user_id, role)
SELECT u.id, 'otomasi'
FROM users u
WHERE LOWER(TRIM(u.divisi)) = 'divisi otomasi'
  AND NOT EXISTS (SELECT 1 FROM crf_user_roles r WHERE r.user_id = u.id);
