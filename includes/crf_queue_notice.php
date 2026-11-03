<?php
/**
 * includes/crf_queue_notice.php
 * ---------------------------------------------------------------
 * Keterangan untuk halaman detail per peran (CMO, Kadep Operasional, Otomasi)
 * bila CRF yang dibuka tidak berada di antrean peran itu, misalnya dari tautan
 * "Buka CRF" di email. Menggantikan pesan "CRF tidak ditemukan pada antrean ..."
 * dengan keterangan singkat: belum sampai, atau sudah ditindaklanjuti oleh siapa
 * dan kapan, ditambah tahap CRF saat ini.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crf_url.php';

/**
 * @param string[] $earlierStages  tahap_alur yang berarti CRF belum sampai ke antrean ini
 * @param string[] $ownActivities  aktivitas di crf_log_aktivitas yang dilakukan oleh peran antrean ini
 * @return string HTML isi halaman (kotak keterangan dan tombol)
 */
function crfQueueNoticeHtml(
    PDO $pdo,
    int $crfId,
    string $queueName,
    array $earlierStages,
    array $ownActivities,
    string $backHref
): string {
    $back = '<a href="' . h($backHref) . '" class="crf-back-btn"><i class="bi bi-arrow-left"></i> Kembali</a>';

    $crf = null;
    if ($crfId > 0 && canAccessCrf($pdo, $crfId)) {
        $stmt = $pdo->prepare('SELECT id, status, tahap_alur FROM crf_pengajuan WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $crfId]);
        $crf = $stmt->fetch() ?: null;
    }

    if ($crf === null || ($crf['status'] ?? '') === 'Draft') {
        return '<div class="alert alert-danger">CRF tidak ditemukan atau Anda tidak memiliki akses.</div>' . $back;
    }

    $stage = (string) $crf['tahap_alur'];
    // CRF yang sudah selesai/dibatalkan: tampilkan statusnya, bukan nama tahap terakhir.
    $nowText = in_array($crf['status'], ['Solve', 'Cancel'], true)
        ? statusLabel((string) $crf['status'])
        : workflowStageLabel($stage);

    if (in_array($stage, $earlierStages, true)) {
        $headline = 'Belum sampai di antrean ' . h($queueName);
    } else {
        $own = null;
        if ($ownActivities) {
            $placeholders = implode(',', array_fill(0, count($ownActivities), '?'));
            $stmt = $pdo->prepare(
                "SELECT pelaksana, tgl_dibuat FROM crf_log_aktivitas
                 WHERE pengajuan_id = ? AND aktivitas IN ($placeholders)
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(array_merge([$crfId], array_values($ownActivities)));
            $own = $stmt->fetch() ?: null;
        }

        $headline = $own
            ? 'Sudah ditindaklanjuti oleh ' . h((string) $own['pelaksana'])
                . ' <span class="fw-normal">&middot; ' . h(crfQueueNoticeDate((string) $own['tgl_dibuat'])) . '</span>'
            : 'Sudah tidak berada di antrean ' . h($queueName);
    }

    $buttons = $back;
    if (isAdmin()) {
        $buttons .= ' <a href="' . h(crfUrl('admin.detail', ['id' => $crfId])) . '" class="btn btn-sm btn-crf-outline ms-2">'
            . '<i class="bi bi-file-earmark-text"></i> Buka detail CRF</a>';
    }

    return '<div class="alert alert-info">'
        . '<div><strong>' . $headline . '</strong></div>'
        . '<div>Saat ini: ' . h($nowText) . '</div>'
        . '</div>' . $buttons;
}

/** "11 Okt 2026, 12.52" */
function crfQueueNoticeDate(string $value): string
{
    $time = strtotime($value);
    if ($time === false) {
        return $value;
    }

    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    return date('j', $time) . ' ' . $months[(int) date('n', $time)] . ' ' . date('Y', $time) . ', ' . date('H.i', $time);
}
