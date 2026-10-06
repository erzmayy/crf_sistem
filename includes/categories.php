<?php
/**
 * includes/categories.php
 * ---------------------------------------------------------------
 * Master Kategori CRF (+ Handling Kategori) dan Kategori Helpdesk
 * (+ PIC). Kategori bersifat dinamis (dikelola Admin), tidak hardcode.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/session.php';

/**
 * Kategori CRF yang belum dihapus. $onlyActive = true untuk dropdown form.
 */
function crfCategories(PDO $pdo, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM crf_categories WHERE deleted_at IS NULL';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    return $pdo->query($sql)->fetchAll();
}

function findCrfCategory(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM crf_categories WHERE id = :id AND deleted_at IS NULL LIMIT 1');
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

    return $category && (int) $category['is_active'] === 1 ? $category : null;
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

    if (!empty($crf['crf_category_id'])) {
        if ($names === null) {
            try {
                $names = getConnection()->query('SELECT id, name FROM crf_categories')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Throwable $e) {
                $names = [];
            }
        }
        if (isset($names[(int) $crf['crf_category_id']])) {
            return (string) $names[(int) $crf['crf_category_id']];
        }
    }

    return !empty($crf['change_category']) ? (string) $crf['change_category'] : $fallback;
}

function helpdeskCategories(PDO $pdo, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM helpdesk_categories WHERE deleted_at IS NULL';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    return $pdo->query($sql)->fetchAll();
}

function findHelpdeskCategory(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM helpdesk_categories WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * PIC CRF diturunkan dari PIC Kategori Helpdesk (migrasi 017):
 *   crf_category_pic_sources     : Kategori CRF <- Kategori Helpdesk. Diisi otomatis dari
 *                                  pengaturan "Butuh CRF + Kategori CRF default" kategori Helpdesk.
 *   crf_category_handlers_manual : PIC CRF lama yang diisi manual (masa transisi).
 *   crf_category_handlers (VIEW) : gabungan keduanya — dipakai semua pembacaan PIC CRF.
 */

/**
 * Samakan pemetaan PIC CRF sebuah kategori Helpdesk dengan pengaturannya:
 * "Butuh CRF" + Kategori CRF default -> semua PIC-nya menjadi PIC CRF kategori itu.
 * $crfCategoryId null = kategori Helpdesk ini tidak memberi PIC CRF.
 */
function syncCrfPicSource(PDO $pdo, int $helpdeskCategoryId, ?int $crfCategoryId, string $actorName): void
{
    $pdo->prepare('DELETE FROM crf_category_pic_sources WHERE helpdesk_category_id = :helpdesk_id')
        ->execute(['helpdesk_id' => $helpdeskCategoryId]);

    if ($crfCategoryId) {
        $pdo->prepare('
            INSERT INTO crf_category_pic_sources (crf_category_id, helpdesk_category_id, created_by_name)
            VALUES (:crf_id, :helpdesk_id, :actor)
        ')->execute(['crf_id' => $crfCategoryId, 'helpdesk_id' => $helpdeskCategoryId, 'actor' => $actorName]);
    }
}

/**
 * ID user PIC CRF untuk satu kategori CRF.
 *
 * @return int[]
 */
function crfCategoryHandlerIds(PDO $pdo, int $categoryId): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM crf_category_handlers WHERE crf_category_id = :id');
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
        $stmt = $pdo->prepare('SELECT crf_category_id FROM crf_category_handlers WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
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
        FROM crf_categories c
        WHERE NOT EXISTS (
            SELECT 1 FROM crf_category_handlers h WHERE h.crf_category_id = c.id
        )
    ')->fetchAll(PDO::FETCH_COLUMN));
}

function isCrfCategoryHandler(PDO $pdo, int $userId): bool
{
    return handledCrfCategoryIds($pdo, $userId) !== [];
}

/**
 * @return int[]
 */
function helpdeskCategoryPicIds(PDO $pdo, int $categoryId): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM helpdesk_category_pics WHERE helpdesk_category_id = :id');
    $stmt->execute(['id' => $categoryId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return int[]
 */
function picHelpdeskCategoryIds(PDO $pdo, int $userId): array
{
    static $cache = [];

    if (!isset($cache[$userId])) {
        $stmt = $pdo->prepare('SELECT helpdesk_category_id FROM helpdesk_category_pics WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $cache[$userId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    return $cache[$userId];
}

function isHelpdeskPic(PDO $pdo, int $userId): bool
{
    return picHelpdeskCategoryIds($pdo, $userId) !== [];
}

/**
 * Daftar handler/PIC per kategori: [category_id => [ [user_id, user_name, email, no_wa], ... ]].
 */
function categoryMembers(PDO $pdo, string $pivotTable, string $categoryColumn): array
{
    if (!in_array($pivotTable, ['crf_category_handlers', 'helpdesk_category_pics'], true)) {
        throw new InvalidArgumentException('Tabel pivot tidak dikenal.');
    }

    $userTable = crfUserTable();
    // PIC CRF (view) membawa asal PIC: isian manual dan/atau kategori Helpdesk sumber.
    $extraColumns = $pivotTable === 'crf_category_handlers'
        ? 'p.is_manual, p.sources'
        : '0 AS is_manual, NULL AS sources';
    $rows = $pdo->query("
        SELECT p.id AS pivot_id, p.{$categoryColumn} AS category_id, p.user_id,
               COALESCE(u.nama, p.user_name) AS user_name, u.userid, u.email, u.no_wa,
               {$extraColumns}
        FROM {$pivotTable} p
        LEFT JOIN {$userTable} u ON u.id = p.user_id
        ORDER BY p.id
    ")->fetchAll();

    $members = [];
    foreach ($rows as $row) {
        $members[(int) $row['category_id']][] = $row;
    }

    return $members;
}

/**
 * Cari user SIAP untuk dipilih sebagai PIC/handler.
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
