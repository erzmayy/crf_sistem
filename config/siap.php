<?php
/**
 * Konfigurasi integrasi user SIAP.
 *
 * CRF_USER_SOURCE:
 * - local    : baca user dari tabel users di database CRF (mode demo/lokal)
 * - siap_db  : baca user dari tabel siap.tbl_user (mode integrasi awal)
 *
 * CRF_ROLE_SOURCE:
 * - table    : role dibaca dari crf_user_roles
 * - resolver : role ditentukan dari userid/dept/divisi user SIAP
 */
const CRF_USER_SOURCE = 'siap_db';
const CRF_ROLE_SOURCE = 'resolver';

const SIAP_DATABASE = 'siap';
const SIAP_USER_TABLE = 'tbl_user';

const CRF_ADMIN_USERIDS = ['administrator', 'CRFDEMO'];
const CRF_OTOMASI_USERIDS = ['N75392', 'N76559'];
const CRF_KADEP_OPERASIONAL_USERIDS = ['3736'];
const CRF_CMO_DEPTS = ['CMO'];
