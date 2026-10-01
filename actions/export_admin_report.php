<?php
/**
 * Ekspor laporan statistik dan rincian pengajuan CRF ke PDF atau Excel.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_crf_report.php';
require_once __DIR__ . '/../includes/admin_crf_xlsx.php';

requireAdmin();

$format = is_string($_GET['format'] ?? null) ? $_GET['format'] : '';
if ($format === 'excel') {
    $format = 'xlsx';
}
if (!in_array($format, ['pdf', 'xlsx'], true)) {
    http_response_code(400);
    exit('Format ekspor tidak valid.');
}

if (
    (array_key_exists('report_date_from', $_GET) && !is_string($_GET['report_date_from']))
    || (array_key_exists('report_date_to', $_GET) && !is_string($_GET['report_date_to']))
) {
    http_response_code(400);
    exit('Rentang tanggal tidak valid.');
}
$dateFromInput = is_string($_GET['report_date_from'] ?? null)
    ? $_GET['report_date_from']
    : null;
$dateToInput = is_string($_GET['report_date_to'] ?? null)
    ? $_GET['report_date_to']
    : null;
try {
    $dateRange = normalizeAdminCrfReportDateRange($dateFromInput, $dateToInput);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    exit(h($exception->getMessage()));
}

$report = getAdminCrfReport(
    getConnection(),
    $dateRange['from'],
    $dateRange['to']
);
$dateFromLabel = date('d-m-Y', strtotime($report['date_from']));
$dateToLabel = date('d-m-Y', strtotime($report['date_to']));
$fileDateRange = $report['date_from'] . '-sampai-' . $report['date_to'];

if ($format === 'pdf') {
    require_once __DIR__ . '/../vendor/autoload.php';

    $escape = static fn ($value): string => htmlspecialchars(
        (string) ($value ?? '-'),
        ENT_QUOTES,
        'UTF-8'
    );

    $monthRows = '';
    foreach ($report['months'] as $month) {
        $monthRows .= '<tr><td>' . $escape($month['label']) . '</td><td>'
            . $month['count'] . '</td></tr>';
    }

    $statusRows = '';
    foreach ($report['statuses'] as $status) {
        $statusRows .= '<tr><td>' . $escape($status['label']) . '</td><td>'
            . $status['count'] . '</td><td>'
            . number_format($status['percentage'], 1, ',', '.') . '%</td></tr>';
    }

    $monthlyDetails = '';
    foreach ($report['months'] as $month) {
        $monthlyDetails .= '<h2>' . $escape($month['label']) . ' — '
            . $month['count'] . ' CRF</h2>';
        if (!$month['requests']) {
            $monthlyDetails .= '<p class="muted">Tidak ada pengajuan pada bulan ini.</p>';
            continue;
        }

        $monthlyDetails .= '<table><thead><tr><th>No. Register</th><th>Nama Pemohon</th>'
            . '<th>Departemen</th><th>Kategori</th><th>Status</th><th>Tanggal</th>'
            . '<th>Isi Pengajuan</th></tr></thead><tbody>';
        foreach ($month['requests'] as $request) {
            $monthlyDetails .= '<tr><td>' . $escape($request['request_number'])
                . '</td><td>' . $escape($request['full_name'])
                . '</td><td>' . $escape($request['from_department'])
                . '</td><td>' . $escape($request['change_category'])
                . '</td><td>' . $escape(statusLabel($request['status']))
                . '</td><td>' . $escape(date('d-m-Y', strtotime($request['report_date'])))
                . '</td><td>' . $escape($request['change_description']) . '</td></tr>';
        }
        $monthlyDetails .= '</tbody></table>';
    }

    $html = '<!doctype html><html lang="id"><head><meta charset="UTF-8">'
        . '<style>body{font-family:Arial,sans-serif;color:#263445;font-size:9px}'
        . 'h1{font-size:19px;margin-bottom:4px}h2{font-size:12px;margin:18px 0 7px}'
        . '.muted{color:#64748b}table{width:100%;border-collapse:collapse;margin-bottom:10px}'
        . 'th,td{border:1px solid #d8e0e8;padding:5px;text-align:left;vertical-align:top}'
        . 'th{background:#edf7fd}td:nth-child(1),td:nth-child(5),td:nth-child(6){white-space:nowrap}'
        . '</style></head><body><h1>Laporan Statistik Change Request Form</h1>'
        . '<div class="muted">Periode ' . $dateFromLabel . ' sampai ' . $dateToLabel
        . ' · Total pengajuan: ' . $report['total'] . ' CRF</div>'
        . '<h2>Tren CRF per Bulan</h2><table><thead><tr><th>Bulan</th>'
        . '<th>Jumlah CRF</th></tr></thead><tbody>' . $monthRows
        . '</tbody></table><h2>Status Distribusi CRF</h2>'
        . '<table><thead><tr><th>Status</th><th>Jumlah CRF</th><th>Persentase</th>'
        . '</tr></thead><tbody>' . $statusRows
        . '</tbody></table><h2>Data Pengajuan CRF per Bulan</h2>'
        . $monthlyDetails . '</body></html>';

    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'Arial');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('Laporan-Statistik-CRF-' . $fileDateRange . '.pdf', ['Attachment' => true]);
    exit;
}

$numberCell = static fn ($value): array => ['type' => 'Number', 'value' => $value];

$trendRows = [
    ['Bulan', 'Jumlah CRF'],
];
foreach ($report['months'] as $month) {
    $trendRows[] = [$month['label'], $numberCell($month['count'])];
}
$trendRows[] = ['TOTAL PENGAJUAN', $numberCell($report['total'])];

$statusRows = [
    ['Status Pengajuan', 'Jumlah CRF', 'Persentase'],
];
foreach ($report['statuses'] as $status) {
    $statusRows[] = [
        $status['label'],
        $numberCell($status['count']),
        number_format($status['percentage'], 1, ',', '.') . '%',
    ];
}

$requestRows = [[
    'Bulan',
    'No. Register',
    'Nama Pemohon',
    'Departemen',
    'Kategori',
    'Status',
    'Tanggal Pengajuan',
    'Isi Pengajuan',
]];
$sectionRows = [];
$requestRowHeights = [];
foreach ($report['months'] as $month) {
    $sectionRows[] = count($requestRows) + 5;
    $requestRows[] = [
        $month['label'] . ' (' . $month['count'] . ' CRF)',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
    ];
    foreach ($month['requests'] as $request) {
        $requestRows[] = [
            $month['label'],
            $request['request_number'],
            $request['full_name'],
            $request['from_department'],
            $request['change_category'],
            statusLabel($request['status']),
            date('d-m-Y', strtotime($request['report_date'])),
            $request['change_description'],
        ];
        $textLength = function_exists('mb_strlen')
            ? mb_strlen((string) ($request['change_description'] ?? ''), 'UTF-8')
            : strlen((string) ($request['change_description'] ?? ''));
        $lineCount = max(1, (int) ceil($textLength / 66));
        $requestRowHeights[count($requestRows) + 4] = min(120, max(34, 18 + ($lineCount * 14)));
    }
}

$reportTitle = 'LAPORAN STATISTIK CHANGE REQUEST FORM';
$reportSubtitle = 'Rekap tren, distribusi status, dan rincian pengajuan CRF';
$reportPeriod = 'Periode: ' . $dateFromLabel . ' s.d. ' . $dateToLabel
    . '     |     Total: ' . $report['total'] . ' CRF'
    . '     |     Dicetak: ' . date('d-m-Y H:i') . ' WIB';

$sheets = [
    'Tren Bulanan' => [
        'title' => $reportTitle,
        'subtitle' => 'Ringkasan jumlah CRF berdasarkan bulan pengajuan',
        'period' => $reportPeriod,
        'rows' => $trendRows,
        'header_row' => 5,
        'widths' => [28, 20],
        'section_rows' => [count($trendRows) + 4],
        'row_heights' => [],
    ],
    'Distribusi Status' => [
        'title' => $reportTitle,
        'subtitle' => 'Ringkasan status seluruh pengajuan dalam periode terpilih',
        'period' => $reportPeriod,
        'rows' => $statusRows,
        'header_row' => 5,
        'widths' => [34, 20, 20],
        'section_rows' => [],
        'row_heights' => [],
    ],
    'Data Pengajuan Bulanan' => [
        'title' => $reportTitle,
        'subtitle' => 'Rincian pengajuan CRF dikelompokkan menurut bulan pengajuan',
        'period' => $reportPeriod,
        'rows' => $requestRows,
        'header_row' => 5,
        'widths' => [23, 20, 27, 25, 19, 25, 19, 68],
        'section_rows' => $sectionRows,
        'row_heights' => $requestRowHeights,
    ],
];
$xlsxContents = '';
try {
    $xlsxContents = buildAdminCrfXlsx($sheets);
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit(h($exception->getMessage()));
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Laporan-Statistik-CRF-' . $fileDateRange . '.xlsx"');
header('Content-Length: ' . strlen($xlsxContents));
echo $xlsxContents;
