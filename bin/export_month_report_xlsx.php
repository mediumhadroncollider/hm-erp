#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const REPORT_LAST_COLUMN = 'X';

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
    ensureDir($monthDir);

    $reportJsonPath = $monthDir . DIRECTORY_SEPARATOR . 'report_rows.zero_filled.json';
    $virtualoJsonPath = $virtualoDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $empikJsonPath = $empikDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $publioJsonPath = $publioDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    $legimiJsonPath = $legimiDir . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    if (!is_file($reportJsonPath) || !is_file($virtualoJsonPath) || !is_file($empikJsonPath) || !is_file($publioJsonPath) || !is_file($legimiJsonPath)) {
        throw new RuntimeException('Brak danych wejściowych Woo i/lub Virtualo i/lub Empik i/lub Publio i/lub Legimi.');
    }

    $reportPayload = readJsonFile($reportJsonPath);
    $virtualoPayload = readJsonFile($virtualoJsonPath);
    $empikPayload = readJsonFile($empikJsonPath);
    $publioPayload = readJsonFile($publioJsonPath);
    $legimiPayload = readJsonFile($legimiJsonPath);
    $reportRows = is_array($reportPayload['records'] ?? null) ? $reportPayload['records'] : [];
    $virtualoRows = is_array($virtualoPayload['records'] ?? null) ? $virtualoPayload['records'] : [];
    $empikRows = is_array($empikPayload['records'] ?? null) ? $empikPayload['records'] : [];
    $publioRows = is_array($publioPayload['records'] ?? null) ? $publioPayload['records'] : [];
    $legimiRows = is_array($legimiPayload['records'] ?? null) ? $legimiPayload['records'] : [];

    $rowsByIsbn = indexReportRowsByIsbn($reportRows);
    $virtualoByIsbn = indexExternalSourceByIsbn($virtualoRows);
    $empikByIsbn = indexExternalSourceByIsbn($empikRows);
    $publioByIsbn = indexExternalSourceByIsbn($publioRows);
    $legimiByIsbn = indexExternalSourceByIsbn($legimiRows);

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
    $sheet->setCellValue('T1', 'Publio');
    $sheet->setCellValue('W1', 'Legimi');
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

        $externalNetworkUnits = $virtUnits + $empikUnits + $publioUnits + $legimiUnits;
        $externalNetworkNet = $virtNet + $empikNet + $publioNet + $legimiNet;

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

        $sheet->setCellValue("T{$r}", $publioUnits);
        $sheet->setCellValue("U{$r}", $publioNet);

        $sheet->setCellValue("W{$r}", $legimiUnits);
        $sheet->setCellValue("X{$r}", $legimiNet);

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
    $sheet->setCellValue("T{$summaryRow}", $sumPublioUnits);
    $sheet->setCellValue("U{$summaryRow}", $sumPublioNet);
    $sheet->setCellValue("W{$summaryRow}", $sumLegimiUnits);
    $sheet->setCellValue("X{$summaryRow}", $sumLegimiNet);
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
