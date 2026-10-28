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
$slaReport = getAdminCrfSlaCompliance(getConnection(), $dateRange['from'], $dateRange['to']);
$slaPercentText = static fn(?float $percent): string => $percent === null ? '-' : number_format($percent, 1, ',', '.') . '%';
$slaAverageText = static fn(?int $seconds): string => $seconds === null ? '-' : formatSlaDuration($seconds);
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
            . '<th>Isi Pengajuan</th><th>Response Time</th><th>Resolution Time</th></tr></thead><tbody>';
        foreach ($month['requests'] as $request) {
            $monthlyDetails .= '<tr><td>' . $escape($request['nomor_register'])
                . '</td><td>' . $escape($request['nama_pemohon'])
                . '</td><td>' . $escape($request['dept_asal'])
                . '</td><td>' . $escape($request['kategori_perubahan'])
                . '</td><td>' . $escape(statusLabel($request['status']))
                . '</td><td>' . $escape(date('d-m-Y', strtotime($request['report_date'])))
                . '</td><td>' . $escape($request['deskripsi_perubahan'])
                . '</td><td>' . $escape($slaReport['items'][(int) $request['id']]['response']['text'] ?? '-')
                . '</td><td>' . $escape($slaReport['items'][(int) $request['id']]['resolution']['text'] ?? '-')
                . '</td></tr>';
        }
        $monthlyDetails .= '</tbody></table>';
    }

    // Ringkasan kepatuhan SLA (Response Time dan Resolution Time).
    $slaSummaryRows = '';
    foreach (['response' => 'Response Time', 'resolution' => 'Resolution Time'] as $slaKey => $slaTitle) {
        $s = $slaReport[$slaKey];
        $slaSummaryRows .= '<tr><td>' . $slaTitle . '</td><td>' . $s['measured'] . '</td><td>' . $s['met']
            . '</td><td>' . $slaPercentText($s['percent']) . '</td><td>' . $escape($slaAverageText($s['average']))
            . '</td><td>' . $s['open_late'] . '</td></tr>';
    }
    // Dua tabel terpisah (per kategori, per level urgensi). Keduanya memotong data CRF yang sama,
    // jadi barisnya tidak dijumlahkan antartabel.
    $slaGroupTable = static function (string $heading, string $nameLabel, array $groups) use ($escape, $slaPercentText): string {
        $cell = static fn(array $x): string => $x['percent'] === null
            ? '-'
            : $slaPercentText($x['percent']) . ' (' . $x['met'] . '/' . $x['measured'] . ')';
        $rows = '';
        foreach ($groups as $g) {
            $rows .= '<tr><td>' . $escape($g['name']) . '</td><td>' . $g['total']
                . '</td><td>' . $cell($g['response'])
                . '</td><td>' . $cell($g['resolution'])
                . '</td><td>' . $g['violations'] . '</td></tr>';
        }

        return '<h3>' . $heading . '</h3><table><thead><tr><th>' . $nameLabel . '</th><th>CRF</th>'
            . '<th>Response Time</th><th>Resolution Time</th><th>CRF Melewati SLA</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    };
    $slaSection = '<h2>Kepatuhan SLA</h2><div class="muted">CRF yang disetujui Kadep Operasional pada periode ini: '
        . $slaReport['total'] . ' CRF. Persentase = tepat waktu dari CRF yang sudah punya hasil; durasi dalam jam kerja.</div>'
        . '<table><thead><tr><th>Jenis</th><th>CRF Diukur</th><th>Tepat Waktu</th><th>Persentase</th>'
        . '<th>Rata-rata</th><th>Berjalan, Terlewati</th></tr></thead><tbody>' . $slaSummaryRows . '</tbody></table>'
        . $slaGroupTable('Per Kategori', 'Kategori', $slaReport['by_category'])
        . $slaGroupTable('Per Level Urgensi', 'Level Urgensi', $slaReport['by_urgency'])
        . '<div class="muted">Tabel per kategori dan per level urgensi memotong CRF yang sama dengan cara berbeda, '
        . 'sehingga angkanya tidak dijumlahkan antartabel.</div>';

    $html = '<!doctype html><html lang="id"><head><meta charset="UTF-8">'
        . '<style>body{font-family:Arial,sans-serif;color:#263445;font-size:9px}'
        . 'h1{font-size:19px;margin-bottom:4px}h2{font-size:12px;margin:18px 0 7px}h3{font-size:10px;margin:10px 0 5px}'
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
        . '</tbody></table>' . $slaSection . '<h2>Data Pengajuan CRF per Bulan</h2>'
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
    'Response Time',
    'Resolution Time',
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
        '',
        '',
    ];
    foreach ($month['requests'] as $request) {
        $requestRows[] = [
            $month['label'],
            $request['nomor_register'],
            $request['nama_pemohon'],
            $request['dept_asal'],
            $request['kategori_perubahan'],
            statusLabel($request['status']),
            date('d-m-Y', strtotime($request['report_date'])),
            $request['deskripsi_perubahan'],
            $slaReport['items'][(int) $request['id']]['response']['text'] ?? '-',
            $slaReport['items'][(int) $request['id']]['resolution']['text'] ?? '-',
        ];
        $textLength = function_exists('mb_strlen')
            ? mb_strlen((string) ($request['deskripsi_perubahan'] ?? ''), 'UTF-8')
            : strlen((string) ($request['deskripsi_perubahan'] ?? ''));
        $lineCount = max(1, (int) ceil($textLength / 66));
        $requestRowHeights[count($requestRows) + 4] = min(120, max(34, 18 + ($lineCount * 14)));
    }
}

