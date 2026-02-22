<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function normalizeNextoText(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value, " \"'");
    $value = mb_strtolower($value, 'UTF-8');
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

    return trim((string)preg_replace('/\s+/u', ' ', $value));
}

function sheetCellString(Worksheet $sheet, int $row, int $col): string
{
    $cellRef = Coordinate::stringFromColumnIndex($col) . (string)$row;
    return trim((string)(scalarToTrimmedString($sheet->getCell($cellRef)->getCalculatedValue()) ?? ''));
}

/**
 * @param array<int,string> $headerCells
 * @return array<string,int>
 */
function mapNextoHeaderColumns(array $headerCells): array
{
    $mapped = [];

    foreach ($headerCells as $col => $rawName) {
        $name = normalizeNextoText($rawName);
        if ($name === '') {
            continue;
        }

        if (!isset($mapped['isbn']) && (
            str_contains($name, 'issn/isbn')
            || str_contains($name, 'isbn/issn')
            || $name === 'isbn'
            || $name === 'issn'
        )) {
            $mapped['isbn'] = $col;
            continue;
        }

        if (!isset($mapped['units']) && (
            $name === 'ilosc'
            || str_contains($name, 'ilosc')
        )) {
            $mapped['units'] = $col;
            continue;
        }

        if (!isset($mapped['net']) && (
            $name === 'netto'
            || str_contains($name, 'netto')
        )) {
            $mapped['net'] = $col;
            continue;
        }

        if (!isset($mapped['title']) && str_contains($name, 'tytul')) {
            $mapped['title'] = $col;
            continue;
        }

        if (!isset($mapped['purchase_type']) && str_contains($name, 'rodzaj zakupu')) {
            $mapped['purchase_type'] = $col;
            continue;
        }

        if (!isset($mapped['source']) && $name === 'zrodlo') {
            $mapped['source'] = $col;
            continue;
        }

        if (!isset($mapped['gross']) && str_contains($name, 'brutto')) {
            $mapped['gross'] = $col;
            continue;
        }

        if (!isset($mapped['vat']) && $name === 'vat') {
            $mapped['vat'] = $col;
            continue;
        }
    }

    return $mapped;
}

/**
 * @return array<int,array{header_row:int,columns:array<string,int>,header_cells:array<int,string>}>
 */
function detectNextoTableHeaders(Worksheet $sheet, int $maxRowsToScan = 200): array
{
    $highestRow = min($sheet->getHighestRow(), $maxRowsToScan);
    $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $tables = [];

    for ($row = 1; $row <= $highestRow; $row++) {
        $headerCells = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = sheetCellString($sheet, $row, $col);
            if ($value === '') {
                continue;
            }
            $headerCells[$col] = $value;
        }

        if ($headerCells === []) {
            continue;
        }

        $columns = mapNextoHeaderColumns($headerCells);
        $hasRequired = isset($columns['isbn'], $columns['units'], $columns['net']);
        if (!$hasRequired) {
            continue;
        }

        $optionalCount = 0;
        foreach (['title', 'purchase_type', 'source', 'gross', 'vat'] as $optionalKey) {
            if (isset($columns[$optionalKey])) {
                $optionalCount++;
            }
        }
        if ($optionalCount < 1) {
            continue;
        }

        $tables[] = [
            'header_row' => $row,
            'columns' => $columns,
            'header_cells' => $headerCells,
        ];
    }

    return $tables;
}

/**
 * @param array<int,string> $rowCellsNorm
 */
