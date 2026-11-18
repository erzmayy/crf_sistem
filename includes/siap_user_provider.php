<?php

require_once __DIR__ . '/../config/siap.php';

function quoteMysqlIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Identifier database tidak valid.');
    }

    return '`' . $identifier . '`';
}

function crfUserTable(): string
{
    if (CRF_USER_SOURCE === 'siap_db') {
        return quoteMysqlIdentifier(SIAP_DATABASE) . '.' . quoteMysqlIdentifier(SIAP_USER_TABLE);
    }

    return 'users';
}

function findCrfUserById(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $table = crfUserTable();
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function findCrfUserByUserid(PDO $pdo, string $userid): ?array
{
    $userid = trim($userid);

    if ($userid === '') {
        return null;
    }

    $table = crfUserTable();
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE userid = :userid LIMIT 1");
    $stmt->execute(['userid' => $userid]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function verifyCrfUserPassword(string $plain, array $user): bool
{
    $passwordNew = (string) ($user['password_new'] ?? '');
    $password = (string) ($user['password'] ?? '');

    foreach ([$passwordNew, $password] as $hash) {
        if ($hash !== '' && preg_match('/^\$2[ayb]\$/', $hash) && password_verify($plain, $hash)) {
            return true;
        }
    }

    if ($password !== '' && hash_equals($password, md5($plain))) {
        return true;
    }

    if ($passwordNew !== '' && hash_equals($passwordNew, md5($plain))) {
        return true;
    }

    return verifyCrfDevLoginPassword($plain, $user);
}

/**
 * Password demo khusus lokal (config/dev_login.php, di-gitignore).
 * Tidak mengubah password di siap.tbl_user dan hanya berlaku untuk
 * request dari komputer lokal.
 */
function verifyCrfDevLoginPassword(string $plain, array $user): bool
{
    $path = __DIR__ . '/../config/dev_login.php';
    if (!is_file($path)) {
        return false;
    }

    $config = require $path;
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if (
        !is_array($config)
        || empty($config['enabled'])
        || !in_array($remoteAddr, ['127.0.0.1', '::1'], true)
        || (string) ($config['password'] ?? '') === ''
        || !hash_equals((string) $config['password'], $plain)
    ) {
        return false;
    }

    return crfUserIdIn($user, (array) ($config['userids'] ?? []))
        || crfUserDeptIn($user, (array) ($config['depts'] ?? []));
}

function normalizeCrfUserValue($value): string
{
    return mb_strtolower(trim((string) $value));
}

function crfUserIdIn(array $user, array $userids): bool
{
    $userid = normalizeCrfUserValue($user['userid'] ?? '');
    $allowed = array_map('normalizeCrfUserValue', $userids);

    return $userid !== '' && in_array($userid, $allowed, true);
}

function crfUserDeptIn(array $user, array $depts): bool
{
    $dept = normalizeCrfUserValue($user['dept'] ?? '');
    $allowed = array_map('normalizeCrfUserValue', $depts);

    return $dept !== '' && in_array($dept, $allowed, true);
}

/* =====================================================================
 * Peran dari hak menu SIAP (Hak Akses User)
 * ---------------------------------------------------------------------
 * Bila CRF_ROLE_FROM_MENU aktif, peran CRF mengikuti hak menu yang diatur
 * di SIAP (tabel tbl_user_menu, hak_akses '0' = muncul = punya hak):
 *   Tindak Lanjut Otomasi -> otomasi, Persetujuan Kadep -> kadep_operasional,
 *   Verifikasi CMO (atau departemen CMO) -> cmo, Dashboard CRF -> hak Admin.
 * Pemohon tetap bawaan semua user. Bila sakelar mati atau tabel menu tidak
 * bisa dibaca, dipakai daftar di config/siap.php seperti sebelumnya.
 * ===================================================================== */

/** Sakelar peralihan. Bawaan mati; nyalakan lewat konstanta di config/siap.php atau variabel lingkungan. */
function crfRoleFromMenuEnabled(): bool
{
    if (defined('CRF_ROLE_FROM_MENU')) {
        return (bool) CRF_ROLE_FROM_MENU;
    }

    $env = getenv('CRF_ROLE_FROM_MENU');

    return $env !== false && in_array(strtolower(trim((string) $env)), ['1', 'true', 'on', 'ya'], true);
}

/** @return array{cmo:int,otomasi:int,kadep_operasional:int,admin:int} id submenu SIAP yang menentukan peran */
function crfRoleMenuIds(): array
{
    $ids = ['cmo' => 338, 'otomasi' => 339, 'kadep_operasional' => 341, 'admin' => 344];

    if (defined('CRF_ROLE_MENU_IDS') && is_array(CRF_ROLE_MENU_IDS)) {
        foreach ($ids as $role => $default) {
            if (isset(CRF_ROLE_MENU_IDS[$role])) {
                $ids[$role] = (int) CRF_ROLE_MENU_IDS[$role];
            }
        }
    }

    return $ids;
}

function crfUserMenuTable(): ?string
{
    if (CRF_USER_SOURCE !== 'siap_db') {
        return null;
    }

    $name = defined('SIAP_USER_MENU_TABLE') ? SIAP_USER_MENU_TABLE : 'tbl_user_menu';

    return quoteMysqlIdentifier(SIAP_DATABASE) . '.' . quoteMysqlIdentifier($name);
}

/**
 * Hak menu peran untuk beberapa userid sekaligus.
 *
 * @param string[] $userids
 * @return array<string,array<int,bool>>|null  [userid(huruf kecil) => [menu_id => true]]; null bila tabel menu tidak bisa dibaca
 */
function crfMenuRightsByUserids(PDO $pdo, array $userids): ?array
{
    static $unavailable = false;

    $userids = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $userids)), 'strlen')));
    if (!$userids) {
        return [];
    }

    $table = crfUserMenuTable();
    if ($table === null || $unavailable) {
        return null;
    }

    $menuIds = array_values(array_unique(array_map('intval', array_values(crfRoleMenuIds()))));

    try {
        $in = implode(',', array_fill(0, count($userids), '?'));
        $inMenu = implode(',', array_fill(0, count($menuIds), '?'));
        $stmt = $pdo->prepare("SELECT userid, menu_id FROM {$table} WHERE userid IN ({$in}) AND menu_id IN ({$inMenu}) AND hak_akses = '0'");
        $stmt->execute(array_merge($userids, $menuIds));
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $unavailable = true;
        error_log('crfMenuRightsByUserids: ' . $e->getMessage());

        return null;
    }

    $map = [];
    foreach ($rows as $row) {
        $map[normalizeCrfUserValue($row['userid'])][(int) $row['menu_id']] = true;
    }

    return $map;
}

