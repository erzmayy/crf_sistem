<?php
/**
 * tools/bandingkan_peran.php
 * ---------------------------------------------------------------
 * Laporan BACA-SAJA: membandingkan peran CRF dari daftar di config/siap.php
 * (cara lama) dengan peran dari hak menu SIAP (tbl_user_menu, cara baru).
 * Jalankan SEBELUM menyalakan CRF_ROLE_FROM_MENU, lalu tinjau baris yang BERUBAH.
 *
 *   php tools/bandingkan_peran.php            tampilkan semua user yang terkait
 *   php tools/bandingkan_peran.php --berubah  hanya yang perannya atau hak Admin-nya berubah
 *
 * Hanya bisa dijalankan dari terminal (bukan lewat browser). Tidak mengubah data apa pun.
 * ---------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

$hanyaBerubah = in_array('--berubah', $argv, true);
$pdo = getConnection();

if (CRF_USER_SOURCE !== 'siap_db') {
    fwrite(STDERR, "CRF_USER_SOURCE bukan 'siap_db': hak menu SIAP tidak dipakai.\n");
    exit(1);
}

// 1. Kumpulkan user terkait: pemegang menu peran, daftar di config, dan departemen CMO.
$menu = crfRoleMenuIds();
$userids = [];
$table = crfUserMenuTable();
try {
    $in = implode(',', array_fill(0, count($menu), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT userid FROM {$table} WHERE menu_id IN ({$in}) AND hak_akses = '0'");
    $stmt->execute(array_values($menu));
    $userids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    fwrite(STDERR, 'Tabel menu SIAP tidak bisa dibaca: ' . $e->getMessage() . "\n");
    exit(1);
}
$userids = array_merge($userids, array_map('strval', array_merge(CRF_ADMIN_USERIDS, CRF_OTOMASI_USERIDS, CRF_KADEP_OPERASIONAL_USERIDS)));
$userids = array_values(array_unique($userids));

$params = $userids;
$where = [];
if ($userids) {
    $where[] = 'userid IN (' . implode(',', array_fill(0, count($userids), '?')) . ')';
}
if (CRF_CMO_DEPTS) {
    $where[] = 'dept IN (' . implode(',', array_fill(0, count(CRF_CMO_DEPTS), '?')) . ')';
    $params = array_merge($params, array_values(CRF_CMO_DEPTS));
}
$stmt = $pdo->prepare('SELECT id, userid, nama, dept FROM ' . crfUserTable() . ' WHERE ' . implode(' OR ', $where) . ' ORDER BY userid');
$stmt->execute($params);
$users = $stmt->fetchAll();

$rights = crfMenuRightsByUserids($pdo, array_column($users, 'userid'));
if ($rights === null) {
    fwrite(STDERR, "Hak menu tidak bisa dibaca; tidak ada yang dibandingkan.\n");
    exit(1);
}

// 2. Bandingkan.
$rows = [];
$jumlahPeran = 0;
$jumlahAdmin = 0;
foreach ($users as $user) {
    $r = $rights[normalizeCrfUserValue($user['userid'])] ?? [];
    $peranLama = crfResolveRoleFromConfig($user);
    $adminLama = crfUserIdIn($user, CRF_ADMIN_USERIDS);
    $peranBaru = crfRoleFromMenuRights($user, $r);
    $adminBaru = !empty($r[$menu['admin']]) || crfUserIdIn($user, CRF_ADMIN_USERIDS);

    $catatan = [];
    if ($peranLama !== $peranBaru) {
        $catatan[] = "peran {$peranLama} -> {$peranBaru}";
        $jumlahPeran++;
    }
    if ($adminLama !== $adminBaru) {
        $catatan[] = $adminBaru ? 'hak Admin DITAMBAH' : 'hak Admin DICABUT';
        $jumlahAdmin++;
    }
    if ($hanyaBerubah && !$catatan) {
        continue;
    }

    $menuKerja = [];
    foreach (['otomasi' => 'Otomasi', 'kadep_operasional' => 'Kadep', 'cmo' => 'CMO', 'admin' => 'Admin'] as $key => $label) {
        if (!empty($r[$menu[$key]])) {
            $menuKerja[] = $label;
        }
    }

    $rows[] = [
        $user['userid'],
        mb_substr((string) $user['nama'], 0, 24),
        (string) $user['dept'],
        $peranLama . ($adminLama && $peranLama !== 'admin' ? '+admin' : ''),
        $peranBaru . ($adminBaru && $peranBaru !== 'admin' ? '+admin' : ''),
        $menuKerja ? implode(',', $menuKerja) : '-',
        $catatan ? implode('; ', $catatan) : 'sama',
    ];
}

// 3. Cetak.
$head = ['USERID', 'NAMA', 'DEPT', 'PERAN LAMA', 'PERAN BARU', 'MENU DIPEGANG', 'STATUS'];
$width = array_map('mb_strlen', $head);
foreach ($rows as $row) {
    foreach ($row as $i => $cell) {
        $width[$i] = max($width[$i], mb_strlen((string) $cell));
    }
}
$line = static function (array $cells) use ($width): string {
    $out = [];
    foreach ($cells as $i => $cell) {
        $out[] = $cell . str_repeat(' ', max(0, $width[$i] - mb_strlen((string) $cell)));
    }

    return implode('  ', $out);
};

echo 'Basis data: ' . SIAP_DATABASE . ' | menu: Otomasi=' . $menu['otomasi'] . ' Kadep=' . $menu['kadep_operasional']
    . ' CMO=' . $menu['cmo'] . ' Admin=' . $menu['admin'] . "\n\n";
echo $line($head) . "\n" . str_repeat('-', array_sum($width) + 2 * (count($width) - 1)) . "\n";
foreach ($rows as $row) {
    echo $line($row) . "\n";
}
echo "\nUser ditampilkan   : " . count($rows) . ($hanyaBerubah ? ' (hanya yang berubah)' : '') . ' dari ' . count($users) . " user terkait\n";
echo "Peran berubah      : {$jumlahPeran}\n";
echo "Hak Admin berubah  : {$jumlahAdmin}\n";
echo ($jumlahPeran + $jumlahAdmin) === 0
    ? "Tidak ada perbedaan: aman untuk menyalakan CRF_ROLE_FROM_MENU.\n"
    : "Ada perbedaan: tinjau baris berstatus selain 'sama' sebelum menyalakan CRF_ROLE_FROM_MENU.\n";
