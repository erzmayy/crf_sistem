USE siap;

INSERT INTO tbl_user (
    userid,
    password,
    password_new,
    nama,
    dept,
    tgl_insert,
    lastlogin,
    divisi,
    no_wa,
    ganti_password,
    email,
    gender,
    atasan_id,
    atasan_nama,
    atasan_telp,
    kpu_kode,
    kpu_nama,
    npp,
    status_wa,
    pusat
)
SELECT
    'CRFDEMO',
    '5f4dcc3b5aa765d61d8327deb882cf99',
    NULL,
    'Demo CRF All Role',
    'Departemen Operasional',
    CURRENT_TIMESTAMP,
    NULL,
    'Pemimpin Departemen',
    NULL,
    '1',
    'crfdemo@ptppu.test',
    'L',
    NULL,
    NULL,
    NULL,
    '',
    '',
    'CRFDEMO',
    'BLM',
    'YES'
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_user WHERE userid = 'CRFDEMO'
);

UPDATE tbl_user
SET
    password = '5f4dcc3b5aa765d61d8327deb882cf99',
    password_new = NULL,
    nama = 'Demo CRF All Role',
    dept = 'Departemen Operasional',
    divisi = 'Pemimpin Departemen',
    email = 'crfdemo@ptppu.test',
    npp = 'CRFDEMO',
    pusat = 'YES'
WHERE userid = 'CRFDEMO';
