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

    return false;
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

function resolveCrfRoleFromUser(array $user): string
{
    if (crfUserIdIn($user, CRF_ADMIN_USERIDS)) {
        return 'admin';
    }

    if (crfUserIdIn($user, CRF_OTOMASI_USERIDS)) {
        return 'otomasi';
    }

    if (crfUserIdIn($user, CRF_KADEP_OPERASIONAL_USERIDS)) {
        return 'kadep_operasional';
    }

    if (crfUserDeptIn($user, CRF_CMO_DEPTS)) {
        return 'cmo';
    }

    return 'pemohon';
}
