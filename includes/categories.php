<?php
/**
 * includes/categories.php
 * ---------------------------------------------------------------
 * Master Kategori CRF (+ PIC CRF). Kategori bersifat dinamis
 * (dikelola Admin), tidak hardcode.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/session.php';

/**
 * Kategori CRF yang belum dihapus. $onlyActive = true untuk dropdown form.
 */
function crfCategories(PDO $pdo, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM crf_kategori WHERE tgl_dihapus IS NULL';
    if ($onlyActive) {
        $sql .= ' AND aktif = 1';
    }
    $sql .= ' ORDER BY urutan, nama';

    return $pdo->query($sql)->fetchAll();
}

function findCrfCategory(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM crf_kategori WHERE id = :id AND tgl_dihapus IS NULL LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Validasi input kategori CRF dari form: harus kategori aktif.
 */
function resolveActiveCrfCategory(PDO $pdo, $rawId): ?array
{
    $id = is_scalar($rawId) ? (int) $rawId : 0;
    if ($id <= 0) {
        return null;
    }

    $category = findCrfCategory($pdo, $id);

    return $category && (int) $category['aktif'] === 1 ? $category : null;
}

/**
 * Nama kategori CRF untuk tampilan (kategori dinamis, fallback ENUM lama).
 */
function crfCategoryName(array $crf, string $fallback = '-'): string
{
    static $names = null;

    if (!empty($crf['crf_category_name'])) {
        return (string) $crf['crf_category_name'];
    }

    if (!empty($crf['kategori_id'])) {
        if ($names === null) {
            try {
                $names = getConnection()->query('SELECT id, nama FROM crf_kategori')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Throwable $e) {
                $names = [];
            }
        }
        if (isset($names[(int) $crf['kategori_id']])) {
            return (string) $names[(int) $crf['kategori_id']];
        }
    }

    return !empty($crf['kategori_perubahan']) ? (string) $crf['kategori_perubahan'] : $fallback;
}

/**
 * ID user PIC CRF untuk satu kategori CRF.
 *
 * @return int[]
 */
function crfCategoryHandlerIds(PDO $pdo, int $categoryId): array
{
    $stmt = $pdo->prepare('SELECT pengguna_id FROM crf_kategori_pic WHERE kategori_id = :id');
    $stmt->execute(['id' => $categoryId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Kategori CRF yang ditangani user.
 *
 * @return int[]
 */
function handledCrfCategoryIds(PDO $pdo, int $userId): array
{
    static $cache = [];

    if (!isset($cache[$userId])) {
        $stmt = $pdo->prepare('SELECT kategori_id FROM crf_kategori_pic WHERE pengguna_id = :pengguna_id');
        $stmt->execute(['pengguna_id' => $userId]);
        $cache[$userId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    return $cache[$userId];
}

/**
 * Kategori CRF yang belum punya handler sama sekali. CRF pada kategori
 * ini ditangani role Otomasi lama (fallback agar tidak ada CRF yatim).
 *
 * @return int[]
 */
function unhandledCrfCategoryIds(PDO $pdo): array
{
    return array_map('intval', $pdo->query('
        SELECT c.id
        FROM crf_kategori c
        WHERE NOT EXISTS (
            SELECT 1 FROM crf_kategori_pic h WHERE h.kategori_id = c.id
        )
    ')->fetchAll(PDO::FETCH_COLUMN));
}

function isCrfCategoryHandler(PDO $pdo, int $userId): bool
{
    return handledCrfCategoryIds($pdo, $userId) !== [];
}

/**
 * Daftar PIC CRF per kategori: [category_id => [ [pengguna_id, pengguna_nama, email, no_wa], ... ]].
 */
function categoryMembers(PDO $pdo): array
{
    $userTable = crfUserTable();
    $rows = $pdo->query("
        SELECT p.id AS pivot_id, p.kategori_id AS category_id, p.pengguna_id,
               COALESCE(u.nama, p.pengguna_nama) AS pengguna_nama, u.userid, u.email, u.no_wa
        FROM crf_kategori_pic p
        LEFT JOIN {$userTable} u ON u.id = p.pengguna_id
        ORDER BY p.id
    ")->fetchAll();

    $members = [];
    foreach ($rows as $row) {
        $members[(int) $row['category_id']][] = $row;
    }

    return $members;
}

/**
 * Cari user SIAP untuk dipilih sebagai PIC CRF.
 */
function searchCrfUsers(PDO $pdo, string $term, int $limit = 15): array
{
    $term = trim($term);
    if (mb_strlen($term) < 2) {
        return [];
    }

    $userTable = crfUserTable();
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare("
        SELECT id, userid, nama, dept, divisi, email
        FROM {$userTable}
        WHERE nama LIKE :term_name OR userid LIKE :term_userid OR email LIKE :term_email
        ORDER BY nama
        LIMIT {$limit}
    ");
    $like = '%' . $term . '%';
    $stmt->execute(['term_name' => $like, 'term_userid' => $like, 'term_email' => $like]);

    return $stmt->fetchAll();
}

function slaLabel($value, ?string $unit): string
{
    if ($value === null || $value === '' || empty($unit)) {
        return '-';
    }

    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' ' . $unit;
}
