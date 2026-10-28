<?php

function normalizeAdminCrfReportDateRange(?string $from, ?string $to): array
{
    $today = new DateTimeImmutable('today');
    if (($from === null || $from === '') && ($to === null || $to === '')) {
        return [
            'from' => $today->modify('first day of January')->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
        ];
    }

    $parseDate = static function (?string $value): ?DateTimeImmutable {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    };

    $start = $parseDate($from);
    $end = $parseDate($to);
    if ($start === null || $end === null || $start > $end) {
        throw new InvalidArgumentException('Tanggal awal dan tanggal akhir tidak valid.');
    }

    return [
        'from' => $start->format('Y-m-d'),
        'to' => $end->format('Y-m-d'),
    ];
}

function getAdminCrfReport(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $submittedDate = 'COALESCE(tgl_pengajuan, DATE(tgl_dibuat))';
    $baseWhere = "status <> 'Draft'
        AND {$submittedDate} >= :date_from
        AND {$submittedDate} <= :date_to";
    $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];

    $monthlyStmt = $pdo->prepare(
        "SELECT DATE_FORMAT({$submittedDate}, '%Y-%m') AS report_month, COUNT(*) AS total
         FROM crf_pengajuan
         WHERE {$baseWhere}
         GROUP BY DATE_FORMAT({$submittedDate}, '%Y-%m')"
    );
    $monthlyStmt->execute($params);
    $monthlyCounts = [];
    foreach ($monthlyStmt->fetchAll() as $row) {
        $monthlyCounts[$row['report_month']] = (int) $row['total'];
    }

    $statusLabels = [
        'Belum Ditindak Lanjuti' => 'Belum Ditindak Lanjuti',
        'Perlu Revisi' => 'Perlu Revisi',
        'Dalam Proses' => 'Dalam Proses',
        'Solve' => 'Selesai',
        'Cancel' => 'Dibatalkan',
    ];
    $statusColors = [
        'Belum Ditindak Lanjuti' => '#64748b',
        'Perlu Revisi' => '#8b5cf6',
        'Dalam Proses' => '#f59e0b',
        'Solve' => '#22a45a',
        'Cancel' => '#ef4444',
    ];
    $statusCounts = array_fill_keys(array_keys($statusLabels), 0);
    $statusStmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS total
         FROM crf_pengajuan
         WHERE {$baseWhere}
         GROUP BY status"
    );
    $statusStmt->execute($params);

    foreach ($statusStmt->fetchAll() as $row) {
        if (array_key_exists($row['status'], $statusCounts)) {
            $statusCounts[$row['status']] = (int) $row['total'];
        }
    }

    $total = array_sum($statusCounts);
    $statuses = [];
    foreach ($statusLabels as $status => $label) {
        $count = $statusCounts[$status];
        $statuses[] = [
            'status' => $status,
            'label' => $label,
            'count' => $count,
            'percentage' => $total > 0 ? ($count / $total) * 100 : 0,
            'color' => $statusColors[$status],
        ];
    }

    // Persentase Tingkat Urgensi: memakai rumus yang sudah berjalan
    // (final Forum > level tersimpan > otomatis dari kategori dampak).
    $urgencyMeta = [
        'Tinggi' => ['label' => 'Tinggi', 'color' => '#ef4444'],
        'Normal' => ['label' => 'Normal', 'color' => '#f59e0b'],
        'Rendah' => ['label' => 'Rendah', 'color' => '#22a45a'],
        'Belum' => ['label' => 'Belum ditetapkan', 'color' => '#94a3b8'],
    ];
    $urgencyCounts = array_fill_keys(array_keys($urgencyMeta), 0);
    $urgencyStmt = $pdo->prepare(
        "SELECT level_urgensi_final, level, kategori_dampak, dampak_dipilih
         FROM crf_pengajuan
         WHERE {$baseWhere}"
    );
    $urgencyStmt->execute($params);
    foreach ($urgencyStmt->fetchAll() as $urgencyRow) {
        $effective = crfEffectiveUrgency($urgencyRow);
        $urgencyKey = isset($urgencyCounts[$effective]) && $effective !== 'Belum' ? $effective : 'Belum';
        $urgencyCounts[$urgencyKey]++;
    }
    $urgencyTotal = array_sum($urgencyCounts);
    $urgencies = [];
    foreach ($urgencyMeta as $urgencyKey => $meta) {
        $count = $urgencyCounts[$urgencyKey];
        $urgencies[] = [
            'key' => $urgencyKey,
            'label' => $meta['label'],
            'count' => $count,
            'percentage' => $urgencyTotal > 0 ? ($count / $urgencyTotal) * 100 : 0,
            'color' => $meta['color'],
        ];
    }

    // Komposisi Tipe Pengajuan. CRF lama tanpa tipe dihitung "Belum diisi" (hanya tampil bila ada).
    $typeMeta = [
        'Problem' => '#0ea5e9',
        'Permohonan Perubahan' => '#6366f1',
        'Permohonan Tambahan' => '#14b8a6',
    ];
    $typeCounts = array_fill_keys(array_keys($typeMeta), 0);
    $typeCounts['Belum diisi'] = 0;
    $typeStmt = $pdo->prepare(
        "SELECT jenis_pengajuan, COUNT(*) AS total
         FROM crf_pengajuan
         WHERE {$baseWhere}
         GROUP BY jenis_pengajuan"
    );
    $typeStmt->execute($params);
    foreach ($typeStmt->fetchAll() as $row) {
        $typeKey = isset($typeMeta[$row['jenis_pengajuan']]) ? $row['jenis_pengajuan'] : 'Belum diisi';
        $typeCounts[$typeKey] += (int) $row['total'];
    }
    $typeTotal = array_sum($typeCounts);
    $types = [];
    foreach ($typeCounts as $typeKey => $count) {
        if ($typeKey === 'Belum diisi' && $count === 0) {
            continue;
        }
        $types[] = [
            'label' => $typeKey,
            'count' => $count,
            'percentage' => $typeTotal > 0 ? ($count / $typeTotal) * 100 : 0,
            'color' => $typeMeta[$typeKey] ?? '#94a3b8',
        ];
    }

    $monthNames = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $firstMonth = new DateTimeImmutable(substr($dateFrom, 0, 7) . '-01');
    $lastMonth = new DateTimeImmutable(substr($dateTo, 0, 7) . '-01');
    $months = [];
    for ($monthDate = $firstMonth; $monthDate <= $lastMonth; $monthDate = $monthDate->modify('+1 month')) {
        $key = $monthDate->format('Y-m');
        $months[] = [
            'key' => $key,
            'label' => $monthNames[(int) $monthDate->format('n')] . ' ' . $monthDate->format('Y'),
            'count' => $monthlyCounts[$key] ?? 0,
            'requests' => [],
        ];
    }

    $requestStmt = $pdo->prepare(
        "SELECT id, nomor_register, nama_pemohon, dept_asal, kategori_perubahan, jenis_pengajuan,
                status, deskripsi_perubahan, {$submittedDate} AS report_date,
                DATE_FORMAT({$submittedDate}, '%Y-%m') AS report_month
         FROM crf_pengajuan
         WHERE {$baseWhere}
         ORDER BY report_month ASC, report_date ASC, id ASC"
    );
    $requestStmt->execute($params);
    $monthIndex = [];
    foreach ($months as $index => $month) {
        $monthIndex[$month['key']] = $index;
    }

    foreach ($requestStmt->fetchAll() as $request) {
        $index = $monthIndex[$request['report_month']] ?? null;
        if ($index !== null) {
            $months[$index]['requests'][] = $request;
        }
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'total' => $total,
        'months' => $months,
        'statuses' => $statuses,
        'urgencies' => $urgencies,
        'types' => $types,
    ];
}