/** Hak menu peran untuk satu user (disimpan per request). Null = tabel menu tidak bisa dipakai. */
function crfMenuRightsForUser(array $user): ?array
{
    static $cache = [];

    $userid = trim((string) ($user['userid'] ?? ''));
    if ($userid === '') {
        return [];
    }

    $key = normalizeCrfUserValue($userid);
    if (!array_key_exists($key, $cache)) {
        $map = crfMenuRightsByUserids(getConnection(), [$userid]);
        $cache[$key] = $map === null ? null : ($map[$key] ?? []);
    }

    return $cache[$key];
}

/**
 * Peran dari hak menu. Satu menu kerja = peran itu. Lebih dari satu menu kerja
 * (mis. akun admin SIAP yang memegang semua menu) = 'admin' bila punya hak Admin,
 * selain itu urutan Otomasi, Kadep, CMO. Tanpa menu kerja: admin atau pemohon.
 *
 * @param array<int,bool> $rights [menu_id => true]
 */
function crfRoleFromMenuRights(array $user, array $rights): string
{
    $menu = crfRoleMenuIds();
    $work = [];

    if (!empty($rights[$menu['otomasi']])) {
        $work[] = 'otomasi';
    }
    if (!empty($rights[$menu['kadep_operasional']])) {
        $work[] = 'kadep_operasional';
    }
    if (!empty($rights[$menu['cmo']]) || crfUserDeptIn($user, CRF_CMO_DEPTS)) {
        $work[] = 'cmo';
    }

    // Daftar CRF_ADMIN_USERIDS di config tetap berlaku sebagai cadangan darurat.
    $admin = !empty($rights[$menu['admin']]) || crfUserIdIn($user, CRF_ADMIN_USERIDS);

    if (count($work) === 1) {
        return $work[0];
    }

    if (count($work) > 1) {
        return $admin ? 'admin' : $work[0];
    }

    return $admin ? 'admin' : 'pemohon';
}