function isNextoSummaryRow(array $rowCellsNorm): bool
{
    foreach ($rowCellsNorm as $value) {
        if ($value === 'razem' || $value === 'razem:') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int,string> $rowCellsNorm
 */
function looksLikeNextoGroupRow(array $rowCellsNorm): bool
{
    $joined = trim(implode(' ', $rowCellsNorm));
    if ($joined === '') {
        return false;
    }

    if (str_starts_with($joined, 'kartoteka ')) {
        return true;
    }

    return str_starts_with($joined, 'rodzaj zakupu') || str_starts_with($joined, 'zrodlo');
}

function parseNextoUnits($value): ?int
{
    $v = scalarToTrimmedString($value);
    if ($v === null || $v === '') {
        return null;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);
    if (!is_numeric($v)) {
        return null;
    }

    return (int)round((float)$v, 0);
}

function parseNextoNetToCents($value): ?int
{
    $v = scalarToTrimmedString($value);
    if ($v === null || $v === '') {
        return null;
    }

    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);

    return decimalStringToCents($v);
}

/**
 * @return array{ok:bool,message:string,details:array<int,string>,data?:array<string,mixed>}
 */
function validateAndParseNextoXlsx(string $filePath, ?string $originalFilename = null): array
{
    if (!is_file($filePath)) {
        return [
            'ok' => false,
            'message' => 'Nie znaleziono pliku raportu Nexto.',
            'details' => ['Ścieżka: ' . $filePath],
        ];
    }

    $ext = strtolower(pathinfo((string)($originalFilename ?? $filePath), PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        return [
            'ok' => false,
            'message' => 'Raport Nexto musi mieć rozszerzenie .xlsx.',
            'details' => ['Otrzymano rozszerzenie: ' . ($ext !== '' ? $ext : '(brak)')],
        ];
    }

    try {
        $spreadsheet = IOFactory::load($filePath);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Nie udało się odczytać pliku XLSX Nexto.',
            'details' => [$e->getMessage()],
        ];
    }

    $sheet = $spreadsheet->getSheet(0);
    $highestRow = $sheet->getHighestRow();
    $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $nextoHints = [
        'raport ze sprzedazy agencyjnej w ramach serwisu nexto',
        'raport sprzedazy - nexto',
        'raport sprzedazy - prenumeraty cykliczne',
    ];

    $detectedHints = [];
    $headerScanRows = min($highestRow, 60);
    for ($row = 1; $row <= $headerScanRows; $row++) {
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $valueNorm = normalizeNextoText(sheetCellString($sheet, $row, $col));
            if ($valueNorm === '') {
                continue;
            }
            foreach ($nextoHints as $hint) {
                if (str_contains($valueNorm, $hint)) {
                    $detectedHints[$hint] = true;
                }
            }
        }
    }

    $tables = detectNextoTableHeaders($sheet, 200);
    if ($tables === []) {
        return [
            'ok' => false,
            'message' => 'Plik nie wygląda na raport Nexto (brak wiarygodnych nagłówków tabeli).',
            'details' => [
                'Wymagane kolumny logiczne: ISSN/ISBN, Ilość, Netto.',
                'Wykryte wskazówki nagłówkowe: ' . (count($detectedHints) > 0 ? implode('; ', array_keys($detectedHints)) : '(brak)'),
            ],
        ];
    }

    $byIsbn = [];
    $rowsTotalSeen = 0;
    $rowsSkippedEmpty = 0;
    $rowsSkippedGroup = 0;
    $rowsSkippedSummary = 0;
    $rowsSkippedWithoutIsbn = 0;
    $rowsParsedData = 0;
    $unitsTotal = 0;
    $marginNetTotalCents = 0;

    usort($tables, static fn(array $a, array $b): int => (int)$a['header_row'] <=> (int)$b['header_row']);

    foreach ($tables as $tableIndex => $tableDef) {
        $headerRow = (int)$tableDef['header_row'];
        $columns = is_array($tableDef['columns'] ?? null) ? $tableDef['columns'] : [];

        $nextHeaderRow = null;
        if (isset($tables[$tableIndex + 1])) {
            $nextHeaderRow = (int)$tables[$tableIndex + 1]['header_row'];
        }

        $blankStreak = 0;
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            if ($nextHeaderRow !== null && $row >= $nextHeaderRow) {
                break;
            }

            $rowsTotalSeen++;

            $rowRaw = [];
            $rowNorm = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $raw = sheetCellString($sheet, $row, $col);
                $rowRaw[$col] = $raw;
                $rowNorm[$col] = normalizeNextoText($raw);
            }

            $nonEmptyNorm = array_values(array_filter($rowNorm, static fn(string $v): bool => $v !== ''));
            if ($nonEmptyNorm === []) {
                $rowsSkippedEmpty++;
                $blankStreak++;
                if ($blankStreak >= 4) {
                    break;
                }
                continue;
            }
            $blankStreak = 0;

            if (isNextoSummaryRow($nonEmptyNorm)) {
                $rowsSkippedSummary++;
                break;
            }

            if (looksLikeNextoGroupRow($nonEmptyNorm)) {
                $rowsSkippedGroup++;
                continue;
            }

            $isbnRaw = trim((string)($rowRaw[$columns['isbn'] ?? -1] ?? ''));
            $units = parseNextoUnits($rowRaw[$columns['units'] ?? -1] ?? null) ?? 0;
            $netCents = parseNextoNetToCents($rowRaw[$columns['net'] ?? -1] ?? null) ?? 0;

            $isbnNorm = normalizeIsbnRaw($isbnRaw)['isbn_norm'];
            if ($isbnNorm === null) {
                $rowsSkippedWithoutIsbn++;
                continue;
            }

            if (!isset($byIsbn[$isbnNorm])) {
                $byIsbn[$isbnNorm] = [
                    'isbn_norm' => $isbnNorm,
                    'isbn_raw_example' => $isbnRaw,
                    'units_sold' => 0,
                    'margin_net_cents' => 0,
                ];
            }

            $byIsbn[$isbnNorm]['units_sold'] += $units;
            $byIsbn[$isbnNorm]['margin_net_cents'] += $netCents;

            $unitsTotal += $units;
            $marginNetTotalCents += $netCents;
            $rowsParsedData++;
        }
    }

    if ($rowsParsedData === 0 || $byIsbn === []) {
        return [
            'ok' => false,
            'message' => 'Raport Nexto nie zawiera poprawnych wierszy sprzedażowych z ISBN.',
            'details' => [
                'Wiersze przejrzane: ' . $rowsTotalSeen,
                'Wiersze bez ISBN: ' . $rowsSkippedWithoutIsbn,
                'Wykryte tabele: ' . count($tables),
            ],
        ];
    }

    ksort($byIsbn);

    return [
        'ok' => true,
        'message' => 'Raport Nexto poprawny.',
        'details' => [],
        'data' => [
            'source' => 'nexto',
            'sheet_name' => $sheet->getTitle(),
            'tables' => $tables,
            'stats' => [
                'rows_total_seen' => $rowsTotalSeen,
                'rows_skipped_empty' => $rowsSkippedEmpty,
                'rows_skipped_group' => $rowsSkippedGroup,
                'rows_skipped_summary' => $rowsSkippedSummary,
                'rows_skipped_without_isbn' => $rowsSkippedWithoutIsbn,
                'rows_parsed_data' => $rowsParsedData,
                'tables_detected' => count($tables),
                'records_aggregated' => count($byIsbn),
                'units_total' => $unitsTotal,
                'margin_net_total_cents' => $marginNetTotalCents,
            ],
            'records' => array_values($byIsbn),
            'header_hints_detected' => array_keys($detectedHints),
        ],
    ];
}
