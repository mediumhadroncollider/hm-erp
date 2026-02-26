#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const REPORT_LAST_COLUMN = 'AV';

function normalizeIsbnForKey($value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_float($value) || is_int($value)) {
        $value = sprintf('%.0f', (float)$value);
    }

    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }

    $digits = preg_replace('/\D/', '', $s);
    if (!is_string($digits) || $digits === '') {
        return null;
    }

    return $digits;
}

function isPremiereInMonth(?string $premiereDate, string $month): bool
{
    return $premiereDate !== null && $premiereDate !== '' && str_starts_with($premiereDate, $month . '-');
}

function indexReportRowsByIsbn(array $reportRows): array
{
    $indexed = [];
    foreach ($reportRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $isbnNorm = normalizeIsbnForKey($row['isbn_norm'] ?? null);
        if ($isbnNorm !== null) {
            $indexed[$isbnNorm] = $row;
        }
    }
    return $indexed;
}

function indexExternalSourceByIsbn(array $records): array
{
    $out = [];
    foreach ($records as $row) {
        if (!is_array($row)) {
            continue;
        }
        $isbnNorm = normalizeIsbnForKey($row['isbn_norm'] ?? null);
        if ($isbnNorm === null) {
            continue;
        }
        $out[$isbnNorm] = [
            'units_sold' => (int)($row['units_sold'] ?? 0),
            'margin_net_cents' => (int)($row['margin_net_cents'] ?? 0),
        ];
    }
    return $out;
}

function readTemplateIsbnOrder(Worksheet $sheet): array
{
    $result = [];
    $seen = [];
    for ($r = 4; $r <= $sheet->getHighestRow(); $r++) {
        $isbn = normalizeIsbnForKey($sheet->getCell("A{$r}")->getValue());
        if ($isbn === null || isset($seen[$isbn])) {
            continue;
        }
        $seen[$isbn] = true;
        $result[] = $isbn;
    }
    return $result;
}

function buildFinalRows(array $templateOrder, array $rowsByIsbn, string $month): array
{
    $final = [];
    $used = [];

    if ($templateOrder !== []) {
        foreach ($templateOrder as $isbn) {
            if (!isset($rowsByIsbn[$isbn])) {
                continue;
            }
            $final[] = $rowsByIsbn[$isbn];
            $used[$isbn] = true;
        }

        foreach ($rowsByIsbn as $isbn => $row) {
            if (isset($used[$isbn])) {
                continue;
            }
            if (isPremiereInMonth((string)($row['premiere_date'] ?? ''), $month)) {
                $final[] = $row;
            }
        }

        return $final;
    }

    $rows = array_values($rowsByIsbn);
    usort($rows, fn(array $a, array $b): int => (string)($a['title'] ?? '') <=> (string)($b['title'] ?? ''));
    return $rows;
}

function adjustedNetCents97(array $row): int
{
    $netCents = isset($row['revenue_net_cents']) ? (int)$row['revenue_net_cents'] : decimalStringToCents((string)($row['revenue_net'] ?? '0'));
    return (int) round($netCents * 0.97, 0);
}

function cloneDataRowStyleIfNeeded(Worksheet $sheet, int $targetLastRow): void
{
    $templateStyleRow = 4;
    $highestRow = $sheet->getHighestRow();
    if ($targetLastRow <= $highestRow) {
        return;
    }

    for ($r = $highestRow + 1; $r <= $targetLastRow; $r++) {
        $sheet->duplicateStyle($sheet->getStyle("A{$templateStyleRow}:" . REPORT_LAST_COLUMN . "{$templateStyleRow}"), "A{$r}:" . REPORT_LAST_COLUMN . "{$r}");
        $srcHeight = $sheet->getRowDimension($templateStyleRow)->getRowHeight();
        if ($srcHeight !== null && $srcHeight > 0) {
            $sheet->getRowDimension($r)->setRowHeight($srcHeight);
        }
    }
}

