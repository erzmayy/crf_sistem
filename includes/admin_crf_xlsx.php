<?php

function buildAdminCrfXlsx(array $sheets): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Ekspor XLSX memerlukan ekstensi PHP ZIP yang aktif.');
    }

    $xmlEscape = static function ($value): string {
        $value = (string) ($value ?? '');
        $value = preg_replace(
            '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $value
        );
        if ($value === null) {
            throw new RuntimeException('Data laporan tidak dapat dikodekan ke XML.');
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $columnName = static function (int $column): string {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)) . $name;
            $column = intdiv($column, 26);
        }

        return $name;
    };
    $cellXml = static function ($value, string $reference, int $style = 0) use ($xmlEscape): string {
        if (is_array($value) && ($value['type'] ?? '') === 'Number') {
            return '<c r="' . $reference . '" s="' . $style . '"><v>'
                . (int) $value['value'] . '</v></c>';
        }

        $cellValue = is_array($value) ? ($value['value'] ?? '') : $value;
        if (is_array($value) && isset($value['style'])) {
            $style = (int) $value['style'];
        }

        return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . $xmlEscape($cellValue) . '</t></is></c>';
    };
    $worksheetXml = static function (
        array $sheet,
        int $columnCount
    ) use ($cellXml, $columnName): string {
        $rows = $sheet['rows'];
        $headerRow = (int) $sheet['header_row'];
        $lastRow = count($rows) + 4;
        $lastColumn = $columnName($columnCount);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow
            . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="20"/>'
            . '<cols>';
        foreach ($sheet['widths'] as $index => $width) {
            $column = $index + 1;
            $xml .= '<col min="' . $column . '" max="' . $column
                . '" width="' . (float) $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        $titleLastColumn = $columnCount > 0 ? $lastColumn : 'A';
        $xml .= '<row r="1" ht="32" customHeight="1">'
            . $cellXml($sheet['title'], 'A1', 1) . '</row>';
        $xml .= '<row r="2" ht="23" customHeight="1">'
            . $cellXml($sheet['subtitle'], 'A2', 2) . '</row>';
        $xml .= '<row r="3" ht="24" customHeight="1">'
            . $cellXml($sheet['period'], 'A3', 3) . '</row>';
        $xml .= '<row r="4"></row>';

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 5;
            $isHeader = $excelRow === $headerRow;
            $isSection = in_array($excelRow, $sheet['section_rows'], true);
            $rowStyle = $isHeader ? 4 : ($isSection ? 5 : (($excelRow % 2) === 0 ? 7 : 6));
            $rowHeight = $sheet['row_heights'][$excelRow] ?? ($isHeader ? 30 : 24);
            $xml .= '<row r="' . $excelRow . '" ht="' . (float) $rowHeight . '" customHeight="1">';
            foreach ($row as $columnIndex => $value) {
                $reference = $columnName($columnIndex + 1) . $excelRow;
                $xml .= $cellXml($value, $reference, $rowStyle);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($lastRow >= $headerRow) {
            $xml .= '<autoFilter ref="A' . $headerRow . ':' . $lastColumn . $lastRow . '"/>';
        }
        $xml .= '<mergeCells count="3"><mergeCell ref="A1:' . $titleLastColumn
            . '1"/><mergeCell ref="A2:' . $titleLastColumn
            . '2"/><mergeCell ref="A3:' . $titleLastColumn . '3"/></mergeCells>'
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';

        return $xml;
    };

    if (!$sheets) {
        throw new InvalidArgumentException('Workbook harus memiliki minimal satu lembar.');
    }

    $parts = [];
    $sheetRelationships = '';
    $contentTypes = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $sheetIndex = 1;
    foreach ($sheets as $sheetName => $sheet) {
        if (
            !is_string($sheetName)
            || $sheetName === ''
            || strlen($sheetName) > 31
            || !isset($sheet['rows'], $sheet['widths'], $sheet['header_row'])
        ) {
            throw new InvalidArgumentException('Konfigurasi lembar Excel tidak valid.');
        }

        $columnCount = count($sheet['widths']);
        $parts['xl/worksheets/sheet' . $sheetIndex . '.xml'] = $worksheetXml($sheet, $columnCount);
        $sheetRelationships .= '<Relationship Id="rId' . $sheetIndex
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet' . $sheetIndex . '.xml"/>';
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $sheetIndex
            . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $sheetIndex++;
    }

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView/></bookViews><sheets>';
    $relationshipId = 1;
    foreach (array_keys($sheets) as $sheetName) {
        $workbookXml .= '<sheet name="' . $xmlEscape($sheetName) . '" sheetId="'
            . $relationshipId . '" r:id="rId' . $relationshipId . '"/>';
        $relationshipId++;
    }
    $workbookXml .= '</sheets></workbook>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        . '<font><sz val="10"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="11"/><color rgb="FF526579"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FF1F568F"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts><fills count="6">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1C8FDB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDF7FD"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCEEFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills><borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD9E2EC"/></left><right style="thin"><color rgb="FFD9E2EC"/></right><top style="thin"><color rgb="FFD9E2EC"/></top><bottom style="thin"><color rgb="FFD9E2EC"/></bottom><diagonal/></border>'
        . '</borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" horizontal="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs></styleSheet>';
    $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . $contentTypes . '</Types>';
    $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $parts['xl/workbook.xml'] = $workbookXml;
    $parts['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRelationships
        . '<Relationship Id="rId' . $sheetIndex
        . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $parts['xl/styles.xml'] = $stylesXml;

    $temporaryFile = tempnam(sys_get_temp_dir(), 'crf-report-');
    if ($temporaryFile === false) {
        error_log('CRF report export error: unable to create temporary file.');
        throw new RuntimeException('File Excel tidak dapat dibuat. Silakan hubungi administrator.');
    }

    $archive = new ZipArchive();
    $openResult = $archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        unlink($temporaryFile);
        error_log('CRF report export error: unable to open XLSX archive (code ' . $openResult . ').');
        throw new RuntimeException('File Excel tidak dapat dibuat. Silakan hubungi administrator.');
    }

    foreach ($parts as $fileName => $contents) {
        if (!$archive->addFromString($fileName, $contents)) {
            $archive->close();
            unlink($temporaryFile);
            error_log('CRF report export error: unable to add XLSX part ' . $fileName . '.');
            throw new RuntimeException('File Excel tidak dapat dibuat. Silakan hubungi administrator.');
        }
    }

    if (!$archive->close()) {
        unlink($temporaryFile);
        error_log('CRF report export error: unable to finalize XLSX archive.');
        throw new RuntimeException('File Excel tidak dapat dibuat. Silakan hubungi administrator.');
    }

    $xlsxContents = file_get_contents($temporaryFile);
    unlink($temporaryFile);
    if ($xlsxContents === false) {
        error_log('CRF report export error: unable to read completed XLSX archive.');
        throw new RuntimeException('File Excel tidak dapat dibuat. Silakan hubungi administrator.');
    }

    return $xlsxContents;
}