// Lembar kepatuhan SLA: baris total, lalu per kategori, lalu per urgensi.
$slaSheetRows = [[
    'Dikelompokkan Menurut', 'Nama', 'CRF Disetujui',
    'Response Diukur', 'Response Tepat Waktu', 'Response %', 'Rata-rata Response',
    'Resolution Diukur', 'Resolution Tepat Waktu', 'Resolution %', 'Rata-rata Resolution',
    'CRF Melewati SLA',
]];
$slaSheetRows[] = [
    'Total', 'Semua kategori dan urgensi', $numberCell($slaReport['total']),
    $numberCell($slaReport['response']['measured']), $numberCell($slaReport['response']['met']),
    $slaPercentText($slaReport['response']['percent']), $slaAverageText($slaReport['response']['average']),
    $numberCell($slaReport['resolution']['measured']), $numberCell($slaReport['resolution']['met']),
    $slaPercentText($slaReport['resolution']['percent']), $slaAverageText($slaReport['resolution']['average']),
    $numberCell(count($slaReport['violations'])),
];
foreach (['Kategori' => $slaReport['by_category'], 'Urgensi' => $slaReport['by_urgency']] as $slaGroupType => $slaGroups) {
    foreach ($slaGroups as $g) {
        $slaSheetRows[] = [
            $slaGroupType === 'Urgensi' ? 'Level Urgensi' : 'Kategori', $g['name'], $numberCell($g['total']),
            $numberCell($g['response']['measured']), $numberCell($g['response']['met']),
            $slaPercentText($g['response']['percent']), $slaAverageText($g['response']['average']),
            $numberCell($g['resolution']['measured']), $numberCell($g['resolution']['met']),
            $slaPercentText($g['resolution']['percent']), $slaAverageText($g['resolution']['average']),
            $numberCell($g['violations']),
        ];
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
    'Kepatuhan SLA' => [
        'title' => $reportTitle,
        'subtitle' => 'Response Time dan Resolution Time CRF yang disetujui Kadep Operasional (jam kerja). Baris Kategori dan Level Urgensi memotong CRF yang sama, jangan dijumlahkan.',
        'period' => $reportPeriod,
        'rows' => $slaSheetRows,
        'header_row' => 5,
        'widths' => [14, 26, 16, 16, 20, 14, 20, 16, 22, 14, 20, 18],
        'section_rows' => [],
        'row_heights' => [],
    ],
    'Data Pengajuan Bulanan' => [
        'title' => $reportTitle,
        'subtitle' => 'Rincian pengajuan CRF dikelompokkan menurut bulan pengajuan',
        'period' => $reportPeriod,
        'rows' => $requestRows,
        'header_row' => 5,
        'widths' => [23, 20, 27, 25, 19, 25, 19, 68, 46, 46],
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
