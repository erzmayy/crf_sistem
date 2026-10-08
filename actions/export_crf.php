<?php
/**
 * actions/export_crf.php
 * ---------------------------------------------------------------
 * Export satu CRF menjadi PDF menggunakan Dompdf.
 * Tampilan dibuat menyerupai dokumen CRF perusahaan.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireLogin();

$pdo = getConnection();

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('ID CRF tidak valid.');
}

if (!canAccessCrf($pdo, $id)) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke CRF ini.');
}

/* ---------------------------------------------------------------
 * Ambil data CRF
 * --------------------------------------------------------------- */
$stmt = $pdo->prepare(
    'SELECT cr.*
     FROM change_requests cr
     WHERE cr.id = :id
     LIMIT 1'
);

$stmt->execute(['id' => $id]);
$crf = $stmt->fetch();

if (!$crf) {
    http_response_code(404);
    exit('CRF tidak ditemukan.');
}

/* ---------------------------------------------------------------
 * Ambil attachment
 * --------------------------------------------------------------- */
$attStmt = $pdo->prepare(
    'SELECT original_name, file_type, file_size
     FROM attachments
     WHERE change_request_id = :id AND category IS NULL
     ORDER BY uploaded_at ASC'
);

$attStmt->execute(['id' => $id]);
$attachments = $attStmt->fetchAll();

/* ---------------------------------------------------------------
 * Helper
 * --------------------------------------------------------------- */
