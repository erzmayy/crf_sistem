<?php
/**
 * actions/category_master.php
 * ---------------------------------------------------------------
 * Kelola master Kategori CRF (Admin):
 *   op = save | toggle | delete | add_member | remove_member
 * Anggota kategori = PIC CRF (tabel crf_kategori_pic).
 * Hapus kategori = soft delete (tgl_dihapus), data CRF lama aman.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    crfRedirect(crfUrl('admin.kategori'));
    exit;
}

verifyCsrf();

$pdo = getConnection();
$op = $_POST['op'] ?? '';

$redirect = crfUrl('admin.kategori');
// Kembali ke kartu kategori yang baru diubah.
if ((int) ($_POST['id'] ?? 0) > 0 && in_array($op, ['save', 'toggle', 'add_member', 'remove_member'], true)) {
    $redirect .= '#crf-' . (int) $_POST['id'];
}

function categoryMasterFinish(string $type, string $message, string $redirect): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    crfRedirect($redirect);
    exit;
}

$categoryId = (int) ($_POST['id'] ?? 0);
$table = 'crf_kategori';

try {
    switch ($op) {
        case 'save':
            $name = trim($_POST['nama'] ?? '');
            $description = trim($_POST['deskripsi'] ?? '');
            $slaValueRaw = trim($_POST['sla_nilai'] ?? '');
            $slaUnit = $_POST['sla_satuan'] ?? '';
            $isActive = !empty($_POST['aktif']) ? 1 : 0;
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
            // Matriks SLA: kolom sla_nilai/sla_satuan di atas = SLA Normal.
            foreach (['tinggi' => 'Tinggi', 'rendah' => 'Rendah'] as $levelKey => $levelLabel) {
                $levelValueRaw = trim($_POST['sla_' . $levelKey . '_nilai'] ?? '');
                $levelUnit = $_POST['sla_' . $levelKey . '_satuan'] ?? '';
                $levelValue = null;
                if ($levelValueRaw !== '') {
                    if (!is_numeric($levelValueRaw) || (float) $levelValueRaw <= 0) {
                        $errors[] = 'Nilai SLA ' . $levelLabel . ' harus angka lebih dari 0.';
                    } elseif (!in_array($levelUnit, ['Menit', 'Jam', 'Hari'], true)) {
                        $errors[] = 'Satuan SLA ' . $levelLabel . ' tidak valid.';
                    } else {
                        $levelValue = (float) $levelValueRaw;
                    }
                }
                $extra['sla_' . $levelKey . '_nilai'] = $levelValue;
                $extra['sla_' . $levelKey . '_satuan'] = $levelValue !== null ? $levelUnit : null;
            }

            // Urutan Resolution Time per urgensi: Tinggi tidak boleh lebih lama dari Normal, dan Normal
            // tidak boleh lebih lama dari Rendah (yang kosong ikut Normal, jadi hanya nilai terisi yang dicek).
            $resolutionSeconds = [];
            foreach ([
                'Tinggi' => [$extra['sla_tinggi_nilai'] ?? null, $extra['sla_tinggi_satuan'] ?? null],
                'Normal' => [$slaValue, $slaUnit],
                'Rendah' => [$extra['sla_rendah_nilai'] ?? null, $extra['sla_rendah_satuan'] ?? null],
            ] as $orderLevel => [$orderValue, $orderUnit]) {
                if ($orderValue !== null && $orderUnit !== null) {
                    $resolutionSeconds[$orderLevel] = [crfSlaSeconds((float) $orderValue, (string) $orderUnit), slaLabel($orderValue, $orderUnit)];
                }
            }
            foreach ([['Tinggi', 'Normal'], ['Normal', 'Rendah'], ['Tinggi', 'Rendah']] as [$faster, $slower]) {
                if (isset($resolutionSeconds[$faster], $resolutionSeconds[$slower])
                    && $resolutionSeconds[$faster][0] > $resolutionSeconds[$slower][0]
                    && !(($faster === 'Tinggi' && $slower === 'Rendah') && isset($resolutionSeconds['Normal']))
                ) {
                    $errors[] = 'Resolution Time ' . $faster . ' (' . $resolutionSeconds[$faster][1] . ') tidak boleh lebih lama dari ' . $slower . ' (' . $resolutionSeconds[$slower][1] . ').';
                }
            }
            // Resolution Time harus lebih besar dari target Response PIC (global) pada urgensi yang sama
            // (Tinggi/Rendah yang kosong ikut Normal, sama seperti saat dipakai di CRF).
            if (crfRespTableReady($pdo)) {
                $resolutionByLevel = [
                    'Normal' => [$slaValue, $slaUnit],
                    'Tinggi' => [$extra['sla_tinggi_nilai'] ?? null, $extra['sla_tinggi_satuan'] ?? null],
                    'Rendah' => [$extra['sla_rendah_nilai'] ?? null, $extra['sla_rendah_satuan'] ?? null],
                ];
                foreach (['Tinggi', 'Normal', 'Rendah'] as $level) {
                    $res = $resolutionByLevel[$level][0] !== null ? $resolutionByLevel[$level] : $resolutionByLevel['Normal'];
                    $resp = crfRespTarget($pdo, 'PIC_MULAI', $level);
                    if ($res[0] === null || $resp === null) {
                        continue;
                    }
                    if (crfSlaSeconds($resp['value'], $resp['unit']) >= crfSlaSeconds((float) $res[0], (string) $res[1])) {
                        $errors[] = 'Resolution Time ' . $level . ' (' . slaLabel($res[0], $res[1]) . ') harus lebih besar dari target Response PIC ' . $level . ' (' . slaLabel($resp['value'], $resp['unit']) . ').';
                    }
                }
            }
            $legacy = $_POST['kategori_perubahan_lama'] ?? 'Lainnya';
            if (!in_array($legacy, ['Aplikasi', 'Infrastruktur', 'Proses', 'Security', 'Lainnya'], true)) {
                $errors[] = 'Kelompok kategori laporan tidak valid.';
            }
            $extra['kategori_perubahan_lama'] = $legacy;
            // Nama unik terhadap kategori lain yang masih ada.
            $dupStmt = $pdo->prepare("SELECT id, tgl_dihapus FROM {$table} WHERE nama = :nama AND id <> :id LIMIT 1");
            $dupStmt->execute(['nama' => $name, 'id' => $categoryId]);
            $duplicate = $dupStmt->fetch();

            if ($duplicate && $duplicate['tgl_dihapus'] === null) {
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
                'nama'        => $name,
                'deskripsi' => $description !== '' ? $description : null,
                'sla_nilai'   => $slaValue,
                'sla_satuan'    => $slaUnit,
                'aktif'   => $isActive,
            ], $extra);

            if ($categoryId > 0) {
                $sets = implode(', ', array_map(static fn($col) => "{$col} = :{$col}", array_keys($fields)));
                $stmt = $pdo->prepare("UPDATE {$table} SET {$sets}, tgl_dihapus = NULL WHERE id = :id");
                $stmt->execute($fields + ['id' => $categoryId]);
                $message = 'Kategori "' . $name . '" berhasil diperbarui.';
            } else {
                $cols = implode(', ', array_keys($fields));
                $vals = implode(', ', array_map(static fn($col) => ':' . $col, array_keys($fields)));
                $pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$vals})")->execute($fields);
                $categoryId = (int) $pdo->lastInsertId();
                $message = 'Kategori "' . $name . '" berhasil ditambahkan.';
            }

            categoryMasterFinish('success', $message, $redirect);
            break;

        case 'toggle':
            $stmt = $pdo->prepare("UPDATE {$table} SET aktif = 1 - aktif WHERE id = :id AND tgl_dihapus IS NULL");
            $stmt->execute(['id' => $categoryId]);
            categoryMasterFinish(
                $stmt->rowCount() ? 'success' : 'danger',
                $stmt->rowCount() ? 'Status kategori berhasil diubah.' : 'Kategori tidak ditemukan.',
                $redirect
            );
            break;

        case 'delete':
            // Soft delete: data CRF yang memakai kategori ini tetap utuh.
            $stmt = $pdo->prepare("UPDATE {$table} SET aktif = 0, tgl_dihapus = NOW() WHERE id = :id AND tgl_dihapus IS NULL");
            $stmt->execute(['id' => $categoryId]);
            categoryMasterFinish(
                $stmt->rowCount() ? 'success' : 'danger',
                $stmt->rowCount() ? 'Kategori dihapus (data yang sudah memakai kategori ini tetap tersimpan).' : 'Kategori tidak ditemukan.',
                $redirect
            );
            break;

        case 'add_member':
        case 'remove_member':
            $userId = (int) ($_POST['pengguna_id'] ?? 0);
            $category = findCrfCategory($pdo, $categoryId);
            $memberUser = findCrfUserById($pdo, $userId);

            if (!$category || !$memberUser) {
                categoryMasterFinish('danger', 'Kategori atau user tidak ditemukan.', $redirect);
            }

            $memberName = crfActorName($memberUser);

            if ($op === 'add_member') {
                $pdo->prepare('
                    INSERT INTO crf_kategori_pic (kategori_id, pengguna_id, pengguna_nama)
                    VALUES (:category_id, :pengguna_id, :pengguna_nama)
                    ON DUPLICATE KEY UPDATE pengguna_nama = VALUES(pengguna_nama)
                ')->execute(['category_id' => $categoryId, 'pengguna_id' => $userId, 'pengguna_nama' => $memberName]);
                $message = $memberName . ' ditambahkan sebagai PIC Otomasi kategori ' . $category['nama'] . '.';
            } else {
                $pdo->prepare('DELETE FROM crf_kategori_pic WHERE kategori_id = :category_id AND pengguna_id = :pengguna_id')
                    ->execute(['category_id' => $categoryId, 'pengguna_id' => $userId]);
                $message = $memberName . ' dihapus dari PIC Otomasi kategori ' . $category['nama'] . '.';
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
