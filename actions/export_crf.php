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
     WHERE change_request_id = :id
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
    : 'Belum ditentukan';

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

$slaDeterminedAt = $firstActivityDate(
    $activityRows,
    ['Otomasi - SLA Ditentukan']
);

$implementationPirAt = $firstActivityDate(
    $activityRows,
    ['Implementasi & PIR Diisi']
);

$slaDeterminedDate = $slaDeterminedAt
    ? formatTanggalIndonesia(new DateTime($slaDeterminedAt))
    : null;

$implementationPirDate = $implementationPirAt
    ? formatTanggalIndonesia(new DateTime($implementationPirAt))
    : null;

/* ---------------------------------------------------------------
 * HTML PDF
 * --------------------------------------------------------------- */
$html = '
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <style>

        @page {
            margin: 25px 28px 25px 28px;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #000;
            margin: 0;
            padding: 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .info-table td {
            border: 1px solid #000;
            padding: 7px;
            vertical-align: top;
        }

        .info-label {
            width: 24%;
            font-weight: bold;
        }

        .info-value {
            width: 76%;
        }

        .section-title {
            margin-top: 7px;
            margin-bottom: 0;
            font-weight: bold;
            font-size: 11px;
        }

        .form-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .form-table td {
            border: 1px solid #000;
            padding: 7px;
            vertical-align: top;
        }

        .form-label {
            width: 24%;
            font-weight: bold;
        }

        .form-content {
            width: 76%;
            min-height: 45px;
        }

        .large-content {
            min-height: 70px;
        }

        .attachment {
            line-height: 1.5;
        }

        .muted {
            color: #555;
        }

        .budget-row td {
            padding: 5px 7px;
        }

        .check {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
        }

        .status-box {
            margin-top: 10px;
            border: 1px solid #000;
            padding: 7px;
        }

        .process-date {
            margin-top: 4px;
            font-size: 9px;
        }

        .process-table {
            margin-top: 12px;
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .process-table td {
            border: 1px solid #000;
            padding: 8px;
            vertical-align: top;
            text-align: center;
            height: 95px;
            width: 14.2857%;
        }

        .process-table .process-table-label {
            margin-top: 12px;
            font-size: 9px;
        }

        .process-table .process-table-date {
            margin-top: 3px;
            font-weight: bold;
            font-size: 10px;
        }

        .footer-note {
            margin-top: 15px;
            font-size: 8px;
            color: #444;
        }

        .category-table td {
            border: 1px solid #000;
            padding: 6px;
            vertical-align: top;
        }

    </style>
</head>

<body>

    <!-- =========================================================
         HALAMAN 1
         ========================================================= -->

    <div class="title">
        CHANGE REQUEST FORM
    </div>

    <table class="info-table">

        <tr>
            <td class="info-label">Hari/Tanggal</td>
            <td class="info-value">
                ' . pdfValue($submissionDate) . '
            </td>
        </tr>

        <tr>
            <td class="info-label">Kepada</td>
            <td class="info-value">
                ' . pdfValue($toValue) . '
            </td>
        </tr>

        <tr>
            <td class="info-label">Dari</td>
            <td class="info-value">
                ' . pdfValue($fromValue) . '
            </td>
        </tr>

        <tr>
            <td class="info-label">Nomor Register</td>
            <td class="info-value">
                ' . pdfValue($crf['request_number']) . '
            </td>
        </tr>

        <tr>
            <td class="info-label">Nama Lengkap</td>
            <td class="info-value">
                ' . pdfValue($crf['full_name']) . '
            </td>
        </tr>

        <tr>
            <td class="info-label">No. Handphone / WA</td>
            <td class="info-value">
                ' . pdfValue($crf['phone']) . '
            </td>
        </tr>

        <tr>
            <td class="info-label">Email</td>
            <td class="info-value">
                ' . pdfValue($crf['email']) . '
            </td>
        </tr>

    </table>

    <div class="section-title">
        Change Request Description
    </div>

    <table class="form-table">

        <tr>
            <td class="form-label">
                Rincian Permohonan Perubahan
            </td>
            <td class="form-content large-content">
                ' . pdfValue($crf['change_description']) . '
            </td>
        </tr>

        <tr>
            <td class="form-label">
                Benefit dari Perubahan yang Diharapkan
            </td>
            <td class="form-content large-content">
                ' . pdfValue($crf['benefit']) . '
            </td>
        </tr>

        <tr>
            <td class="form-label">
                Dampak Jika Tidak Dilakukan Perubahan
            </td>
            <td class="form-content large-content">
                ' . pdfValue($crf['impact']) . '
            </td>
        </tr>

        <tr>
            <td class="form-label">
                Alasan Permohonan Perubahan
            </td>
            <td class="form-content large-content">
                ' . pdfValue($crf['reason']) . '
            </td>
        </tr>

        <tr>
            <td class="form-label">
                Bukti dan Informasi Pendukung
            </td>
            <td class="form-content attachment">
                ' . $attachmentHtml . '
            </td>
        </tr>
        
        <tr>
            <td class="form-label">
                Biaya / Anggaran
            </td>

            <td class="form-content">
                ' . pdfValue($budgetDisplay) . '
            </td>
        </tr>

        <tr>
            <td class="form-label">
                Kategori Perubahan
            </td>

            <td class="form-content">
                ' . pdfValue($categoryDisplay) . '
            </td>
        </tr>

    </table>

    <div class="section-title">
        Change Request Action
    </div>

    <table class="form-table">

        <tr>
            <td class="form-label">
                Saran Alternatif
            </td>

            <td class="form-content large-content">
                ' . pdfValue($crf['alternative_suggestion']) . '
            </td>
        </tr>

        <tr>
            <td class="form-label">
                Post Implementation Review
            </td>

            <td class="form-content large-content">
                ' . pdfValue($crf['post_implementation_review']) . '
            </td>
        </tr>

        <tr>
            <td class="form-label">
                Implementasi
            </td>

            <td class="form-content large-content">
                ' . pdfValue($crf['implementation']) . '
            </td>
        </tr>

    </table>

    <div class="status-box">
        <strong>Level Urgensi:</strong>
        ' . pdfValue($levelDisplay) . '
        &nbsp;&nbsp;&nbsp;
        <strong>SLA:</strong>
        ' . pdfValue($slaDisplay) . '
        &nbsp;&nbsp;&nbsp;
        <strong>Status:</strong>
        ' . pdfValue($statusDisplay) . '
        &nbsp;&nbsp;&nbsp;
        <strong>Tahap:</strong>
        ' . pdfValue($workflowStageDisplay) . '
        ' . $processDateHtml . '
    </div>

    <div class="section-title" style="margin-top: 12px;">
        PROSES PENGAJUAN
    </div>

    <table class="process-table">
        <tr>

            <!-- 1. PEMOHON -->
            <td>
                <div class="process-table-title">
                    Pemohon
                </div>

                <div class="process-table-person">
                    ' . pdfValue($crf['full_name']) . '
                </div>

                <div class="process-table-label">
                    Mengajukan CRF
                </div>

                <div class="process-table-date">
                    ' . $submissionDate . '
                </div>
            </td>


            <!-- 2. CMO FILTERING -->
            <td>
                <div class="process-table-title">
                    CMO
                </div>

                <div class="process-table-role">
                    Filtering
                </div>

                <div class="process-table-label">
                    Diteruskan ke Otomasi
                </div>

                <div class="process-table-date">
                    ' . (
                        !empty($crf['automation_started_at'])
                            ? formatTanggalIndonesia(
                                new DateTime(
                                    $crf['automation_started_at']
                                )
                            )
                            : '-'
                    ) . '
                </div>
            </td>


            <!-- 3. OTOMASI SLA -->
            <td>
                <div class="process-table-title">
                    Otomasi
                </div>

                <div class="process-table-role">
                    Menentukan SLA
                </div>

                <div class="process-table-label">
                    SLA Ditentukan
                </div>

                <div class="process-table-date">
                    ' . (
                        !empty($slaDeterminedDate)
                            ? $slaDeterminedDate
                            : '-'
                    ) . '
                </div>
            </td>


            <!-- 4. APPROVAL -->
            <td>
                <div class="process-table-title">
                    Kepala Dept. Operasional
                </div>

                <div class="process-table-role">
                    Approval
                </div>

                <div class="process-table-label">
                    Tanggal Approval
                </div>

                <div class="process-table-date">
                    ' . (
                        !empty($crf['pak_joko_approved_at'])
                            ? formatTanggalIndonesia(
                                new DateTime(
                                    $crf['pak_joko_approved_at']
                                )
                            )
                            : '-'
                    ) . '
                </div>
            </td>


            <!-- 5. OTOMASI EKSEKUSI -->
            <td>
                <div class="process-table-title">
                    Otomasi
                </div>

                <div class="process-table-role">
                    Eksekusi
                </div>

                <div class="process-table-label">
                    Eksekusi Selesai
                </div>

                <div class="process-table-date">
                    ' . (
                        !empty($crf['automation_completed_at'])
                            ? formatTanggalIndonesia(
                                new DateTime(
                                    $crf['automation_completed_at']
                                )
                            )
                            : '-'
                    ) . '
                </div>
            </td>


            <!-- 6. PEMOHON IMPLEMENTASI + PIR -->
            <td>
                <div class="process-table-title">
                    Pemohon
                </div>

                <div class="process-table-role">
                    Implementasi + PIR
                </div>

                <div class="process-table-label">
                    Dikirim ke CMO
                </div>

                <div class="process-table-date">
                    ' . (
                        !empty($implementationPirDate)
                            ? $implementationPirDate
                            : '-'
                    ) . '
                </div>
            </td>


            <!-- 7. CMO PENUTUPAN -->
            <td>
                <div class="process-table-title">
                    CMO
                </div>

                <div class="process-table-role">
                    Penutupan CRF
                </div>

                <div class="process-table-label">
                    Tanggal Selesai
                </div>

                <div class="process-table-date">
                    ' . (
                        !empty($crf['solved_at'])
                            ? formatTanggalIndonesia(
                                new DateTime(
                                    $crf['solved_at']
                                )
                            )
                            : '-'
                    ) . '
                </div>
            </td>

        </tr>
    </table>

    <div class="footer-note">
        Dokumen ini dihasilkan oleh sistem CRF Prototype PT Persona Prima Utama.
    </div>

</body>
</html>
';

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

$fileName = 'CRF-' . preg_replace(
    '/[^A-Za-z0-9._-]/',
    '-',
    $crf['request_number'] ?? ('DRAFT-' . $crf['id'])
) . '.pdf';

$dompdf->stream($fileName, [
    'Attachment' => true
]);

exit;