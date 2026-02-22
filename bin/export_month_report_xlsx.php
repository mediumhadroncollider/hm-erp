#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * v0.0.1-xlsx
 * Buduje finalny XLSX (tylko A:I pierwszego arkusza, bez formuł, bez dodatkowych arkuszy)
 * na bazie:
 *  - report_rows.zero_filled.json (z obecnego build_month_rows_from_woo.php)
 *  - szablonu XLSX (załączony plik użytkownika)
 */

function normalizeIsbnForKey($value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_float($value) || is_int($value)) {
        // np. 9788365156365.0 z Excela
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
    if ($premiereDate === null || $premiereDate === '') {
        return false;
    }

    return str_starts_with($premiereDate, $month . '-');
}

/**
 * @param array<int,array<string,mixed>> $reportRows
 * @return array<string,array<string,mixed>>
 */
function indexReportRowsByIsbn(array $reportRows): array
{
    $indexed = [];

    foreach ($reportRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $isbnNorm = normalizeIsbnForKey($row['isbn_norm'] ?? null);
        if ($isbnNorm === null) {
            continue;
        }

        $indexed[$isbnNorm] = $row;
    }

    return $indexed;
}

/**
 * Czyta kolejność ISBN z arkusza szablonu (A4:A...)
 *
 * @return array<int,string>
 */
function readTemplateIsbnOrder(Worksheet $sheet): array
{
    $result = [];
    $seen = [];

    $highestRow = $sheet->getHighestRow();

    for ($r = 4; $r <= $highestRow; $r++) {
        $isbn = normalizeIsbnForKey($sheet->getCell("A{$r}")->getValue());
        if ($isbn === null) {
            continue;
        }

        if (isset($seen[$isbn])) {
            continue;
        }

        $seen[$isbn] = true;
        $result[] = $isbn;
    }

    return $result;
}

/**
 * Buduje ostateczną kolejność:
 * 1) wiersze z szablonu (jeśli istnieją w reportRows)
 * 2) brakujące tytuły z reportRows, ale tylko premiery z danego miesiąca
 * 3) jeśli szablon pusty -> bierzemy wszystko z reportRows
 *
 * @param array<int,string> $templateOrder
 * @param array<string,array<string,mixed>> $rowsByIsbn
 * @return array<int,array<string,mixed>>
 */
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

            $premiereDate = isset($row['premiere_date']) ? (string)$row['premiere_date'] : null;
            if (isPremiereInMonth($premiereDate, $month)) {
                $final[] = $row;
                $used[$isbn] = true;
            }
        }

        return $final;
    }

    // bootstrap: jeśli szablon nie ma listy ISBN -> bierzemy wszystko
    $rows = array_values($rowsByIsbn);

    usort($rows, function (array $a, array $b): int {
        $ta = mb_strtolower((string)($a['title'] ?? ''), 'UTF-8');
        $tb = mb_strtolower((string)($b['title'] ?? ''), 'UTF-8');
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        return (string)($a['isbn_norm'] ?? '') <=> (string)($b['isbn_norm'] ?? '');
    });

    return $rows;
}

function adjustedNetCents97(array $row): int
{
    $netCents = 0;

    if (isset($row['revenue_net_cents'])) {
        $netCents = (int)$row['revenue_net_cents'];
    } elseif (isset($row['revenue_net'])) {
        $netCents = decimalStringToCents((string)$row['revenue_net']);
    }

    // hardcoded 0.97 zgodnie z wymaganiem
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
        $sheet->duplicateStyle(
            $sheet->getStyle("A{$templateStyleRow}:I{$templateStyleRow}"),
            "A{$r}:I{$r}"
        );

        // opcjonalnie wysokość wiersza
        $srcHeight = $sheet->getRowDimension($templateStyleRow)->getRowHeight();
        if ($srcHeight !== null && $srcHeight > 0) {
            $sheet->getRowDimension($r)->setRowHeight($srcHeight);
        }
    }
}