/**
 * Warna kepatuhan SLA: >= 90% baik, 75-89% perlu perhatian, di bawah itu kurang.
 */
function adminSlaTone(?float $percent): string
{
    if ($percent === null) {
        return 'secondary';
    }

    return $percent >= 90 ? 'success' : ($percent >= 75 ? 'warning' : 'danger');
}

/**
 * Kepatuhan SLA (Response Time dan Resolution Time) CRF yang sudah disetujui
 * Kadep Operasional pada periode laporan. Semua durasi dalam jam kerja.
 *
 * Persentase hanya dihitung dari CRF yang sudah punya hasil (sudah direspons /
 * eksekusi selesai). CRF tanpa target Response Time (CRF lama) tidak ikut
 * dihitung pada Response Time. CRF yang masih berjalan tetapi sudah melewati
 * batas dihitung terpisah sebagai "berjalan melewati batas".
 *
 * @return array{total:int,response:array,resolution:array,by_category:array,by_urgency:array,violations:array,items:array}
 */
function getAdminCrfSlaCompliance(PDO $pdo, string $dateFrom, string $dateTo, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $submittedDate = 'COALESCE(cr.tgl_pengajuan, DATE(cr.tgl_dibuat))';

    try {
        $stmt = $pdo->prepare("
            SELECT cr.id, cr.nomor_register, cr.kategori_id, cc.nama AS kategori_nama, cr.status,
                   cr.level, cr.level_urgensi_final, cr.kategori_dampak, cr.dampak_dipilih, cr.pic_nama,
                   cr.sla_nilai, cr.sla_satuan, cr.sla_mulai, cr.sla_tenggat, cr.otomasi_selesai,
                   cr.sla_respon_nilai, cr.sla_respon_satuan, cr.sla_respon_tenggat, cr.sla_respon_at
            FROM crf_pengajuan cr
            LEFT JOIN crf_kategori cc ON cc.id = cr.kategori_id
            WHERE cr.status NOT IN ('Draft', 'Cancel')
              AND cr.sla_mulai IS NOT NULL
              AND {$submittedDate} >= :date_from
              AND {$submittedDate} <= :date_to
            ORDER BY cr.sla_mulai DESC, cr.id DESC
        ");
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Migrasi 026 belum dijalankan: laporan SLA kosong, laporan lain tetap jalan.
        error_log('getAdminCrfSlaCompliance: ' . $e->getMessage());
        $rows = [];
    }

    $newSummary = static fn(): array => [
        'measured' => 0, 'met' => 0, 'late' => 0, 'open_late' => 0, 'open' => 0, 'none' => 0,
        'seconds_sum' => 0, 'seconds_count' => 0,
    ];
    $finalize = static function (array $summary): array {
        $summary['percent'] = $summary['measured'] > 0 ? $summary['met'] / $summary['measured'] * 100 : null;
        $summary['average'] = $summary['seconds_count'] > 0 ? (int) round($summary['seconds_sum'] / $summary['seconds_count']) : null;
        $summary['tone'] = adminSlaTone($summary['percent']);

        return $summary;
    };
    $newGroup = static fn(string $name) => [
        'name' => $name, 'total' => 0, 'violations' => 0,
        'response' => ['measured' => 0, 'met' => 0, 'seconds_sum' => 0, 'seconds_count' => 0],
        'resolution' => ['measured' => 0, 'met' => 0, 'seconds_sum' => 0, 'seconds_count' => 0],
    ];

    // Tambahkan satu hasil ke rekap total, kelompok kategori, dan kelompok urgensi.
    $tally = static function (array &$summary, array &$categoryGroup, array &$urgencyGroup, array $result, string $key): void {
        $state = $result['state'];
        if (in_array($state, ['met', 'late'], true)) {
            $summary['measured']++;
            $summary[$state]++;
            foreach ([&$categoryGroup, &$urgencyGroup] as &$group) {
                $group[$key]['measured']++;
                $group[$key]['met'] += $state === 'met' ? 1 : 0;
            }
            unset($group);
        } elseif (in_array($state, ['open_late', 'open', 'none'], true)) {
            $summary[$state]++;
        }
        if ($result['seconds'] !== null) {
            $summary['seconds_sum'] += $result['seconds'];
            $summary['seconds_count']++;
            foreach ([&$categoryGroup, &$urgencyGroup] as &$group) {
                $group[$key]['seconds_sum'] += $result['seconds'];
                $group[$key]['seconds_count']++;
            }
            unset($group);
        }
    };

    $response = $newSummary();
    $resolution = $newSummary();
    $byCategory = [];
    $byUrgency = ['Tinggi' => $newGroup('Tinggi'), 'Normal' => $newGroup('Normal'), 'Rendah' => $newGroup('Rendah')];
    $violations = [];
    $items = [];

    foreach ($rows as $row) {
        $start = new DateTimeImmutable($row['sla_mulai']);
        $urgency = crfEffectiveUrgency($row);
        $urgency = isset($byUrgency[$urgency]) ? $urgency : 'Normal';
        $categoryKey = (int) ($row['kategori_id'] ?? 0);
        $byCategory[$categoryKey] = $byCategory[$categoryKey] ?? $newGroup((string) ($row['kategori_nama'] ?? 'Tanpa kategori'));

        // --- Response Time ---
        $resp = ['state' => 'none', 'seconds' => null, 'over' => 0, 'text' => '-'];
        $responTarget = !empty($row['sla_respon_nilai']) ? slaLabel($row['sla_respon_nilai'], $row['sla_respon_satuan']) : null;
        $responDue = !empty($row['sla_respon_tenggat']) ? new DateTimeImmutable($row['sla_respon_tenggat']) : null;
        if (!empty($row['sla_respon_at'])) {
            $at = new DateTimeImmutable($row['sla_respon_at']);
            $resp['seconds'] = slaWorkingSecondsBetween($start, $at);
            $took = $resp['seconds'] < 60 ? 'kurang dari 1 menit' : formatSlaDuration($resp['seconds']);
            if ($responDue === null) {
                $resp['state'] = 'no_target';
                $resp['text'] = 'Sudah direspons · ' . $took . ' (tanpa target)';
            } elseif ($at <= $responDue) {
                $resp['state'] = 'met';
                $resp['text'] = 'Sesuai SLA · ' . $took . ' (target ' . $responTarget . ')';
            } else {
                $resp['state'] = 'late';
                $resp['over'] = slaWorkingSecondsBetween($responDue, $at);
                $resp['text'] = 'Melebihi SLA · ' . $took . ' (target ' . $responTarget . ', lewat ' . formatSlaDuration($resp['over']) . ')';
            }
        } elseif ($responDue !== null) {
            if ($now > $responDue) {
                $resp['state'] = 'open_late';
                $resp['over'] = slaWorkingSecondsBetween($responDue, $now);
                $resp['text'] = 'Belum direspons · terlewati ' . formatSlaDuration($resp['over']) . ' (batas ' . $responDue->format('d-m-Y H:i') . ')';
            } else {
                $resp['state'] = 'open';
                $resp['text'] = 'Berjalan · batas ' . $responDue->format('d-m-Y H:i');
            }
        }

        // --- Resolution Time ---
        $resol = ['state' => 'none', 'seconds' => null, 'over' => 0, 'text' => '-'];
        $resolTarget = !empty($row['sla_nilai']) ? slaLabel($row['sla_nilai'], $row['sla_satuan']) : null;
        $resolDue = !empty($row['sla_tenggat']) ? new DateTimeImmutable($row['sla_tenggat']) : null;
        if (!empty($row['otomasi_selesai'])) {
            $done = new DateTimeImmutable($row['otomasi_selesai']);
            $resol['seconds'] = slaWorkingSecondsBetween($start, $done);
            $took = formatSlaDuration($resol['seconds']);
            if ($resolDue === null) {
                $resol['state'] = 'no_target';
                $resol['text'] = 'Selesai · ' . $took . ' (tanpa target)';
            } elseif ($done <= $resolDue) {
                $resol['state'] = 'met';
                $resol['text'] = 'Sesuai SLA · ' . $took . ' (target ' . $resolTarget . ')';
            } else {
                $resol['state'] = 'late';
                $resol['over'] = slaWorkingSecondsBetween($resolDue, $done);
                $resol['text'] = 'Melebihi SLA · ' . $took . ' (target ' . $resolTarget . ', lewat ' . formatSlaDuration($resol['over']) . ')';
            }
        } elseif ($resolDue !== null) {
            if ($now > $resolDue) {
                $resol['state'] = 'open_late';
                $resol['over'] = slaWorkingSecondsBetween($resolDue, $now);
                $resol['text'] = 'Berjalan · terlewati ' . formatSlaDuration($resol['over']) . ' (batas ' . $resolDue->format('d-m-Y H:i') . ')';
            } else {
                $resol['state'] = 'open';
                $resol['text'] = 'Berjalan · batas ' . $resolDue->format('d-m-Y H:i');
            }
        }

        $items[(int) $row['id']] = ['response' => $resp, 'resolution' => $resol];

        // --- Rekap ---
        $tally($response, $byCategory[$categoryKey], $byUrgency[$urgency], $resp, 'response');
        $tally($resolution, $byCategory[$categoryKey], $byUrgency[$urgency], $resol, 'resolution');

        $breached = in_array($resp['state'], ['late', 'open_late'], true) || in_array($resol['state'], ['late', 'open_late'], true);
        $byCategory[$categoryKey]['total']++;
        $byUrgency[$urgency]['total']++;
        if ($breached) {
            $byCategory[$categoryKey]['violations']++;
            $byUrgency[$urgency]['violations']++;
            $violations[] = [
                'id' => (int) $row['id'],
                'nomor_register' => (string) ($row['nomor_register'] ?: 'CRF #' . $row['id']),
                'kategori' => (string) ($row['kategori_nama'] ?? '-'),
                'urgensi' => $urgency,
                'pic' => (string) ($row['pic_nama'] ?? ''),
                'response' => $resp,
                'resolution' => $resol,
            ];
        }
    }

    $finishGroup = static function (array $group) use ($finalize): array {
        foreach (['response', 'resolution'] as $key) {
            $g = $group[$key];
            $g['percent'] = $g['measured'] > 0 ? $g['met'] / $g['measured'] * 100 : null;
            $g['average'] = $g['seconds_count'] > 0 ? (int) round($g['seconds_sum'] / $g['seconds_count']) : null;
            $g['tone'] = adminSlaTone($g['percent']);
            $group[$key] = $g;
        }

        return $group;
    };
    $categories = array_map($finishGroup, array_values($byCategory));
    usort($categories, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return [
        'total' => count($rows),
        'response' => $finalize($response),
        'resolution' => $finalize($resolution),
        'by_category' => $categories,
        'by_urgency' => array_map($finishGroup, array_values($byUrgency)),
        'violations' => $violations,
        'items' => $items,
    ];
}
