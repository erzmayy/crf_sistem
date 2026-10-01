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
    $submittedDate = 'COALESCE(submission_date, DATE(created_at))';
    $baseWhere = "status <> 'Draft'
        AND {$submittedDate} >= :date_from
        AND {$submittedDate} <= :date_to";
    $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];

    $monthlyStmt = $pdo->prepare(
        "SELECT DATE_FORMAT({$submittedDate}, '%Y-%m') AS report_month, COUNT(*) AS total
         FROM change_requests
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
         FROM change_requests
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
        "SELECT id, request_number, full_name, from_department, change_category,
                status, change_description, {$submittedDate} AS report_date,
                DATE_FORMAT({$submittedDate}, '%Y-%m') AS report_month
         FROM change_requests
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
    ];
}
