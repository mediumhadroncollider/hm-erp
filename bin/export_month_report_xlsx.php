#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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

function indexVirtualoByIsbn(array $records): array
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
        $sheet->duplicateStyle($sheet->getStyle("A{$templateStyleRow}:O{$templateStyleRow}"), "A{$r}:O{$r}");
        $srcHeight = $sheet->getRowDimension($templateStyleRow)->getRowHeight();
        if ($srcHeight !== null && $srcHeight > 0) {
            $sheet->getRowDimension($r)->setRowHeight($srcHeight);
        }
    }
}

function clearDataArea(Worksheet $sheet, int $fromRow, int $toRow): void
{
    for ($r = $fromRow; $r <= $toRow; $r++) {
        foreach (range('A', 'O') as $col) {
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

    $monthDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'woo';
    ensureDir($monthDir);

    $reportJsonPath = $monthDir . DIRECTORY_SEPARATOR . 'report_rows.zero_filled.json';
    $virtualoJsonPath = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'virtualo' . DIRECTORY_SEPARATOR . 'sales_by_isbn.json';
    if (!is_file($reportJsonPath) || !is_file($virtualoJsonPath)) {
        throw new RuntimeException('Brak danych wejściowych Woo i/lub Virtualo.');
    }

    $reportPayload = readJsonFile($reportJsonPath);
    $virtualoPayload = readJsonFile($virtualoJsonPath);
    $reportRows = is_array($reportPayload['records'] ?? null) ? $reportPayload['records'] : [];
    $virtualoRows = is_array($virtualoPayload['records'] ?? null) ? $virtualoPayload['records'] : [];

    $rowsByIsbn = indexReportRowsByIsbn($reportRows);
    $virtualoByIsbn = indexVirtualoByIsbn($virtualoRows);

    $spreadsheet = IOFactory::load($templatePath);
    while ($spreadsheet->getSheetCount() > 1) {
        $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
    }

    $sheet = $spreadsheet->getSheet(0);
    $sheet->setTitle('autozliczanie (' . $month . ')');

    $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    if ($highestColIndex > 15) {
        $sheet->removeColumn('P', $highestColIndex - 15);
    }

    $templateOrder = readTemplateIsbnOrder($sheet);
    $finalRows = buildFinalRows($templateOrder, $rowsByIsbn, $month);

    // +1 pusty wiersz +1 wiersz sumy, żeby style były stabilne przy różnych długościach listy
    $targetLastRow = max(4, 4 + count($finalRows) + 2);
    cloneDataRowStyleIfNeeded($sheet, $targetLastRow);
    clearDataArea($sheet, 4, max($sheet->getHighestRow(), $targetLastRow));

    $sheet->setCellValue('B1', 'Tytuł');
    $sheet->setCellValue('E1', 'RAZEM');
    $sheet->setCellValue('H1', 'Histmag (szacunkowy)');
    $sheet->setCellValue('K1', 'sieci');
    $sheet->setCellValue('N1', 'Virtualo');
    $sheet->setCellValue('H2', 'kwoty są szacunkowe');

    $r = 4;
    $totalUnits = 0;
    $totalNet = 0.0;
    $sumHistUnits = 0;
    $sumHistNet = 0.0;
    $sumVirtUnits = 0;
    $sumVirtNet = 0.0;

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

        // Semantyka (wariant A):
        // - N:O = szczegół Virtualo
        // - K:L = suma sieci zewnętrznych (na ten moment tylko Virtualo)
        $externalNetworkUnits = $virtUnits;
        $externalNetworkNet = $virtNet;

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

        $sumHistUnits += $histUnits;
        $sumHistNet += $histNet;
        $sumVirtUnits += $virtUnits;
        $sumVirtNet += $virtNet;
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
    $sheet->setCellValue("K{$summaryRow}", $sumVirtUnits);
    $sheet->setCellValue("L{$summaryRow}", $sumVirtNet);
    $sheet->setCellValue("N{$summaryRow}", $sumVirtUnits);
    $sheet->setCellValue("O{$summaryRow}", $sumVirtNet);

    $xlsxPath = $monthDir . DIRECTORY_SEPARATOR . 'raport_sprzedazy_' . $month . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);

    writeJsonFile($monthDir . DIRECTORY_SEPARATOR . 'xlsx_manifest.json', [
        'snapshot_type' => 'month_xlsx_manifest',
        'generated_at' => gmdate('c'),
        'month' => $month,
        'template_xlsx' => $templatePath,
        'input_report_json' => $reportJsonPath,
        'input_virtualo_json' => $virtualoJsonPath,
        'output_xlsx' => $xlsxPath,
        'columns_kept' => 'A:O',
        'formulas_in_output' => false,
        'stats' => [
            'rows_written' => count($finalRows),
            'summary_row' => $summaryRow,
            'total_units' => $totalUnits,
            'total_net' => round($totalNet, 2),
        ],
    ]);

    fwrite(STDOUT, "✅ Gotowe\nXLSX: {$xlsxPath}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}