function clearDataArea(Worksheet $sheet, int $fromRow, int $toRow): void
{
    for ($r = $fromRow; $r <= $toRow; $r++) {
        foreach (range('A', 'I') as $col) {
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
        throw new RuntimeException("Brak paths.periods_dir w config.local.php");
    }
    if ($templatePath === '' || !is_file($templatePath)) {
        throw new RuntimeException("Brak szablonu XLSX (paths.monthly_report_template_xlsx): {$templatePath}");
    }

    $monthDir = rtrim($periodsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . 'woo';
    ensureDir($monthDir);

    $reportJsonPath = $monthDir . DIRECTORY_SEPARATOR . 'report_rows.zero_filled.json';
    if (!is_file($reportJsonPath)) {
        throw new RuntimeException("Brak pliku {$reportJsonPath}. Najpierw uruchom build_month_rows_from_woo.php");
    }

    fwrite(STDOUT, "Budowanie XLSX za {$month}\n");
    fwrite(STDOUT, "Szablon: {$templatePath}\n");
    fwrite(STDOUT, "Wejście JSON: {$reportJsonPath}\n\n");

    $reportPayload = readJsonFile($reportJsonPath);
    $reportRows = $reportPayload['records'] ?? null;

    if (!is_array($reportRows)) {
        throw new RuntimeException("Plik report_rows.zero_filled.json nie zawiera records[]");
    }

    $rowsByIsbn = indexReportRowsByIsbn($reportRows);

    // Wczytaj szablon
    $spreadsheet = IOFactory::load($templatePath);

    // Zostaw tylko pierwszy arkusz
    while ($spreadsheet->getSheetCount() > 1) {
        $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
    }

    $sheet = $spreadsheet->getSheet(0);
    $sheet->setTitle('autozliczanie (' . $month . ')');

    // Odetnij kolumny od J wzwyż
    $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    if ($highestColIndex > 9) {
        $sheet->removeColumn('J', $highestColIndex - 9);
    }

    // Przeczytaj kolejność z szablonu (A4:A...)
    $templateOrder = readTemplateIsbnOrder($sheet);

    // Zbuduj finalną listę wierszy (szablon + premiery miesiąca)
    $finalRows = buildFinalRows($templateOrder, $rowsByIsbn, $month);

    // Upewnij się, że mamy style dla dodatkowych wierszy
    $targetLastRow = max(4, 3 + count($finalRows));
    cloneDataRowStyleIfNeeded($sheet, $targetLastRow);

    // Nagłówki / notki (na wszelki wypadek)
    $sheet->setCellValue('B1', 'Tytuł');
    $sheet->setCellValue('E1', 'RAZEM');
    $sheet->setCellValue('H1', 'Histmag (szacunkowy)');
    $sheet->setCellValue('H2', 'kwoty są szacunkowe');

    // Wyczyść stare wartości/formuły w A:I od wiersza 4 w dół
    $clearToRow = max($sheet->getHighestRow(), $targetLastRow);
    clearDataArea($sheet, 4, $clearToRow);

    // Wpisz wartości statyczne (bez formuł)
    $r = 4;
    $rowsAddedFromPremiere = 0;
    $templateOrderSet = [];
    foreach ($templateOrder as $isbn) {
        $templateOrderSet[$isbn] = true;
    }

    foreach ($finalRows as $row) {
        $isbnNorm = normalizeIsbnForKey($row['isbn_norm'] ?? null);
        if ($isbnNorm === null) {
            continue;
        }

        $title = (string)($row['title'] ?? '');
        $units = (int)($row['units_sold'] ?? 0);

        $adjNetCents = adjustedNetCents97($row);
        $adjNet = $adjNetCents / 100;

        // Wykryj dopisane premiery (nieobecne w szablonie)
        if (!isset($templateOrderSet[$isbnNorm]) && isPremiereInMonth((string)($row['premiere_date'] ?? ''), $month)) {
            $rowsAddedFromPremiere++;
        }

        // A: ISBN bez myślników (jako string, żeby uniknąć problemów z floatami)
        $sheet->setCellValueExplicit("A{$r}", $isbnNorm, DataType::TYPE_STRING);

        // B: Tytuł
        $sheet->setCellValueExplicit("B{$r}", $title, DataType::TYPE_STRING);

        // C, D, G: puste
        $sheet->setCellValue("C{$r}", null);
        $sheet->setCellValue("D{$r}", null);
        $sheet->setCellValue("G{$r}", null);

        // Woo-only na dziś:
        // E=RAZEM(szt.) = H
        // F=RAZEM(kwota) = I
        // H=sztuki Histmag
        // I=kwota Histmag netto * 0.97
        $sheet->setCellValue("E{$r}", $units);
        $sheet->setCellValue("F{$r}", $adjNet);
        $sheet->setCellValue("H{$r}", $units);
        $sheet->setCellValue("I{$r}", $adjNet);

        $r++;
    }

    // Zapis pliku
    $xlsxPath = $monthDir . DIRECTORY_SEPARATOR . 'raport_sprzedazy_woo_' . $month . '.xlsx';

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($xlsxPath);

    // Manifest pomocniczy
    $manifestPath = $monthDir . DIRECTORY_SEPARATOR . 'xlsx_manifest.json';
    writeJsonFile($manifestPath, [
        'snapshot_type' => 'woo_month_xlsx_manifest',
        'generated_at' => gmdate('c'),
        'month' => $month,
        'template_xlsx' => $templatePath,
        'input_report_json' => $reportJsonPath,
        'output_xlsx' => $xlsxPath,
        'sheet_count' => 1,
        'columns_kept' => 'A:I',
        'formulas_in_output' => false,
        'hardcoded_net_multiplier' => 0.97,
        'stats' => [
            'report_rows_input' => count($reportRows),
            'rows_written' => count($finalRows),
            'template_rows_detected' => count($templateOrder),
            'rows_added_from_month_premieres' => $rowsAddedFromPremiere,
        ],
    ]);

    fwrite(STDOUT, "✅ Gotowe\n");
    fwrite(STDOUT, "XLSX: {$xlsxPath}\n");
    fwrite(STDOUT, "Manifest: {$manifestPath}\n");
    fwrite(STDOUT, "Wiersze zapisane: " . count($finalRows) . "\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Błąd: " . $e->getMessage() . "\n");
    exit(1);
}