/** Punya hak Admin (pengelola dan pemantau)? Hak tambahan di atas peran kerja. */
function crfUserHasAdminRight(array $user): bool
{
    if (crfRoleFromMenuEnabled()) {
        $rights = crfMenuRightsForUser($user);
        if ($rights !== null) {
            return !empty($rights[crfRoleMenuIds()['admin']]) || crfUserIdIn($user, CRF_ADMIN_USERIDS);
        }
    }

    return crfUserIdIn($user, CRF_ADMIN_USERIDS);
}

/**
 * ID user (crf pengguna_id = tbl_user.id) untuk satu peran, dari hak menu. Dipakai penerima notifikasi.
 * 'admin' = semua pemegang hak Admin; peran lain = user yang peran akhirnya persis peran itu.
 *
 * @return int[]|null  null bila tabel menu tidak bisa dipakai (pemanggil jatuh ke config)
 */
function crfUserIdsForRoleFromMenu(PDO $pdo, string $role): ?array
{
    $menu = crfRoleMenuIds();
    $table = crfUserMenuTable();
    if ($table === null || !isset($menu[$role])) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT DISTINCT userid FROM {$table} WHERE menu_id = ? AND hak_akses = '0'");
        $stmt->execute([$menu[$role]]);
        $userids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($role === 'admin') {
            $userids = array_merge($userids, array_map('strval', CRF_ADMIN_USERIDS));
        }

        $params = array_values(array_unique($userids));
        $where = [];
        if ($params) {
            $where[] = 'userid IN (' . implode(',', array_fill(0, count($params), '?')) . ')';
        }
        if ($role === 'cmo' && CRF_CMO_DEPTS) {
            $where[] = 'dept IN (' . implode(',', array_fill(0, count(CRF_CMO_DEPTS), '?')) . ')';
            $params = array_merge($params, array_values(CRF_CMO_DEPTS));
        }
        if (!$where) {
            return [];
        }

        $userStmt = $pdo->prepare('SELECT id, userid, dept FROM ' . crfUserTable() . ' WHERE ' . implode(' OR ', $where));
        $userStmt->execute($params);
        $users = $userStmt->fetchAll();
    } catch (Throwable $e) {
        error_log('crfUserIdsForRoleFromMenu: ' . $e->getMessage());

        return null;
    }

    $rights = crfMenuRightsByUserids($pdo, array_column($users, 'userid'));
    if ($rights === null) {
        return null;
    }

    $ids = [];
    foreach ($users as $user) {
        $userRights = $rights[normalizeCrfUserValue($user['userid'])] ?? [];

        if ($role === 'admin') {
            $match = !empty($userRights[$menu['admin']]) || crfUserIdIn($user, CRF_ADMIN_USERIDS);
        } else {
            $match = crfRoleFromMenuRights($user, $userRights) === $role;
        }

        if ($match) {
            $ids[] = (int) $user['id'];
        }
    }

    return $ids;
}

function resolveCrfRoleFromUser(array $user): string
{
    if (CRF_DEMO_MODE && crfUserIdIn($user, CRF_DEMO_USERIDS)) {
        return 'demo';
    }

    if (crfRoleFromMenuEnabled()) {
        $rights = crfMenuRightsForUser($user);
        if ($rights !== null) {
            return crfRoleFromMenuRights($user, $rights);
        }
    }

    return crfResolveRoleFromConfig($user);
}

/** Cara lama: peran dari daftar userid dan departemen di config/siap.php. */
function crfResolveRoleFromConfig(array $user): string
{
    if (CRF_DEMO_MODE && crfUserIdIn($user, CRF_DEMO_USERIDS)) {
        return 'demo';
    }

    // Admin adalah hak akses tambahan (lihat isAdmin()); peran kerja
    // didahulukan. Hanya user tanpa peran kerja yang berperan 'admin'.
    if (crfUserIdIn($user, CRF_OTOMASI_USERIDS)) {
        return 'otomasi';
    }

    if (crfUserIdIn($user, CRF_KADEP_OPERASIONAL_USERIDS)) {
        return 'kadep_operasional';
    }

    if (crfUserDeptIn($user, CRF_CMO_DEPTS)) {
        return 'cmo';
    }

    if (crfUserIdIn($user, CRF_ADMIN_USERIDS)) {
        return 'admin';
    }

    return 'pemohon';
}