function clearDataArea(Worksheet $sheet, int $fromRow, int $toRow): void
{
    $lastColIndex = Coordinate::columnIndexFromString(REPORT_LAST_COLUMN);
    for ($r = $fromRow; $r <= $toRow; $r++) {
        for ($colIndex = 1; $colIndex <= $lastColIndex; $colIndex++) {
            $col = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue("{$col}{$r}", null);
        }
    }
}

try {
    $config = loadConfig();
    $paths = $config['paths'] ?? [];
    if (!is_array($paths)) {
        throw new RuntimeException("Błąd config.local.php: sekcja 'paths' musi być tablicą.");
    }

    $month = parseRequiredMonthArg($argv);
    $periodsDir = (string)($paths['periods_dir'] ?? '');
    $templatePath = (string)($paths['monthly_report_template_xlsx'] ?? '');

    if ($periodsDir === '') {
        throw new RuntimeException('Brak paths.periods_dir w config.local.php');
    }
    if ($templatePath === '' || !is_file($templatePath)) {
        throw new RuntimeException("Brak szablonu XLSX: {$templatePath}");
    }

    $monthRootDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month;
    $monthDir = $monthRootDir . DIRECTORY_SEPARATOR . 'woo';
    $virtualoDir = $monthRootDir . DIRECTORY_SEPARATOR . 'virtualo';
    $empikDir = $monthRootDir . DIRECTORY_SEPARATOR . 'empik';
    $publioDir = $monthRootDir . DIRECTORY_SEPARATOR . 'publio';
    $legimiDir = $monthRootDir . DIRECTORY_SEPARATOR . 'legimi';
    $nextoDir = $monthRootDir . DIRECTORY_SEPARATOR . 'nexto';
    $woblinkDir = $monthRootDir . DIRECTORY_SEPARATOR . 'woblink';
    $azymutDir = $monthRootDir . DIRECTORY_SEPARATOR . 'azymut';
    $ebookpointDir = $monthRootDir . DIRECTORY_SEPARATOR . 'ebookpoint';
    $nasbiDir = $monthRootDir . DIRECTORY_SEPARATOR . 'nasbi';
    ensureDir($monthDir);

    $reportJsonPath = $monthDir . DIRECTORY_SEPARATOR . 'report_rows.zero_filled.json';
    $virtualoJsonPath = $virtualoDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $empikJsonPath = $empikDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $publioJsonPath = $publioDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $legimiJsonPath = $legimiDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $nextoJsonPath = $nextoDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $woblinkJsonPath = $woblinkDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $azymutJsonPath = $azymutDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $ebookpointJsonPath = $ebookpointDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $nasbiJsonPath = $nasbiDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';

    if (!is_file($reportJsonPath) || !is_file($virtualoJsonPath) || !is_file($empikJsonPath) || !is_file($publioJsonPath) || !is_file($legimiJsonPath) || !is_file($nextoJsonPath) || !is_file($woblinkJsonPath) || !is_file($ebookpointJsonPath) || !is_file($nasbiJsonPath)) {
        throw new RuntimeException('Brak danych wejściowych Woo i/lub źródeł obsługiwanych (Virtualo/Empik/Publio/Legimi/Nexto/Woblink/Ebookpoint/Nasbi).');
    }

    $reportPayload = readJsonFile($reportJsonPath);
    $virtualoPayload = readJsonFile($virtualoJsonPath);
    $empikPayload = readJsonFile($empikJsonPath);
    $publioPayload = readJsonFile($publioJsonPath);
    $legimiPayload = readJsonFile($legimiJsonPath);
    $nextoPayload = readJsonFile($nextoJsonPath);
    $woblinkPayload = readJsonFile($woblinkJsonPath);
    $azymutPayload = is_file($azymutJsonPath) ? readJsonFile($azymutJsonPath) : [];
    $ebookpointPayload = readJsonFile($ebookpointJsonPath);
    $nasbiPayload = readJsonFile($nasbiJsonPath);
    $reportRows = is_array($reportPayload['records'] ?? null) ? $reportPayload['records'] : [];
    $virtualoRows = is_array($virtualoPayload['records'] ?? null) ? $virtualoPayload['records'] : [];
    $empikRows = is_array($empikPayload['records'] ?? null) ? $empikPayload['records'] : [];
    $publioRows = is_array($publioPayload['records'] ?? null) ? $publioPayload['records'] : [];
    $legimiRows = is_array($legimiPayload['records'] ?? null) ? $legimiPayload['records'] : [];
    $nextoRows = is_array($nextoPayload['records'] ?? null) ? $nextoPayload['records'] : [];
    $woblinkRows = is_array($woblinkPayload['records'] ?? null) ? $woblinkPayload['records'] : [];
    $azymutRows = is_array($azymutPayload['records'] ?? null) ? $azymutPayload['records'] : [];
    $ebookpointRows = is_array($ebookpointPayload['records'] ?? null) ? $ebookpointPayload['records'] : [];
    $nasbiRows = is_array($nasbiPayload['records'] ?? null) ? $nasbiPayload['records'] : [];

    $rowsByIsbn = indexReportRowsByIsbn($reportRows);
    $virtualoByIsbn = indexExternalSourceByIsbn($virtualoRows);
    $empikByIsbn = indexExternalSourceByIsbn($empikRows);
    $publioByIsbn = indexExternalSourceByIsbn($publioRows);
    $legimiByIsbn = indexExternalSourceByIsbn($legimiRows);
    $nextoByIsbn = indexExternalSourceByIsbn($nextoRows);
    $woblinkByIsbn = indexExternalSourceByIsbn($woblinkRows);
    $azymutByIsbn = indexExternalSourceByIsbn($azymutRows);
    $ebookpointByIsbn = indexExternalSourceByIsbn($ebookpointRows);
    $nasbiByIsbn = indexExternalSourceByIsbn($nasbiRows);

    $spreadsheet = IOFactory::load($templatePath);
    while ($spreadsheet->getSheetCount() > 1) {
        $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
    }

    $sheet = $spreadsheet->getSheet(0);
    $sheet->setTitle('autozliczanie (' . $month . ')');

    $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $lastColIndex = Coordinate::columnIndexFromString(REPORT_LAST_COLUMN);
    if ($highestColIndex > $lastColIndex) {
        $firstColumnToRemove = Coordinate::stringFromColumnIndex($lastColIndex + 1);
        $sheet->removeColumn($firstColumnToRemove, $highestColIndex - $lastColIndex);
    }

    $templateOrder = readTemplateIsbnOrder($sheet);
    $finalRows = buildFinalRows($templateOrder, $rowsByIsbn, $month);

    $targetLastRow = max(4, 4 + count($finalRows) + 2);
    cloneDataRowStyleIfNeeded($sheet, $targetLastRow);
    clearDataArea($sheet, 4, max($sheet->getHighestRow(), $targetLastRow));

    $sheet->setCellValue('B1', 'Tytuł');
    $sheet->setCellValue('E1', 'RAZEM');
    $sheet->setCellValue('H1', 'Histmag (szacunkowy)');
    $sheet->setCellValue('K1', 'sieci zewnętrzne (suma)');
    $sheet->setCellValue('N1', 'Virtualo');
    $sheet->setCellValue('Q1', 'Empik');
    $sheet->setCellValue('T1', 'Legimi');
    $sheet->setCellValue('W1', 'Publio');
    $sheet->setCellValue('Z1', 'Nexto');
    $sheet->setCellValue('AC1', 'Woblink');
    $sheet->setCellValue('AF1', 'Azymut');
    $sheet->setCellValue('AI1', 'Ebookpoint');
    $sheet->setCellValue('AL1', 'Nasbi');
    $sheet->setCellValue('AO1', 'Storytel');
    $sheet->setCellValue('AR1', 'Audioteka');
    $sheet->setCellValue('AU1', 'Bookbeat');
    $sheet->setCellValue('H2', 'kwoty są szacunkowe');

    $r = 4;
    $totalUnits = 0;
    $totalNet = 0.0;
    $sumHistUnits = 0;
    $sumHistNet = 0.0;
    $sumVirtUnits = 0;
    $sumVirtNet = 0.0;
    $sumEmpikUnits = 0;
    $sumEmpikNet = 0.0;
    $sumNetworkUnits = 0;
    $sumNetworkNet = 0.0;
    $sumPublioUnits = 0;
    $sumPublioNet = 0.0;
    $sumLegimiUnits = 0;
    $sumLegimiNet = 0.0;
    $sumNextoUnits = 0;
    $sumNextoNet = 0.0;
    $sumWoblinkUnits = 0;
    $sumWoblinkNet = 0.0;
    $sumAzymutUnits = 0;
    $sumAzymutNet = 0.0;
    $sumEbookpointUnits = 0;
    $sumEbookpointNet = 0.0;
    $sumNasbiUnits = 0;
    $sumNasbiNet = 0.0;
    $sumStorytelUnits = 0;
    $sumStorytelNet = 0.0;
    $sumAudiotekaUnits = 0;
    $sumAudiotekaNet = 0.0;
    $sumBookbeatUnits = 0;
    $sumBookbeatNet = 0.0;

    foreach ($finalRows as $row) {
        $isbnNorm = normalizeIsbnForKey($row['isbn_norm'] ?? null);
        if ($isbnNorm === null) {
            continue;
        }

        $title = (string)($row['title'] ?? '');
        $histUnits = (int)($row['units_sold'] ?? 0);
        $histNet = adjustedNetCents97($row) / 100;

        $virtualo = $virtualoByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $virtUnits = (int)$virtualo['units_sold'];
        $virtNet = ((int)$virtualo['margin_net_cents']) / 100;

        $empik = $empikByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $empikUnits = (int)$empik['units_sold'];
        $empikNet = ((int)$empik['margin_net_cents']) / 100;

        $publio = $publioByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $publioUnits = (int)$publio['units_sold'];
        $publioNet = ((int)$publio['margin_net_cents']) / 100;

        $legimi = $legimiByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $legimiUnits = (int)$legimi['units_sold'];
        $legimiNet = ((int)$legimi['margin_net_cents']) / 100;

        $nexto = $nextoByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $nextoUnits = (int)$nexto['units_sold'];
        $nextoNet = ((int)$nexto['margin_net_cents']) / 100;

        $woblink = $woblinkByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $woblinkUnits = (int)$woblink['units_sold'];
        $woblinkNet = ((int)$woblink['margin_net_cents']) / 100;

        $azymut = $azymutByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $azymutUnits = (int)$azymut['units_sold'];
        $azymutNet = ((int)$azymut['margin_net_cents']) / 100;

        $ebookpoint = $ebookpointByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $ebookpointUnits = (int)$ebookpoint['units_sold'];
        $ebookpointNet = ((int)$ebookpoint['margin_net_cents']) / 100;

        $nasbi = $nasbiByIsbn[$isbnNorm] ?? ['units_sold' => 0, 'margin_net_cents' => 0];
        $nasbiUnits = (int)$nasbi['units_sold'];
        $nasbiNet = ((int)$nasbi['margin_net_cents']) / 100;

        $storytelUnits = 0;
        $storytelNet = 0.0;
        $audiotekaUnits = 0;
        $audiotekaNet = 0.0;
        $bookbeatUnits = 0;
        $bookbeatNet = 0.0;

        $externalNetworkUnits = $virtUnits + $empikUnits + $publioUnits + $legimiUnits + $nextoUnits + $woblinkUnits + $azymutUnits + $ebookpointUnits + $nasbiUnits + $storytelUnits + $audiotekaUnits + $bookbeatUnits;
        $externalNetworkNet = $virtNet + $empikNet + $publioNet + $legimiNet + $nextoNet + $woblinkNet + $azymutNet + $ebookpointNet + $nasbiNet + $storytelNet + $audiotekaNet + $bookbeatNet;

        $sheet->setCellValueExplicit("A{$r}", $isbnNorm, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("B{$r}", $title, DataType::TYPE_STRING);

        $sheet->setCellValue("E{$r}", $histUnits + $externalNetworkUnits);
        $sheet->setCellValue("F{$r}", $histNet + $externalNetworkNet);

        $sheet->setCellValue("H{$r}", $histUnits);
        $sheet->setCellValue("I{$r}", $histNet);

        $sheet->setCellValue("K{$r}", $externalNetworkUnits);
        $sheet->setCellValue("L{$r}", $externalNetworkNet);

        $sheet->setCellValue("N{$r}", $virtUnits);
        $sheet->setCellValue("O{$r}", $virtNet);

        $sheet->setCellValue("Q{$r}", $empikUnits);
        $sheet->setCellValue("R{$r}", $empikNet);

        $sheet->setCellValue("T{$r}", $legimiUnits);
        $sheet->setCellValue("U{$r}", $legimiNet);

        $sheet->setCellValue("W{$r}", $publioUnits);
        $sheet->setCellValue("X{$r}", $publioNet);

        $sheet->setCellValue("Z{$r}", $nextoUnits);
        $sheet->setCellValue("AA{$r}", $nextoNet);

        $sheet->setCellValue("AC{$r}", $woblinkUnits);
        $sheet->setCellValue("AD{$r}", $woblinkNet);

        $sheet->setCellValue("AF{$r}", $azymutUnits);
        $sheet->setCellValue("AG{$r}", $azymutNet);

        $sheet->setCellValue("AI{$r}", $ebookpointUnits);
        $sheet->setCellValue("AJ{$r}", $ebookpointNet);

        $sheet->setCellValue("AL{$r}", $nasbiUnits);
        $sheet->setCellValue("AM{$r}", $nasbiNet);

        $sheet->setCellValue("AO{$r}", $storytelUnits);
        $sheet->setCellValue("AP{$r}", $storytelNet);

        $sheet->setCellValue("AR{$r}", $audiotekaUnits);
        $sheet->setCellValue("AS{$r}", $audiotekaNet);

        $sheet->setCellValue("AU{$r}", $bookbeatUnits);
        $sheet->setCellValue("AV{$r}", $bookbeatNet);

        $sumHistUnits += $histUnits;
        $sumHistNet += $histNet;
        $sumVirtUnits += $virtUnits;
        $sumVirtNet += $virtNet;
        $sumEmpikUnits += $empikUnits;
        $sumEmpikNet += $empikNet;
        $sumNetworkUnits += $externalNetworkUnits;
        $sumNetworkNet += $externalNetworkNet;
        $sumPublioUnits += $publioUnits;
        $sumPublioNet += $publioNet;
        $sumLegimiUnits += $legimiUnits;
        $sumLegimiNet += $legimiNet;
        $sumNextoUnits += $nextoUnits;
        $sumNextoNet += $nextoNet;
        $sumWoblinkUnits += $woblinkUnits;
        $sumWoblinkNet += $woblinkNet;
        $sumAzymutUnits += $azymutUnits;
        $sumAzymutNet += $azymutNet;
        $sumEbookpointUnits += $ebookpointUnits;
        $sumEbookpointNet += $ebookpointNet;
        $sumNasbiUnits += $nasbiUnits;
        $sumNasbiNet += $nasbiNet;
        $sumStorytelUnits += $storytelUnits;
        $sumStorytelNet += $storytelNet;
        $sumAudiotekaUnits += $audiotekaUnits;
        $sumAudiotekaNet += $audiotekaNet;
        $sumBookbeatUnits += $bookbeatUnits;
        $sumBookbeatNet += $bookbeatNet;
        $totalUnits += ($histUnits + $externalNetworkUnits);
        $totalNet += ($histNet + $externalNetworkNet);

        $r++;
    }

    $summaryRow = $r + 1;
    $sheet->setCellValueExplicit("B{$summaryRow}", 'razem:', DataType::TYPE_STRING);
    $sheet->setCellValue("E{$summaryRow}", $totalUnits);
    $sheet->setCellValue("F{$summaryRow}", $totalNet);
    $sheet->setCellValue("H{$summaryRow}", $sumHistUnits);
    $sheet->setCellValue("I{$summaryRow}", $sumHistNet);
    $sheet->setCellValue("K{$summaryRow}", $sumNetworkUnits);
    $sheet->setCellValue("L{$summaryRow}", $sumNetworkNet);
    $sheet->setCellValue("N{$summaryRow}", $sumVirtUnits);
    $sheet->setCellValue("O{$summaryRow}", $sumVirtNet);
    $sheet->setCellValue("Q{$summaryRow}", $sumEmpikUnits);
    $sheet->setCellValue("R{$summaryRow}", $sumEmpikNet);
    $sheet->setCellValue("T{$summaryRow}", $sumLegimiUnits);
    $sheet->setCellValue("U{$summaryRow}", $sumLegimiNet);
    $sheet->setCellValue("W{$summaryRow}", $sumPublioUnits);
    $sheet->setCellValue("X{$summaryRow}", $sumPublioNet);
    $sheet->setCellValue("Z{$summaryRow}", $sumNextoUnits);
    $sheet->setCellValue("AA{$summaryRow}", $sumNextoNet);
    $sheet->setCellValue("AC{$summaryRow}", $sumWoblinkUnits);
    $sheet->setCellValue("AD{$summaryRow}", $sumWoblinkNet);
    $sheet->setCellValue("AF{$summaryRow}", $sumAzymutUnits);
    $sheet->setCellValue("AG{$summaryRow}", $sumAzymutNet);
    $sheet->setCellValue("AI{$summaryRow}", $sumEbookpointUnits);
    $sheet->setCellValue("AJ{$summaryRow}", $sumEbookpointNet);
    $sheet->setCellValue("AL{$summaryRow}", $sumNasbiUnits);
    $sheet->setCellValue("AM{$summaryRow}", $sumNasbiNet);
    $sheet->setCellValue("AO{$summaryRow}", $sumStorytelUnits);
    $sheet->setCellValue("AP{$summaryRow}", $sumStorytelNet);
    $sheet->setCellValue("AR{$summaryRow}", $sumAudiotekaUnits);
    $sheet->setCellValue("AS{$summaryRow}", $sumAudiotekaNet);
    $sheet->setCellValue("AU{$summaryRow}", $sumBookbeatUnits);
    $sheet->setCellValue("AV{$summaryRow}", $sumBookbeatNet);
    $xlsxPath = $monthDir . DIRECTORY_SEPARATOR . 'raport_sprzedazy_' . $month . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);

    writeJsonFile($monthDir . DIRECTORY_SEPARATOR . 'xlsx_manifest.json', [
        'snapshot_type' => 'month_xlsx_manifest',
        'generated_at' => gmdate('c'),
        'month' => $month,
        'template_xlsx' => $templatePath,
        'input_report_json' => $reportJsonPath,
        'input_virtualo_json' => $virtualoJsonPath,
        'input_empik_json' => $empikJsonPath,
        'input_publio_json' => $publioJsonPath,
        'input_legimi_json' => $legimiJsonPath,
        'input_nexto_json' => $nextoJsonPath,
        'input_woblink_json' => $woblinkJsonPath,
        'input_azymut_json' => $azymutJsonPath,
        'input_ebookpoint_json' => $ebookpointJsonPath,
        'input_nasbi_json' => $nasbiJsonPath,
        'output_xlsx' => $xlsxPath,
        'columns_kept' => 'A:' . REPORT_LAST_COLUMN,
        'formulas_in_output' => false,
        'stats' => [
            'rows_written' => count($finalRows),
            'summary_row' => $summaryRow,
            'total_units' => $totalUnits,
            'total_net' => round($totalNet, 2),
            'network_units' => $sumNetworkUnits,
            'network_net' => round($sumNetworkNet, 2),
        ],
    ]);

    fwrite(STDOUT, "✅ Gotowe\nXLSX: {$xlsxPath}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}
