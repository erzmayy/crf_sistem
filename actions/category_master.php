<?php
/**
 * actions/category_master.php
 * ---------------------------------------------------------------
 * Kelola master Kategori CRF dan Kategori Helpdesk (Admin):
 *   type = crf | helpdesk
 *   op   = save | toggle | delete | add_member | remove_member
 * Anggota kategori = Handler (CRF) atau PIC (Helpdesk).
 * Hapus kategori = soft delete (deleted_at), data CRF/ticket lama aman.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin/master_data.php');
    exit;
}

verifyCsrf();

$pdo = getConnection();
$type = $_POST['type'] ?? '';
$op = $_POST['op'] ?? '';

$config = [
    'crf' => [
        'table'    => 'crf_categories',
        'pivot'    => 'crf_category_handlers',
        'fk'       => 'crf_category_id',
        'member'   => 'Handler',
        'pages'    => ['master_data.php'],
    ],
    'helpdesk' => [
        'table'    => 'helpdesk_categories',
        'pivot'    => 'helpdesk_category_pics',
        'fk'       => 'helpdesk_category_id',
        'member'   => 'PIC',
        'pages'    => ['master_data.php'],
    ],
];

if (!isset($config[$type])) {
    http_response_code(400);
    exit('Tipe kategori tidak dikenal.');
}

$cfg = $config[$type];
$returnPage = in_array($_POST['return'] ?? '', $cfg['pages'], true) ? $_POST['return'] : $cfg['pages'][0];
$redirect = '../admin/' . $returnPage . '?tab=' . $type;
// Kembali ke kartu kategori yang baru diubah.
if ((int) ($_POST['id'] ?? 0) > 0 && in_array($_POST['op'] ?? '', ['save', 'toggle', 'add_member', 'remove_member'], true)) {
    $redirect .= '#' . $type . '-' . (int) $_POST['id'];
}

function categoryMasterFinish(string $type, string $message, string $redirect): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $redirect);
    exit;
}

$categoryId = (int) ($_POST['id'] ?? 0);
$table = $cfg['table'];

try {
    switch ($op) {
        case 'save':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $slaValueRaw = trim($_POST['sla_value'] ?? '');
            $slaUnit = $_POST['sla_unit'] ?? '';
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            $errors = [];

            if ($name === '' || mb_strlen($name) > 100) {
                $errors[] = 'Nama kategori wajib diisi (maks. 100 karakter).';
            }
            if (mb_strlen($description) > 255) {
                $errors[] = 'Deskripsi maksimal 255 karakter.';
            }

            $slaValue = null;
            if ($slaValueRaw !== '') {
                if (!is_numeric($slaValueRaw) || (float) $slaValueRaw <= 0) {
                    $errors[] = 'Nilai SLA harus angka lebih dari 0.';
                } elseif (!in_array($slaUnit, ['Menit', 'Jam', 'Hari'], true)) {
                    $errors[] = 'Satuan SLA tidak valid.';
                } else {
                    $slaValue = (float) $slaValueRaw;
                }
            }
            if ($slaValue === null) {
                $slaUnit = null;
            }

            $extra = [];
            if ($type === 'crf') {
                $legacy = $_POST['legacy_change_category'] ?? 'Lainnya';
                if (!in_array($legacy, ['Aplikasi', 'Infrastruktur', 'Proses', 'Security', 'Lainnya'], true)) {
                    $errors[] = 'Kelompok kategori laporan tidak valid.';
                }
                $extra['legacy_change_category'] = $legacy;
            } else {
                $icon = trim($_POST['icon'] ?? 'bi-tag');
                $extra['icon'] = preg_match('/^bi-[a-z0-9-]{1,40}$/', $icon) ? $icon : 'bi-tag';
                $extra['requires_crf'] = !empty($_POST['requires_crf']) ? 1 : 0;
                $defaultCrfCategoryId = (int) ($_POST['default_crf_category_id'] ?? 0);
                if ($defaultCrfCategoryId > 0 && !findCrfCategory($pdo, $defaultCrfCategoryId)) {
                    $errors[] = 'Kategori CRF default tidak ditemukan.';
                }
                $extra['default_crf_category_id'] = $defaultCrfCategoryId > 0 ? $defaultCrfCategoryId : null;
            }

            // Nama unik terhadap kategori lain yang masih ada.
            $dupStmt = $pdo->prepare("SELECT id, deleted_at FROM {$table} WHERE name = :name AND id <> :id LIMIT 1");
            $dupStmt->execute(['name' => $name, 'id' => $categoryId]);
            $duplicate = $dupStmt->fetch();

            if ($duplicate && $duplicate['deleted_at'] === null) {
                $errors[] = 'Nama kategori sudah digunakan.';
            }

            if ($errors) {
                categoryMasterFinish('danger', implode(' ', $errors), $redirect);
            }

            // Nama milik kategori yang sudah dihapus: pulihkan kategori lama itu.
            if ($duplicate && $categoryId === 0) {
                $categoryId = (int) $duplicate['id'];
            }

            $fields = array_merge([
                'name'        => $name,
                'description' => $description !== '' ? $description : null,
                'sla_value'   => $slaValue,
                'sla_unit'    => $slaUnit,
                'sort_order'  => $sortOrder,
                'is_active'   => $isActive,
            ], $extra);

            if ($categoryId > 0) {
                $sets = implode(', ', array_map(static fn($col) => "{$col} = :{$col}", array_keys($fields)));
                $stmt = $pdo->prepare("UPDATE {$table} SET {$sets}, deleted_at = NULL WHERE id = :id");
                $stmt->execute($fields + ['id' => $categoryId]);
                $message = 'Kategori "' . $name . '" berhasil diperbarui.';
            } else {
                $cols = implode(', ', array_keys($fields));
                $vals = implode(', ', array_map(static fn($col) => ':' . $col, array_keys($fields)));
                $pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$vals})")->execute($fields);
                $message = 'Kategori "' . $name . '" berhasil ditambahkan.';
            }

            categoryMasterFinish('success', $message, $redirect);
            break;

        case 'toggle':
            $stmt = $pdo->prepare("UPDATE {$table} SET is_active = 1 - is_active WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute(['id' => $categoryId]);
            categoryMasterFinish(
                $stmt->rowCount() ? 'success' : 'danger',
                $stmt->rowCount() ? 'Status kategori berhasil diubah.' : 'Kategori tidak ditemukan.',
                $redirect
            );
            break;

        case 'delete':
            // Soft delete: data CRF / ticket yang memakai kategori ini tetap utuh.
            $stmt = $pdo->prepare("UPDATE {$table} SET is_active = 0, deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute(['id' => $categoryId]);
            categoryMasterFinish(
                $stmt->rowCount() ? 'success' : 'danger',
                $stmt->rowCount() ? 'Kategori dihapus (data yang sudah memakai kategori ini tetap tersimpan).' : 'Kategori tidak ditemukan.',
                $redirect
            );
            break;

        case 'add_member':
        case 'remove_member':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $category = $type === 'crf' ? findCrfCategory($pdo, $categoryId) : findHelpdeskCategory($pdo, $categoryId);
            $memberUser = findCrfUserById($pdo, $userId);

            if (!$category || !$memberUser) {
                categoryMasterFinish('danger', 'Kategori atau user tidak ditemukan.', $redirect);
            }

            $pivot = $cfg['pivot'];
            $fk = $cfg['fk'];
            $memberName = crfActorName($memberUser);

            if ($op === 'add_member') {
                $pdo->prepare("
                    INSERT INTO {$pivot} ({$fk}, user_id, user_name)
                    VALUES (:category_id, :user_id, :user_name)
                    ON DUPLICATE KEY UPDATE user_name = VALUES(user_name)
                ")->execute(['category_id' => $categoryId, 'user_id' => $userId, 'user_name' => $memberName]);
                $message = $memberName . ' ditambahkan sebagai ' . $cfg['member'] . ' kategori ' . $category['name'] . '.';
            } else {
                $pdo->prepare("DELETE FROM {$pivot} WHERE {$fk} = :category_id AND user_id = :user_id")
                    ->execute(['category_id' => $categoryId, 'user_id' => $userId]);
                $message = $memberName . ' dihapus dari ' . $cfg['member'] . ' kategori ' . $category['name'] . '.';
            }

            categoryMasterFinish('success', $message, $redirect);
            break;

        default:
            categoryMasterFinish('danger', 'Aksi tidak dikenal.', $redirect);
    }
} catch (Throwable $e) {
    error_log('category_master error: ' . $e->getMessage());
    categoryMasterFinish('danger', 'Terjadi kesalahan saat menyimpan data kategori.', $redirect);
}