function pdfText($value): string
{
    return nl2br(
        htmlspecialchars(
            trim((string) ($value ?? '')),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

function pdfValue($value): string
{
    $value = trim((string) ($value ?? ''));

    if ($value === '') {
        return '-';
    }

    return pdfText($value);
}

/* ---------------------------------------------------------------
 * Data tampilan
 * --------------------------------------------------------------- */
$submissionDate = !empty($crf['submission_date'])
    ? formatTanggalIndonesia(new DateTime($crf['submission_date']))
    : '-';

$toValue = trim(
    ($crf['to_department'] ?? '') .
    (!empty($crf['to_division']) ? ' (' . $crf['to_division'] . ')' : '')
);

$fromValue = trim(
    ($crf['from_department'] ?? '') .
    (!empty($crf['from_division']) ? ' (' . $crf['from_division'] . ')' : '')
);

$statusDisplay = statusLabel($crf['status'] ?? '');

/*
 * Tanda pada PDF yang dicetak sebelum CRF selesai, agar salinan lama tidak
 * dikira dokumen akhir. CRF berstatus Solve dicetak tanpa tanda.
 */
$watermarkText = [
    'Solve' => '',
    'Cancel' => 'DIBATALKAN',
][$crf['status'] ?? ''] ?? 'BELUM FINAL';
// Pita peringatan di atas dokumen (tidak menutupi isi seperti watermark diagonal).
$watermarkHtml = $watermarkText === '' ? '' : '
    <div class="draft-note">
        <strong>' . htmlspecialchars($watermarkText, ENT_QUOTES, 'UTF-8') . '</strong>
        &nbsp;·&nbsp; Dokumen ' . ($watermarkText === 'DIBATALKAN' ? 'CRF yang dibatalkan' : 'belum final') . ' · status saat dicetak: '
        . htmlspecialchars($statusDisplay, ENT_QUOTES, 'UTF-8') . ' · dicetak ' . date('d-m-Y H:i') . '
    </div>';

$categoryDisplay = $crf['change_category'] ?? '-';

if (!empty($crf['change_category_detail'])) {
    $categoryDisplay .= ' - ' . $crf['change_category_detail'];
}

$budgetDisplay = budgetTypeLabel($crf['budget_type'] ?? null);

if ($crf['budget_amount'] !== null && $crf['budget_amount'] !== '') {
    $budgetDisplay .= ' - ' . formatRupiah($crf['budget_amount']);
}

/* ---------------------------------------------------------------
 * Daftar attachment
 * --------------------------------------------------------------- */
$attachmentHtml = '';

if (!$attachments) {

    $attachmentHtml = '<span class="muted">Tidak ada file yang dilampirkan.</span>';

} else {

    $items = [];

    foreach ($attachments as $file) {
        $name = htmlspecialchars(
            $file['original_name'],
            ENT_QUOTES,
            'UTF-8'
        );

        $size = round(((int) $file['file_size']) / 1024);

        $items[] = $name . ' (' . $size . ' KB)';
    }

    $attachmentHtml = implode('<br>', $items);
}

/* ---------------------------------------------------------------
 * Level Urgensi
 * --------------------------------------------------------------- */
$levelDisplay = !empty($crf['level'])
    ? $crf['level']
    : (crfUrgencyForImpact($crf['impact_category'] ?? null) ?? 'Belum ditentukan');

$workflowStageDisplay = !empty($crf['workflow_stage'])
    ? workflowStageLabel($crf['workflow_stage'])
    : '-';

$slaDisplay = (!empty($crf['sla_value']) && !empty($crf['sla_unit']))
    ? rtrim(rtrim(number_format((float) $crf['sla_value'], 2, '.', ''), '0'), '.') . ' ' . $crf['sla_unit']
    : '-';

/* ---------------------------------------------------------------
 * Tanggal Solve / Cancel
 * --------------------------------------------------------------- */
$processDateHtml = '';

if (
    ($crf['status'] ?? '') === 'Solve' &&
    !empty($crf['solved_at'])
) {
    $processDateHtml = '
        <div class="process-date">
            Diselesaikan pada:
            ' . htmlspecialchars(
                date('d-m-Y H:i', strtotime($crf['solved_at'])),
                ENT_QUOTES,
                'UTF-8'
            ) . '
        </div>
    ';
}

if (
    ($crf['status'] ?? '') === 'Cancel' &&
    !empty($crf['cancelled_at'])
) {
    $processDateHtml = '
        <div class="process-date">
            Dibatalkan pada:
            ' . htmlspecialchars(
                date('d-m-Y H:i', strtotime($crf['cancelled_at'])),
                ENT_QUOTES,
                'UTF-8'
            ) . '
        </div>
    ';
}

/* ---------------------------------------------------------------
 * Ambil tanggal aktivitas workflow untuk kebutuhan export PDF
 * --------------------------------------------------------------- */
$activityStmt = $pdo->prepare("
    SELECT activity, created_at
    FROM crf_activity_logs
    WHERE change_request_id = :id
    ORDER BY created_at ASC, id ASC
");

$activityStmt->execute(['id' => $id]);
$activityRows = $activityStmt->fetchAll();

$firstActivityDate = static function (array $rows, array $activities): ?string {
    foreach ($rows as $row) {
        if (in_array($row['activity'], $activities, true)) {
            return $row['created_at'];
        }
    }

    return null;
};

$cmoFilterAt = $firstActivityDate($activityRows, ['Lolos Filter CMO', 'Diteruskan ke Kepala Departemen']);
$cmoFilterDate = $cmoFilterAt
    ? formatTanggalIndonesia(new DateTime($cmoFilterAt))
    : null;

$implementationPirAt = $firstActivityDate($activityRows, ['Otomasi Selesai']);

$implementationPirDate = $implementationPirAt
    ? formatTanggalIndonesia(new DateTime($implementationPirAt))
    : null;

/* ---------------------------------------------------------------
 * Data tambahan untuk tampilan
 * --------------------------------------------------------------- */
$dateOnly = static function (?string $value): ?string {
    return !empty($value) ? formatTanggalIndonesia(new DateTime($value)) : null;
};

$shortDate = static fn(?string $value): ?string => !empty($value) ? date('d-m-Y', strtotime($value)) : null;

$implementationDateText = $dateOnly($crf['implementation_date'] ?? null) ?? '-';
$pirDateText = $dateOnly($crf['pir_date'] ?? null) ?? '-';

// Langkah proses pengajuan (tanggal kosong = belum dilalui).
$processSteps = [
    ['role' => 'Pemohon',    'action' => 'Mengajukan CRF', 'name' => $crf['full_name'] ?? '',             'date' => $shortDate($crf['submission_date'] ?? null)],
    ['role' => 'CMO',        'action' => 'Verifikasi',     'name' => '',                                  'date' => $shortDate($cmoFilterAt ?? null)],
    ['role' => 'Kadep Ops.', 'action' => 'Persetujuan',    'name' => '',                                  'date' => $shortDate($crf['kadep_operasional_approved_at'] ?? null)],
    ['role' => 'PIC CRF',    'action' => 'Eksekusi',       'name' => $crf['assigned_handler_name'] ?? '', 'date' => $shortDate($crf['automation_completed_at'] ?? null)],
    ['role' => 'CMO',        'action' => 'UAT lulus',      'name' => '',                                  'date' => $shortDate($crf['uat_passed_at'] ?? null)],
    ['role' => 'Pemohon',    'action' => 'PIR',            'name' => '',                                  'date' => $shortDate($crf['pir_date'] ?? null)],
    ['role' => 'CMO',        'action' => 'Penutupan',      'name' => '',                                  'date' => $shortDate($crf['solved_at'] ?? null)],
];

$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$levelColors = [
    'Tinggi' => ['#fde8e6', '#a8362d'],
    'Normal' => ['#fff4dc', '#8a6410'],
    'Rendah' => ['#e1f3e6', '#2d7a4a'],
];
[$levelBg, $levelFg] = $levelColors[$levelDisplay] ?? ['#eef1f5', '#475467'];

$statusColors = [
    'Solve'  => ['#e1f3e6', '#2d7a4a'],
    'Cancel' => ['#fde8e6', '#a8362d'],
    'Draft'  => ['#eef1f5', '#475467'],
];
[$statusBg, $statusFg] = $statusColors[$crf['status'] ?? ''] ?? ['#e6eff9', '#3c6396'];

$processDateText = '';
if (($crf['status'] ?? '') === 'Solve' && !empty($crf['solved_at'])) {
    $processDateText = 'Diselesaikan pada ' . date('d-m-Y H:i', strtotime($crf['solved_at']));
} elseif (($crf['status'] ?? '') === 'Cancel' && !empty($crf['cancelled_at'])) {
    $processDateText = 'Dibatalkan pada ' . date('d-m-Y H:i', strtotime($crf['cancelled_at']));
}

/* ---------------------------------------------------------------
 * HTML PDF
 * --------------------------------------------------------------- */
ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 28px 32px 40px 32px; }

    body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #1f2937; margin: 0; padding: 0; line-height: 1.4; }
    table { border-collapse: collapse; width: 100%; }

    /* Kepala dokumen */
    .head { background: #dce8f6; color: #1f3a5f; border: 1px solid #c4d6ec; }
    .head td { padding: 12px 14px; vertical-align: middle; }
    .head .doc-title { font-size: 17px; font-weight: bold; letter-spacing: 1px; }
    .head .doc-sub { font-size: 9px; color: #5a7699; margin-top: 2px; }
    .head .reg-label { font-size: 8px; color: #5a7699; text-transform: uppercase; letter-spacing: 1px; text-align: right; }
    .head .reg-number { font-size: 13px; font-weight: bold; text-align: right; margin-top: 2px; }

    /* Ringkasan status */
    .chips { margin-top: 8px; }
    .chips td { width: 25%; padding: 6px 8px; border: 1px solid #d5dbe3; background: #f8fafc; vertical-align: top; }
    .chip-label { font-size: 8px; color: #667085; text-transform: uppercase; letter-spacing: 0.5px; }
    .chip-value { margin-top: 3px; font-size: 11px; font-weight: bold; }
    .pill { padding: 2px 8px; font-size: 10px; font-weight: bold; }

    /* Bagian */
    .section-bar { margin-top: 12px; background: #eef2f7; border-left: 4px solid #9db8dc; padding: 5px 9px; font-size: 11px; font-weight: bold; color: #35557f; }

    .grid { border: 1px solid #d5dbe3; border-top: 0; }
    .grid td { border-bottom: 1px solid #e3e8ef; padding: 6px 9px; vertical-align: top; }
    .grid .lbl { width: 27%; background: #f6f8fb; font-weight: bold; color: #344054; border-right: 1px solid #e3e8ef; }
    .grid .val { width: 73%; }
    .grid .lbl4 { width: 16%; background: #f6f8fb; font-weight: bold; color: #344054; }
    .grid .val4 { width: 34%; }
    .muted { color: #667085; }

    /* Proses pengajuan */
    .steps { border: 1px solid #d5dbe3; border-top: 0; table-layout: fixed; }
    .steps td { width: 14.2857%; vertical-align: top; text-align: center; border-right: 1px solid #e3e8ef; padding: 0; }
    .steps td.last { border-right: 0; }
    .step-head { padding: 5px 3px; font-size: 8px; font-weight: bold; }
    .step-done .step-head { background: #d5eedc; color: #1c6b3a; }
    .step-todo .step-head { background: #e8ecf2; color: #7b8798; }
    .step-body { padding: 7px 4px 8px 4px; }
    .step-action { font-size: 9px; font-weight: bold; color: #1f2937; }
    .step-name { font-size: 8px; color: #667085; margin-top: 2px; }
    .step-date { margin-top: 5px; font-size: 8.5px; font-weight: bold; color: #35557f; }
    .step-todo .step-date { color: #a3adbb; font-weight: normal; }

    .footer-note { margin-top: 14px; font-size: 8px; color: #667085; text-align: center; border-top: 1px solid #e3e8ef; padding-top: 6px; }

    .draft-note { margin-bottom: 8px; padding: 6px 10px; background: #fdeceb; border: 1px solid #f3c6c2; color: #a8362d; font-size: 9px; text-align: center; letter-spacing: 0.3px; }
</style>
</head>
<body>
<?= $watermarkHtml ?>

<table class="head">
    <tr>
        <td>
            <div class="doc-title">CHANGE REQUEST FORM</div>
            <div class="doc-sub">PT Persona Prima Utama</div>
        </td>
        <td style="width: 38%;">
            <div class="reg-label">Nomor Register</div>
            <div class="reg-number"><?= pdfValue($crf['request_number']) ?></div>
        </td>
    </tr>
</table>

<table class="chips">
    <tr>
        <td>
            <div class="chip-label">Level Urgensi</div>
            <div class="chip-value"><span class="pill" style="background: <?= $levelBg ?>; color: <?= $levelFg ?>;"><?= $esc($levelDisplay) ?></span></div>
        </td>
        <td>
            <div class="chip-label">SLA</div>
            <div class="chip-value"><?= $esc($slaDisplay) ?></div>
        </td>
        <td>
            <div class="chip-label">Status</div>
            <div class="chip-value"><span class="pill" style="background: <?= $statusBg ?>; color: <?= $statusFg ?>;"><?= $esc($statusDisplay) ?></span></div>
        </td>
        <td>
            <div class="chip-label">Tahap</div>
            <div class="chip-value"><?= $esc($workflowStageDisplay) ?></div>
        </td>
    </tr>
</table>
<?php if ($processDateText !== ''): ?>
    <div class="muted" style="margin-top: 4px; font-size: 9px;"><?= $esc($processDateText) ?></div>
<?php endif; ?>

<div class="section-bar">Informasi Pengajuan</div>
<table class="grid">
    <tr>
        <td class="lbl4">Hari/Tanggal</td>
        <td class="val4"><?= pdfValue($submissionDate) ?></td>
        <td class="lbl4">Nama Lengkap</td>
        <td class="val4"><?= pdfValue($crf['full_name']) ?></td>
    </tr>
    <tr>
        <td class="lbl4">Kepada</td>
        <td class="val4"><?= pdfValue($toValue) ?></td>
        <td class="lbl4">No. HP / WA</td>
        <td class="val4"><?= pdfValue($crf['phone']) ?></td>
    </tr>
    <tr>
        <td class="lbl4">Dari</td>
        <td class="val4"><?= pdfValue($fromValue) ?></td>
        <td class="lbl4">Email</td>
        <td class="val4"><?= pdfValue($crf['email']) ?></td>
    </tr>
</table>

<div class="section-bar">Change Request Description</div>
<table class="grid">
    <tr><td class="lbl">Tipe Pengajuan</td><td class="val"><?= pdfValue($crf['request_type'] ?? '') ?></td></tr>
    <tr><td class="lbl">Rincian Permohonan Perubahan</td><td class="val"><?= pdfValue($crf['change_description']) ?></td></tr>
    <tr><td class="lbl">Benefit dari Perubahan yang Diharapkan</td><td class="val"><?= pdfValue($crf['benefit']) ?></td></tr>
    <tr><td class="lbl">Kategori Dampak</td><td class="val"><?= pdfValue(crfImpactLabel($crf['impact_category'] ?? null)) ?></td></tr>
    <tr><td class="lbl">Dampak Jika Tidak Dilakukan Perubahan</td><td class="val"><?= pdfValue($crf['impact']) ?></td></tr>
    <tr><td class="lbl">Alasan Permohonan Perubahan</td><td class="val"><?= pdfValue($crf['reason']) ?></td></tr>
    <tr><td class="lbl">Bukti dan Informasi Pendukung</td><td class="val"><?= $attachmentHtml ?></td></tr>
    <tr><td class="lbl">Biaya / Anggaran</td><td class="val"><?= pdfValue($budgetDisplay) ?></td></tr>
    <tr><td class="lbl">Kategori Perubahan</td><td class="val"><?= pdfValue($categoryDisplay) ?></td></tr>
</table>

<div class="section-bar">Change Request Action</div>
<table class="grid">
    <tr><td class="lbl">Saran Alternatif</td><td class="val"><?= pdfValue($crf['alternative_suggestion']) ?></td></tr>
    <tr><td class="lbl">Tanggal Implementasi</td><td class="val"><?= $esc($implementationDateText) ?></td></tr>
    <tr><td class="lbl">Implementasi</td><td class="val"><?= pdfValue($crf['implementation']) ?></td></tr>
    <tr><td class="lbl">Tanggal PIR</td><td class="val"><?= $esc($pirDateText) ?></td></tr>
    <tr><td class="lbl">Post Implementation Review</td><td class="val"><?= pdfValue($crf['post_implementation_review']) ?></td></tr>
</table>

<div class="section-bar">Proses Pengajuan</div>
<table class="steps">
    <tr>
        <?php foreach ($processSteps as $index => $step): ?>
            <?php $done = $step['date'] !== null; ?>
            <td class="<?= $done ? 'step-done' : 'step-todo' ?><?= $index === count($processSteps) - 1 ? ' last' : '' ?>">
                <div class="step-head"><?= ($index + 1) . '. ' . $esc($step['role']) ?></div>
                <div class="step-body">
                    <div class="step-action"><?= $esc($step['action']) ?></div>
                    <?php if (trim((string) $step['name']) !== ''): ?>
                        <div class="step-name"><?= $esc($step['name']) ?></div>
                    <?php endif; ?>
                    <div class="step-date"><?= $done ? $esc($step['date']) : 'Belum' ?></div>
                </div>
            </td>
        <?php endforeach; ?>
    </tr>
</table>

<div class="footer-note">
    Dokumen ini dihasilkan oleh Sistem Helpdesk &amp; CRF PT Persona Prima Utama · dicetak <?= date('d-m-Y H:i') ?>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

/* ---------------------------------------------------------------
 * Generate PDF
 * --------------------------------------------------------------- */

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nomor halaman di kaki setiap halaman.
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('Arial', 'normal');
$canvas->page_text(
    $canvas->get_width() - 100,
    $canvas->get_height() - 26,
    'Halaman {PAGE_NUM} dari {PAGE_COUNT}',
    $font,
    8,
    [0.4, 0.45, 0.52]
);

$fileName = 'CRF-' . preg_replace(
    '/[^A-Za-z0-9._-]/',
    '-',
    $crf['request_number'] ?? ('DRAFT-' . $crf['id'])
) . '.pdf';

$dompdf->stream($fileName, [
    'Attachment' => false
]);
